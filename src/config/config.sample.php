<?php
$config['ldap_port'] = 389;
$config['remote_soap_user'] = 'ispremoteuser';
$config['remote_soap_pass'] = 'ispremotepass';
$config['soap_url'] = 'https://localhost:8080/remote/';
$config['soap_location'] = $config['soap_url'].'index.php';
$config['soap_validate_cert'] = false;

#TODO:
#$config['accept_domain_only'] = []; #empty = any domain existing into the ISPConfig server
#$config['accept_domain_only'] = ['domain1.com','domain2.com.br'];
?>
