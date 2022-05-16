# ISPConfigLDAPAuthServer

A service that uses ISPConfig remote API to serve as a LDAP auth server, uses ISPConfig mailbox user and password data, the mail user must have IMAP or POP3 enabled, if none the user won't be availabe to LDAP clients

Note: I hadn't configured anonymous LDAP lookup, so you'll need to create a bind user as a regular mail user, like `myldapcontroluser@mydomain.com`.

Example of a `docker-compose.yml`

```
version: '3.1'
services:

  ldap:
    image: arvanus/ispconfig_ldap_auth_server:main
    restart: unless-stopped
    ports:
      - 389:389
    environment:
      - TZ=America/Sao_Paulo
      - remote_soap_user=roundcuberemoteuser
      - remote_soap_pass=roundcuberemotepassword
      - soap_url=https://localhost:8080/remote/
      - soap_location=https://localhost:8080/remote/index.php
      - soap_validate_cert=false
      - ldap_port=389
      #If undefined, any mailbox domain will be accepted
      - accept_domain_only=['domain1.com','domain2.com','domain3.com']
```


