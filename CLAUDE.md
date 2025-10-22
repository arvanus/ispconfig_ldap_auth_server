# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Project Overview

ISPConfigLDAPAuthServer is a PHP-based LDAP authentication service that bridges ISPConfig's remote SOAP API with LDAP clients. It allows mailbox users from ISPConfig to authenticate via LDAP protocol. Users must have IMAP or POP3 enabled in ISPConfig to be available to LDAP clients.

**Key constraint**: Anonymous LDAP lookups are not configured. A bind user must be created as a regular mail user (e.g., `myldapcontroluser@mydomain.com`).

## Architecture

### Request Flow

1. **LDAP Server Entry Point** (`src/server.php`): Initializes FreeDSx LDAP Server with configured port and request handler
2. **Request Handler** (`src/lib/LdapRequestHandler.php`): Implements FreeDSx `GenericRequestHandler` with two core operations:
   - `bind()`: Validates username/password against ISPConfig via SOAP
   - `search()`: Retrieves user data and maps to LDAP entry format
3. **SOAP Layer** (`src/lib/ispconfig_soap.php`): Abstract base class `ISPConfig_SOAP` managing ISPConfig remote API connections
4. **User Authentication** (`src/lib/user_ispconfig.php`): `OC_User_ISPCONFIG` class handling authentication logic, UID mapping, and domain filtering
5. **Domain User Model** (`src/lib/ispdomainuser.php`): `ISPDomainUser` class representing authenticated users with mailbox, domain, and display name
6. **Utilities** (`src/lib/util.php`): Helper functions for email parsing, DN conversion, and logging

### Critical Implementation Details

**Password Verification**: Uses PHP `crypt()` function to verify passwords against ISPConfig's crypted password hashes (`src/lib/ispdomainuser.php:100-103`).

**User Filtering**: Users with both IMAP and POP3 disabled are excluded from LDAP results (`src/lib/LdapRequestHandler.php:113-114`).

**Domain Filtering**: The `accept_domain_only` config array restricts authentication to specific domains. Empty array = all domains allowed (`src/lib/LdapRequestHandler.php:47-50`, `97-100`).

**LDAP Entry Mapping** (`src/lib/LdapRequestHandler.php:116-125`):
- `cn`: Full name from ISPConfig
- `sn`: Surname (last name)
- `uid`: Username portion of email
- `sAMAccountName`: Full DN format (CN=user,DC=domain,DC=com)
- `givenName`: First name
- `mail`: Full email address

## Development Commands

### Local Development (requires PHP 8+ with soap, ldap, pcntl extensions)

```bash
# Install dependencies
cd src && composer install

# Create config from sample
cp src/config/config.sample.php src/config/config.php
# Edit config.php with your ISPConfig credentials

# Run server directly
php src/server.php
```

### Docker Development

```bash
# Build image
docker build -t ispconfig_ldap_auth_server .

# Run container (production)
docker run -p 389:389 \
  -e remote_soap_user=youruser \
  -e remote_soap_pass=yourpass \
  -e soap_url=https://yourserver:8080/remote/ \
  -e soap_location=https://yourserver:8080/remote/index.php \
  -e soap_validate_cert=false \
  -e accept_domain_only="['domain1.com','domain2.com']" \
  ispconfig_ldap_auth_server

# Run with debug mode enabled (troubleshooting)
docker run -p 389:389 \
  -e remote_soap_user=youruser \
  -e remote_soap_pass=yourpass \
  -e soap_url=https://yourserver:8080/remote/ \
  -e soap_location=https://yourserver:8080/remote/index.php \
  -e soap_validate_cert=false \
  -e accept_domain_only="['domain1.com','domain2.com']" \
  -e debug_mode=true \
  ispconfig_ldap_auth_server
```

### Testing LDAP Bind

```bash
# Test bind with ldapsearch
ldapsearch -x -H ldap://localhost:389 \
  -D "user@domain.com" -w "password" \
  -b "" "(mail=user@domain.com)"
```

## Configuration

### Hybrid Configuration System

The application supports two configuration methods with the following priority:

1. **File-based** (`src/config/config.php`): Loaded first if file exists (for manual installations)
2. **Environment variables**: Override file settings or used exclusively if no config file exists (for Docker)

This hybrid approach provides flexibility for both development and production deployments while maintaining security.

### Configuration Parameters

- `ldap_port`: LDAP listening port (default: 389)
- `remote_soap_user`: ISPConfig remote API username
- `remote_soap_pass`: ISPConfig remote API password
- `soap_url`: ISPConfig remote API URL
- `soap_location`: ISPConfig remote API index.php location
- `soap_validate_cert`: Boolean for SSL certificate validation (default: `true` for security)
- `accept_domain_only`: PHP array of allowed domains (e.g., `['domain1.com']`) or `[]` for all domains
- `debug_mode`: Boolean to enable detailed logging to stdout (default: `false`)

### Security Best Practices

**Certificate Validation**: `soap_validate_cert` defaults to `true`. Only set to `false` in development environments with self-signed certificates. For production with self-signed certs, configure proper CA certificates instead.

**Domain Whitelisting**: Use `accept_domain_only` to restrict authentication to specific domains when needed.

**Logging**: Sensitive information (passwords, full usernames) is not logged. Only domain names and operation results are logged via syslog.

**Debug Mode**: Set `debug_mode=true` for troubleshooting authentication issues. Outputs detailed information to stdout including:
- Username format detection (email vs DN)
- Domain validation steps
- SOAP connection details
- Authentication results

**Warning**: Debug mode may expose usernames in logs. Only enable for troubleshooting and disable in production.

## Dependencies

**PHP Extensions Required**:
- `soap`: ISPConfig API communication
- `ldap`: LDAP DN parsing functions
- `pcntl`: Process control for LDAP server

**Composer Packages**:
- `freedsx/ldap` (^0.8.0): Pure PHP LDAP server implementation

### FreeDSx/LDAP Library

This project uses **FreeDSx/LDAP** (available locally at `D:\GitHub\LDAP\`), a pure PHP LDAP library that implements RFC 4511 without requiring the PHP LDAP extension.

**Key Components Used**:
- **LdapServer**: Main server class that handles LDAP protocol communication
- **GenericRequestHandler**: Base class extended by `LdapRequestHandler` to implement custom bind/search logic
- **Entry/Entries**: Classes for representing LDAP entries with DN and attributes
- **Filters**: AndFilter, EqualityFilter for parsing LDAP search filters
- **OperationException**: Thrown when LDAP operations fail

**Implementation Pattern**:
```php
class LdapRequestHandler extends GenericRequestHandler {
    public function bind(string $username, string $password): bool { }
    public function search(RequestContext $context, SearchRequest $search): Entries { }
}
```

**Entry Creation**:
```php
// Correct: Use proper DN (not email)
$dn = Util::getDNfromMail($email); // "CN=user,DC=domain,DC=com"
$entry = Entry::create($dn, [
    'cn' => 'Full Name',
    'sn' => 'Surname',
    'uid' => 'username',
    'mail' => 'user@domain.com'
]);
```

**Important Notes**:
- FreeDSx uses ASN.1 BER encoding for LDAP protocol
- All classes are autoloaded via Composer - never `require` vendor internals
- Server requires `ext-pcntl` for proper functionality
- See FreeDSx CLAUDE.md for architecture details

## Code Origins

The codebase is adapted from the [nextcloud-user-ispconfig](https://github.com/SpicyWeb-de/nextcloud-user-ispconfig) project by Michael Fürmann. Classes retain original copyright and AGPL license headers. The Nextcloud-specific database and user management code has been commented out in `src/lib/base.php`.
