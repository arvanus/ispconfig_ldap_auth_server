<?php
$config['ldap_port'] = 389;
$config['remote_soap_user'] = 'ispremoteuser';
$config['remote_soap_pass'] = 'ispremotepass';
$config['soap_url'] = 'https://localhost:8080/remote/';
$config['soap_location'] = $config['soap_url'].'index.php';

// SECURITY: Set to true to validate SSL certificates (recommended)
// Only set to false for development with self-signed certificates
$config['soap_validate_cert'] = true;

// Domain whitelist (empty array = allow all domains)
$config['accept_domain_only'] = [];
// Example: $config['accept_domain_only'] = ['domain1.com','domain2.com.br'];

// Debug mode: Enable detailed logging to stdout (useful for troubleshooting)
$config['debug_mode'] = false;
?>
