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

// Authentication cache.
//
// Clients such as GitLab issue several binds per operation - a single git fetch
// was measured producing 8 of them in 5.7 seconds, six for the same service
// bind DN. Each bind otherwise opens a new SOAP connection to ISPConfig, in its
// own forked process. Caching the result for a few seconds removes almost all
// of that work.
//
// Entries live in a tmpfs directory so nothing reaches the disk, are keyed by
// HMAC-SHA256 with a secret regenerated on every server start, and hold only an
// expiry and a boolean. Credentials are never stored.
$config['auth_cache_enabled'] = true;

// How long a successful authentication is trusted, in seconds. Keep it short:
// this is also the window in which a password change or a disabled mailbox
// keeps working.
$config['auth_cache_ttl'] = 60;

// How long a failed authentication is remembered, in seconds. Kept much shorter
// so a user who just fixed their password is not locked out by the cache. Set
// to 0 to never cache failures.
$config['auth_cache_negative_ttl'] = 5;

// Where cache entries live. Must be a tmpfs/ramdisk path, and writable by the
// process. Defaults to /dev/shm, which is a tmpfs in the official PHP images.
$config['auth_cache_dir'] = '/dev/shm/ispldap-authcache';

// NOTE: no closing "?>" on purpose. Anything after it - including the trailing
// newline of the file - is written straight to stdout when this file is
// require()d, which shows up as a mystery blank line in the container logs.
