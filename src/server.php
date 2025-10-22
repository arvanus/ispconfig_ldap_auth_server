<?php
require_once __DIR__ . '/vendor/autoload.php';
require_once __DIR__ . '/lib/LdapRequestHandler.php';

use FreeDSx\Ldap\LdapServer;

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
    'debug_mode' => false  // Debug logging disabled by default
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

// Step 3: Override with local config.php if exists (highest priority)
$config_file = __DIR__ . '/config/config.php';
if (file_exists($config_file)) {
    require $config_file;
}

// Make config globally available
$GLOBALS['config'] = $config;

$server = new LdapServer([
    'port' => $config['ldap_port'],
    'request_handler' => LdapRequestHandler::class
]);
$server->run();
