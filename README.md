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

## Authentication cache

LDAP clients rarely bind once per operation. A single `git fetch` against a
GitLab instance backed by this server was measured issuing **8 binds in 5.7
seconds**, six of them for the very same service bind DN. Every bind otherwise
opens a fresh SOAP connection to ISPConfig (connect, login, work, logout) inside
its own forked process.

The same operation also issues LDAP *searches* between those binds, each one
another SOAP round trip. Measured after the binds were cached, a single search
accounted for 1.8 seconds of a 2.85 second fetch - so both are cached.

A short-lived cache absorbs the repeats. It is enabled by default:

| Variable | Default | Meaning |
| --- | --- | --- |
| `auth_cache_enabled` | `true` | Set to `false` to always query ISPConfig. |
| `auth_cache_ttl` | `60` | Seconds a **successful** authentication is trusted. |
| `auth_cache_negative_ttl` | `5` | Seconds a **failed** authentication is remembered. Kept short so a corrected password works right away. `0` disables caching failures. |
| `auth_cache_dir` | `/dev/shm/ispldap-authcache` | Where entries live. Must be a tmpfs path writable by the process. |

**Worth knowing before raising the TTL:** a changed password or a mailbox you
just disabled keeps working for up to `auth_cache_ttl` seconds. The 60 second
default is a deliberate trade-off - long enough to collapse the binds of a
single client operation, short enough that a revocation takes effect quickly.

How it stays safe: entries live in a tmpfs directory (nothing reaches the disk),
the directory is `0700` and entries `0600`, and keys are HMAC-SHA256 of the
credentials using a 32-byte secret regenerated on **every server start**.
Credentials themselves are never written, and stored values hold only an expiry
and a boolean. A failure to set the cache up disables it silently, falling back
to querying ISPConfig every time.

## Logging

Lifecycle events - startup, restarts, crashes and why the process is exiting -
always go to STDERR, so `docker logs` shows them. Setting `debug_mode=true`
additionally emits per-bind detail and the FreeDSx connection-level INFO lines,
which are verbose enough to be worth keeping off by default.


