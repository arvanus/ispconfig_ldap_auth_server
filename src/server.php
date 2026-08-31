<?php
// Suppress PHP 8.1+ deprecation warnings from FreeDSx/LDAP library
error_reporting(E_ALL & ~E_DEPRECATED);

require_once __DIR__ . '/vendor/autoload.php';
require_once __DIR__ . '/lib/AuthCache.php';
require_once __DIR__ . '/lib/LdapRequestHandler.php';

use FreeDSx\Ldap\LdapServer;
use ISPLDAP\lib\AuthCache;

/**
 * Structured line on STDERR. Always on: these are lifecycle events, not debug
 * chatter, and without them a crash of this server is completely silent.
 */
function ldap_log(string $level, string $message, array $context = []): void
{
    fwrite(STDERR, sprintf(
        "[%s] [pid %d] [%s] %s%s\n",
        date('c'),
        getmypid(),
        strtoupper($level),
        $message,
        $context ? ' ' . json_encode($context) : ''
    ));
}

// Report why this process is going away. Covers fatal errors, uncaught
// exceptions and a plain fall-through of the accept loop, which otherwise all
// look identical from the outside: an empty log and a container restart.
register_shutdown_function(static function (): void {
    $error = error_get_last();

    if ($error !== null && in_array($error['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)) {
        ldap_log('critical', 'Process exiting after a fatal error.', $error);

        return;
    }

    ldap_log('info', 'Process exiting.');
});

set_exception_handler(static function (\Throwable $e): void {
    ldap_log('critical', 'Uncaught ' . get_class($e) . ': ' . $e->getMessage(), [
        'file'  => $e->getFile(),
        'line'  => $e->getLine(),
        'trace' => $e->getTraceAsString(),
    ]);

    exit(1);
});

// SIGPIPE is not among the signals FreeDSx installs handlers for, and its
// default disposition is to kill the process without a word. A client vanishing
// mid-write must not take the server down with it; the write reports EPIPE
// instead, which the normal error paths already handle.
if (function_exists('pcntl_async_signals')) {
    pcntl_async_signals(true);
    pcntl_signal(SIGPIPE, static function (): void {
        ldap_log('warning', 'SIGPIPE received and ignored (client went away mid-write).');
    });
}

// PSR-3 logger handed to FreeDSx. Without it every logInfo()/logAndThrow() call
// inside the server runner - including "Unable to fork process." and the
// shutdown notices - is silently discarded.
$logger = new class extends \Psr\Log\AbstractLogger {
    public function log($level, $message, array $context = []): void
    {
        ldap_log((string) $level, (string) $message, $context);
    }
};

// Priority: Local file > Environment variables > Default values

// Step 1: Start with defaults
$config = [
    'ldap_port' => 389,
    'remote_soap_user' => 'ispremoteuser',
    'remote_soap_pass' => 'ispremotepass',
    'soap_url' => 'https://localhost:8080/remote/',
    'soap_location' => 'https://localhost:8080/remote/index.php',
    'soap_validate_cert' => true,  // Secure default
    'accept_domain_only' => [],
    'debug_mode' => false,  // Debug logging disabled by default
    'auth_cache_enabled' => true,
    'auth_cache_ttl' => 60,
    'auth_cache_negative_ttl' => 5,
    'auth_cache_dir' => '/dev/shm/ispldap-authcache'
];

// Step 2: Override with environment variables if set (for Docker)
if (getenv('ldap_port') !== false) {
    $config['ldap_port'] = (int)getenv('ldap_port');
}
if (getenv('remote_soap_user') !== false) {
    $config['remote_soap_user'] = getenv('remote_soap_user');
}
if (getenv('remote_soap_pass') !== false) {
    $config['remote_soap_pass'] = getenv('remote_soap_pass');
}
if (getenv('soap_url') !== false) {
    $config['soap_url'] = getenv('soap_url');
}
if (getenv('soap_location') !== false) {
    $config['soap_location'] = getenv('soap_location');
}
if (getenv('soap_validate_cert') !== false) {
    $config['soap_validate_cert'] = filter_var(getenv('soap_validate_cert'), FILTER_VALIDATE_BOOLEAN);
}
if (getenv('accept_domain_only') !== false && getenv('accept_domain_only') !== '') {
    $domains_str = getenv('accept_domain_only');
    if ($domains_str !== '[]') {
        $config['accept_domain_only'] = json_decode(str_replace("'", '"', $domains_str), true) ?: [];
    }
}
if (getenv('debug_mode') !== false) {
    $config['debug_mode'] = filter_var(getenv('debug_mode'), FILTER_VALIDATE_BOOLEAN);
}
if (getenv('auth_cache_enabled') !== false) {
    $config['auth_cache_enabled'] = filter_var(getenv('auth_cache_enabled'), FILTER_VALIDATE_BOOLEAN);
}
if (getenv('auth_cache_ttl') !== false) {
    $config['auth_cache_ttl'] = (int)getenv('auth_cache_ttl');
}
if (getenv('auth_cache_negative_ttl') !== false) {
    $config['auth_cache_negative_ttl'] = (int)getenv('auth_cache_negative_ttl');
}
if (getenv('auth_cache_dir') !== false && getenv('auth_cache_dir') !== '') {
    $config['auth_cache_dir'] = getenv('auth_cache_dir');
}

// Step 3: Override with local config.php if exists (highest priority)
$config_file = __DIR__ . '/config/config.php';
if (file_exists($config_file)) {
    require $config_file;
}

// Make config globally available
$GLOBALS['config'] = $config;

// The cache directory and its secret are prepared here, in the parent, so the
// forked children only ever read them.
$cache = AuthCache::instance($config);
$cacheReady = $cache->bootstrap();

ldap_log('info', 'ISPConfig LDAP auth server starting.', [
    'port'          => $config['ldap_port'],
    'soap_location' => $config['soap_location'],
    'validate_cert' => (bool) $config['soap_validate_cert'],
    'domains'       => $config['accept_domain_only'] ?: 'any',
    'debug_mode'    => (bool) $config['debug_mode'],
    'auth_cache'    => $cacheReady
        ? ['dir' => $cache->getDirectory(), 'ttl' => $cache->getTtl(), 'negative_ttl' => $cache->getNegativeTtl()]
        : 'disabled',
    'php'           => PHP_VERSION,
    'pid'           => getmypid(),
]);

/**
 * Supervisor loop.
 *
 * FreeDSx tears the whole server down when it cannot fork a child, and any
 * exception escaping the accept loop ends the process. Previously that meant
 * the container exited and every client got a connection reset until Docker
 * brought it back. Rebuilding the listener in-process turns a minutes-long
 * outage into a one second gap.
 */
$parentPid    = getmypid();
$restarts     = 0;
$maxRestarts  = 100;
$firstRestart = null;

while (true) {
    try {
        $server = new LdapServer([
            'port' => $config['ldap_port'],
            'request_handler' => LdapRequestHandler::class,
            'logger' => $logger
        ]);

        ldap_log('info', 'Listening for LDAP connections.', ['port' => $config['ldap_port']]);

        $server->run();

        // A child never reaches this point: FreeDSx exits inside the child once
        // the connection is handled. If we are here and we are not the parent,
        // leave immediately rather than turning a worker into a second server.
        if (getmypid() !== $parentPid) {
            exit(0);
        }

        ldap_log('warning', 'Accept loop ended on its own; restarting listener.');
    } catch (\Throwable $e) {
        if (getmypid() !== $parentPid) {
            ldap_log('error', 'Child process failed: ' . $e->getMessage(), [
                'file' => $e->getFile(),
                'line' => $e->getLine(),
            ]);

            exit(1);
        }

        ldap_log('critical', 'Server loop crashed: ' . get_class($e) . ': ' . $e->getMessage(), [
            'file'  => $e->getFile(),
            'line'  => $e->getLine(),
            'trace' => $e->getTraceAsString(),
        ]);
    }

    $now          = time();
    $firstRestart = $firstRestart ?? $now;
    $restarts++;

    // Give up only when restarts are both numerous and sustained, so the
    // container manager can step in on a genuinely broken state (a port that
    // stays bound, for instance) instead of us spinning forever.
    if ($restarts >= $maxRestarts && ($now - $firstRestart) < 300) {
        ldap_log('critical', 'Too many restarts in a short window; exiting so the supervisor can take over.', [
            'restarts' => $restarts,
            'seconds'  => $now - $firstRestart,
        ]);

        exit(1);
    }

    if (($now - $firstRestart) >= 300) {
        $restarts     = 1;
        $firstRestart = $now;
    }

    sleep(1);
}
