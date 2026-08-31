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
        $context ? ' ' . ldap_format_context($context) : ''
    ));
}

/**
 * Render a log context as JSON.
 *
 * Two traps worth guarding against. FreeDSx hands the caught Throwable inside
 * the context, and json_encode() renders a Throwable as `{}` - losing exactly
 * the detail these logs exist to capture. And any non-UTF8 byte arriving from a
 * socket makes json_encode() return false, which would silently drop the whole
 * context.
 */
function ldap_format_context(array $context): string
{
    foreach ($context as $key => $value) {
        if ($value instanceof \Throwable) {
            $context[$key] = [
                'class'   => get_class($value),
                'message' => $value->getMessage(),
                'file'    => $value->getFile(),
                'line'    => $value->getLine(),
            ];
        } elseif (is_object($value) && !($value instanceof \JsonSerializable)) {
            $context[$key] = method_exists($value, '__toString')
                ? (string) $value
                : get_class($value);
        }
    }

    $json = json_encode($context, JSON_INVALID_UTF8_SUBSTITUTE | JSON_PARTIAL_OUTPUT_ON_ERROR);

    return $json === false ? '[context could not be serialised]' : $json;
}

$serverPid = getmypid();

// Report why this process is going away. Covers fatal errors, uncaught
// exceptions and a plain fall-through of the accept loop, which otherwise all
// look identical from the outside: an empty log and a container restart.
register_shutdown_function(static function () use ($serverPid): void {
    $error = error_get_last();

    if ($error !== null && in_array($error['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)) {
        ldap_log('critical', 'Process exiting after a fatal error.', $error);

        return;
    }

    // Every connection forks a child that exits moments later, so logging each
    // clean exit produces one line per connection and buries the one event this
    // hook exists for: the server process itself going away. Fatal errors above
    // are still reported from any process.
    if (getmypid() === $serverPid) {
        ldap_log('info', 'Process exiting.');
    }
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
        $level = (string) $level;

        // FreeDSx emits several INFO lines per connection. At the bind volume
        // this server sees that is dozens of lines per client operation, so
        // they stay behind debug_mode. Warning and above always get through -
        // including the fork failures this logger exists to surface.
        $verbose = (bool) ($GLOBALS['config']['debug_mode'] ?? false);

        if (!$verbose && in_array($level, ['debug', 'info', 'notice'], true)) {
            return;
        }

        ldap_log($level, (string) $message, $context);
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
 * FreeDSx tears the whole server down when it cannot fork a child: the failure
 * is reported through logAndThrow(), and the exception unwinds out of the accept
 * loop and ends the process. The container then exits and every client gets a
 * connection reset until Docker brings it back. Rebuilding the listener here
 * turns a minutes-long outage into a one second gap.
 *
 * Only *exceptional* exits are restarted. A run() that returns normally is an
 * orderly shutdown and must be honoured - see the comment below.
 */
$parentPid = getmypid();

/** Consecutive immediate failures tolerated before handing over to Docker. */
$maxConsecutiveRestarts = 10;

/** Hard ceiling for the life of the process, so no failure pattern loops forever. */
$maxTotalRestarts = 50;

$restarts      = 0;
$totalRestarts = 0;
$backoff       = 1;

while (true) {
    $startedAt = time();

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

        // run() returning WITHOUT an exception means an orderly shutdown: a
        // SIGTERM from `docker stop`, whose FreeDSx handler stops the children,
        // closes the socket and lets the accept loop break.
        //
        // Restarting here would be a bug: every stop would become a restart,
        // and the following SIGTERM would be a no-op, because the handler still
        // installed belongs to the previous runner and its isShuttingDown flag
        // is already true. The container would never honour TERM and would
        // always be SIGKILLed after the grace period, cutting live connections.
        // So exit, exactly as this server did before the supervisor existed.
        ldap_log('info', 'Accept loop finished; shutting down.');

        exit(0);
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

    // A listener that served for a while before failing is an isolated fault,
    // not a crash loop, so the consecutive counter and the backoff reset. The
    // absolute ceiling below still applies, so no pattern of failures - however
    // slow - can keep this process spinning indefinitely.
    if ((time() - $startedAt) >= 60) {
        $restarts = 0;
        $backoff  = 1;
    }

    $restarts++;
    $totalRestarts++;

    if ($restarts > $maxConsecutiveRestarts || $totalRestarts > $maxTotalRestarts) {
        ldap_log('critical', 'Giving up on restarting; exiting so the container manager can take over.', [
            'consecutive' => $restarts,
            'total'       => $totalRestarts,
        ]);

        exit(1);
    }

    ldap_log('warning', 'Restarting listener.', [
        'attempt'         => $restarts,
        'backoff_seconds' => $backoff,
    ]);

    sleep($backoff);

    $backoff = min($backoff * 2, 30);
}
