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

The module adds two CLI commands and nothing else: no admin UI, no web routes,
no cron, no configuration, and no network calls.

- `setup:db:forecast` is read-only. It runs no DDL and writes no files; it
  reads the database schema and `information_schema` through Magento's own
  connection.
- `setup:guarded-upgrade` builds the same forecast and then starts Magento's
  own `setup:upgrade` as a child process, using the PHP binary and the
  `bin/magento` of the current installation. It adds no behaviour to the
  upgrade itself.

Reports about anything that breaks those guarantees are in scope — for example
a way to make either command run something other than `setup:upgrade`, or to
make the forecast write to the database.
