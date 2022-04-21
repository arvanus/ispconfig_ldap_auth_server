<?php
require_once __DIR__ . '/vendor/autoload.php';
require_once __DIR__ . '/config/config.php';
require_once __DIR__ . '/vendor/freedsx/ldap/src/FreeDSx/Ldap/LdapServer.php';
require_once __DIR__ . '/lib/LdapRequestHandler.php';

use FreeDSx\Ldap\LdapServer;

$server = new LdapServer([
    'port' => $config['ldap_port'],
    'request_handler' => LdapRequestHandler::class
]);
$server->run();
