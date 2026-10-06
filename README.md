# Migration Forecast for Magento 2

Know what `setup:upgrade` will do to your database **before** you run it.

`bin/magento setup:upgrade:forecast` lists every pending schema change, tells
you which MySQL algorithm each one will use, whether it blocks writes, and
roughly how long it will take on the tables you actually have.

Example output (illustrative figures):

```
$ bin/magento setup:upgrade:forecast
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

Pending data patches (cost unknown): 1
  - Acme\Widget\Setup\Patch\Data\SeedWidgets

Migration forecast: 2 blocking, 1 instant, 1 online; 1 patch(es)/script(s) of unknown cost;
predicted ~81s, ~78s write-blocking (confidence: low)
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

Requires Magento Open Source / Adobe Commerce / Mage-OS 2.4.x on PHP 8.1+. No
other dependencies, no configuration, no licence key.

## Use

Run it on the new code, before `setup:upgrade`:

```bash
bin/magento setup:upgrade:forecast
```

| Option | What it does |
|---|---|
| `--format=json` | Machine-readable output for CI and deploy tooling. |
| `--max-seconds=N` | Exit `1` when the predicted duration exceeds `N` seconds. |
| `--max-blocking-seconds=N` | Exit `1` when the write-blocking part exceeds `N` seconds. |
| `--inplace-rate=N` | Rows per second your host manages for in-place DDL. Default `100000`. |
| `--copy-rate=N` | Rows per second your host manages for table-copy DDL. Default `50000`. |

### Gate a deploy

```bash
# Refuse to deploy in business hours if writes would be blocked for more than 10 seconds.
bin/magento setup:upgrade:forecast --max-blocking-seconds=10 || exit 1
bin/magento setup:upgrade --keep-generated
```

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
   schema patches and legacy `Install`/`Upgrade` scripts are arbitrary PHP; they
   are named and they drop the confidence to `low`.

### Confidence

| Level | Meaning |
|---|---|
| `high` | Only DDL is pending and every affected table's size is known. |
| `medium` | Only DDL is pending, but some table sizes could not be read. |
| `low` | Patches, legacy scripts or not-yet-enabled modules are pending — the DDL figure is a floor, not a total. |

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

## Tests

The unit tests ship with the repository, not the Composer dist. From a clone
placed in `app/code/Byte8/MigrationForecast`:

```bash
vendor/bin/phpunit --bootstrap vendor/autoload.php --no-configuration \
    app/code/Byte8/MigrationForecast/Test/Unit
```

## Origin

Extracted from the migration-intelligence engine in
[Orbit](https://byte8.io/orbit), Byte8's zero-downtime deployment service for
Magento, where the same forecast sizes the traffic-hold window for each deploy.

## Licence

MIT — see [LICENSE.txt](LICENSE.txt).
