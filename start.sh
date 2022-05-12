#!/bin/bash
if [ ! -f /app/config/config.php ] ; then
  echo "creating config file"

  cat <<EOF > /app/config/config.php
<?php
\$config['ldap_port'] = $ldap_port;
\$config['remote_soap_user'] = '$remote_soap_user';
\$config['remote_soap_pass'] = '$remote_soap_pass';
\$config['soap_url'] = '$soap_url';
\$config['soap_location'] = '$soap_location';
\$config['soap_validate_cert'] = $soap_validate_cert;
\$config['accept_domain_only'] = $accept_domain_only;

#TODO:
#\$config['accept_domain_only'] = []; #empty = any domain existing into the ISPConfig server
#\$config['accept_domain_only'] = ['domain1.com','domain2.com.br'];
?>

EOF

fi

php /app/server.php