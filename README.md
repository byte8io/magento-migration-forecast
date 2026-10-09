# Migration Forecast for Magento 2 and Mage-OS

[![CI](https://github.com/byte8io/magento-migration-forecast/actions/workflows/ci.yml/badge.svg)](https://github.com/byte8io/magento-migration-forecast/actions/workflows/ci.yml)
[![Packagist](https://img.shields.io/packagist/v/byte8/module-migration-forecast)](https://packagist.org/packages/byte8/module-migration-forecast)
[![Licence: MIT](https://img.shields.io/badge/licence-MIT-blue.svg)](LICENSE.txt)

Know what `setup:upgrade` will do to your database **before** you run it.

`bin/magento setup:db:forecast` lists every pending schema change, tells
you which MySQL algorithm each one will use, whether it blocks writes, and
roughly how long it will take on the tables you actually have.

Example output (illustrative figures):

```
$ bin/magento setup:db:forecast
+------------------+----------------+-------------------------------+-----------+----------+-----------+------+
| Table            | Change         | Name                          | Algorithm | Impact   | Rows      | Est. |
+------------------+----------------+-------------------------------+-----------+----------+-----------+------+
| sales_order_item | modify column  | sku                           | COPY      | blocking | 2,100,000 | ~42s |
| sales_order_item | add column     | fixture_note                  | INSTANT   | instant  | 2,100,000 | -    |
| sales_order_item | add index      | SALES_ORDER_ITEM_FIXTURE_NOTE | INPLACE   | online   | 2,100,000 | ~21s |
| sales_order_grid | add column     | fixture_tag                   | INPLACE   | blocking | 1,400,000 | ~14s |
+------------------+----------------+-------------------------------+-----------+----------+-----------+------+
  sales_order_item sku: Changed: length.
  sales_order_grid fixture_tag: INSTANT is unavailable on tables with a FULLTEXT index. ...

Pending data patches (not costed): 1
  - Acme\Widget\Setup\Patch\Data\SeedWidgets

DDL forecast: 2 blocking, 1 instant, 1 online; ~81s, ~78s write-blocking (confidence: high).
Plus 1 patch(es)/script(s) not costed.
```

It is read-only. It never alters the database, never writes a file, and never
blocks a deploy unless you ask it to.

## Why

`setup:db:status` tells you *that* an upgrade is needed. `--dry-run` tells you
*which SQL* will run. Neither tells you whether that SQL finishes in a blink or
holds a lock on `sales_order_item` for four minutes — which is the only thing
you need to know to decide if a release can go out at 2 PM.

## Install

```bash
composer require byte8/module-migration-forecast
bin/magento module:enable Byte8_MigrationForecast
bin/magento setup:upgrade
```

The only dependency is the framework itself. There is nothing to configure, no
licence key, no telemetry and no network call of any kind.

## Compatibility

| Platform | Versions | How it is verified |
|---|---|---|
| **Mage-OS** | 2.x, 3.x | Clean install + end-to-end forecast in CI on every commit |
| **Magento Open Source** | 2.4.9 | Clean install + end-to-end forecast in CI on every commit |
| Magento Open Source | 2.4.4 – 2.4.8 | Same framework line (`magento/framework ^103.0`); not run in CI |
| Adobe Commerce | 2.4.4 – 2.4.9 | Same framework line; not run in CI |
| **PHP** | 8.1 – 8.5 | Unit tests in CI on every version |
| **MySQL** | 8.4 | CI database. The classification follows MySQL 8's online-DDL rules, so 8.0 behaves the same except where noted under Limits |
| MariaDB | 10.6 | Runs (developed against it); the matrix is MySQL's, so treat the class as an approximation |

On Mage-OS the requirement on `magento/framework` is satisfied by
`mage-os/framework`, so `composer require` works unchanged — no patches, no
alternative package.

## Use

Run it on the new code, before `setup:upgrade`:

```bash
bin/magento setup:db:forecast
```

| Option | What it does |
|---|---|
| `--format=json` | Machine-readable output for CI and deploy tooling. |
| `--max-seconds=N` | Exit `1` when the predicted duration exceeds `N` seconds. |
| `--max-blocking-seconds=N` | Exit `1` when the write-blocking part exceeds `N` seconds. |
| `--fail-on-uncosted` | Exit `1` when any patch or setup script is pending, because its cost is unknown. |
| `--inplace-rate=N` | Rows per second your host manages for in-place DDL. Default `100000`. |
| `--copy-rate=N` | Rows per second your host manages for table-copy DDL. Default `50000`. |

### Gate a deploy

```bash
# Refuse to deploy in business hours if writes would be blocked for more than 10 seconds.
bin/magento setup:db:forecast --max-blocking-seconds=10 || exit 1
bin/magento setup:upgrade --keep-generated
```

## Forecast and upgrade in one step

`setup:guarded-upgrade` shows the forecast and then runs the real
`setup:upgrade` — but only if the forecast is acceptable. It is a separate
command: `setup:upgrade` itself is never replaced, wrapped or altered.

```bash
bin/magento setup:guarded-upgrade --keep-generated
```

**At a terminal** it prints the forecast and asks `Run setup:upgrade now? [y/N]`.
Pass `--yes` to skip the question.

**Unattended** (CI, deploy scripts, anything without a terminal) it never
prompts. The limits you pass decide:

```bash
bin/magento setup:guarded-upgrade --keep-generated --max-blocking-seconds=10
```

| Exit code | Meaning |
|---|---|
| `0` | The forecast was within limits and `setup:upgrade` succeeded. |
| `2` | `setup:upgrade` was **not run**: a limit was exceeded, the forecast could not be built, or you answered no. Nothing was changed. |
| anything else | `setup:upgrade` ran and failed; this is its own exit code. |

It accepts the same limit and rate options as `setup:db:forecast`. Without any
limit, an unattended run always proceeds — set at least one for it to guard
anything. Of `setup:upgrade`'s own options only `--keep-generated` is passed
through; for the others, run the two commands separately.

### GitHub Actions

```yaml
- name: Upgrade the database, unless it would block writes for too long
  run: |
    status=0
    bin/magento setup:guarded-upgrade --keep-generated --max-blocking-seconds=10 || status=$?
    if [ "$status" -eq 2 ]; then
      echo "::error::Migration forecast is over the limit. Schedule this release for a maintenance window."
    fi
    exit "$status"
```

Run it where `setup:upgrade` would run — on the server, or over SSH from the
workflow — because the forecast needs the live database.

## How it works

1. **The diff is Magento's own.** The command asks the declarative-schema
   engine for the difference between the merged `db_schema.xml` files and the
   live database — the exact operation list `setup:upgrade` executes,
   `db_schema_whitelist.json` rules included.
2. **Each operation is classified** against MySQL 8 / InnoDB's online-DDL
   matrix:

   | Change | Algorithm | Rebuilds table | Writes during it |
   |---|---|---|---|
   | New / drop table | metadata | no | allowed |
   | Add column | INSTANT | no | allowed |
   | Add auto-increment column | INPLACE | yes | **blocked** |
   | Drop column | INPLACE | yes | allowed |
   | Modify column: comment or default | metadata | no | allowed |
   | Modify column: extend `VARCHAR` within the same length-byte class | INPLACE | no | allowed |
   | Modify column: nullability | INPLACE | yes | allowed |
   | Modify column: anything else (type, shrink, unsigned…) | COPY | yes | **blocked** |
   | Add index / unique / foreign key | INPLACE | no | allowed |
   | Add FULLTEXT index | INPLACE | no | **blocked** |
   | Add primary key | INPLACE | yes | allowed |
   | Drop index / unique / foreign key | metadata | no | allowed |
   | Drop primary key | COPY | yes | **blocked** |
   | Table engine / charset / resource | COPY | yes | **blocked** |
   | Any rebuild of a table that has a FULLTEXT index | — | yes | **blocked** |

3. **Row-scaled operations are costed** from `information_schema` row counts
   and a throughput per class. Magento folds all changes to one table into a
   single `ALTER`, so a table is rebuilt once however many changes force it.
4. **What cannot be costed is listed, not guessed.** Pending data patches,
   schema patches and legacy `Install`/`Upgrade` scripts are arbitrary PHP. They
   are named beside the DDL forecast and never folded into it: the seconds you
   see are DDL plus bootstrap, nothing else.

### Confidence

Confidence describes the DDL figure only. A pending patch does not lower it —
a release with a trivial data patch still gets a trustworthy DDL forecast, and
the patch is reported on its own line for you to judge.

| Level | Meaning |
|---|---|
| `high` | Every affected table's size was read. |
| `medium` | Some table sizes could not be read; class defaults were used. |
| `low` | A module not yet in `app/etc/config.php` ships a `db_schema.xml` the diff cannot see, so DDL is missing from the figure. |

Use `--fail-on-uncosted` if you would rather stop a pipeline whenever a patch
is pending than review it by eye.

## Limits

- **It is an estimate.** Row counts for InnoDB are approximate and the default
  throughputs are conservative guesses for mid-range hosting. Time one real
  `ALTER` on your host and pass `--inplace-rate` / `--copy-rate`.
- **When in doubt it assumes the slower class.** A dropped column is costed as a
  rebuild although MySQL 8.0.29+ can often do it instantly; a foreign key is
  costed as an index build although one may already exist.
- **Metadata-lock waits are not modelled.** Even an instant `ALTER` queues
  behind a long-running transaction on the same table.
- **Modules missing from `app/etc/config.php`** are enabled by `setup:upgrade`
  but are invisible to the diff until then. The command warns about each one.
- **MariaDB** follows the same matrix closely enough for the classification to
  hold, but it was written against MySQL 8's documentation.
- **Split databases:** tables on a non-default connection are sized through
  their own connection, but this path is not covered by CI.

## Open source and Mage-OS

This module is open source under the MIT licence and works on Mage-OS. It is
developed in the open at
[github.com/byte8io/magento-migration-forecast](https://github.com/byte8io/magento-migration-forecast)
and distributed through Packagist, which also lists it in the
[Mage-OS Extension Directory](https://directory.mage-os.org/).

Its maintainer is a member of the [Mage-OS Association](https://mage-os.org/).
The module is an independent Byte8 project; it is not an official Mage-OS
package.

## Contributing

Issues and pull requests are welcome — above all, real measurements. If the
forecast was wrong on your store, that is the most useful thing you can tell us.

- [CONTRIBUTING.md](CONTRIBUTING.md) — setup, checks, how to change the matrix
- [SECURITY.md](SECURITY.md) — reporting a vulnerability
- [CODE_OF_CONDUCT.md](CODE_OF_CONDUCT.md)
- [CHANGELOG.md](CHANGELOG.md)

The unit tests are framework-free and run from a plain clone with `phpunit`.

## Credits

Built and maintained by [Byte8](https://byte8.io). Extracted from the
migration-intelligence engine in [Orbit](https://byte8.io/orbit), Byte8's
zero-downtime deployment service for Magento, where the same forecast sizes the
traffic-hold window for each deploy.

## Licence

MIT — see [LICENSE.txt](LICENSE.txt).
