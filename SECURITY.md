# Security Policy

## Supported versions

Security fixes are released for the latest minor version.

## Reporting a vulnerability

Please do not open a public issue for a security problem.

- Preferred: use **Report a vulnerability** under the repository's
  [Security tab](https://github.com/byte8io/magento-migration-forecast/security/advisories/new)
  (GitHub private vulnerability reporting).
- Or email **helo@byte8.io** with "Security: migration-forecast" in the subject.

Include the module version, the Magento / Mage-OS version, and steps to
reproduce. You will get an acknowledgement within three working days, and a fix
or a mitigation plan as soon as the report is confirmed.

## Scope

The module is a read-only CLI command: it runs no DDL, writes no files and makes
no network calls. It reads the database schema and `information_schema` through
Magento's own connection. Reports about anything that breaks those guarantees
are in scope.
