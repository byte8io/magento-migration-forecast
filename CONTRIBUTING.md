# Contributing

Bug reports, corrections to the classification matrix and pull requests are
welcome. The most valuable contribution is a measurement: if the forecast was
wrong on your store, open an issue with the forecast output, the real duration,
your MySQL/MariaDB version and the table's row count.

## Development

Clone the repository into `app/code/Byte8/MigrationForecast` of any Magento
Open Source 2.4.x or Mage-OS 2.x / 3.x install, then:

```bash
bin/magento module:enable Byte8_MigrationForecast
bin/magento setup:db:forecast
```

In developer mode, clear `generated/code/Byte8/MigrationForecast` after changing
a constructor: Magento keeps the old generated class and every `bin/magento`
call fails until it is removed.

The module has two commands. `setup:db:forecast` is read-only and safe to run
anywhere. `setup:guarded-upgrade` runs the real `setup:upgrade` when it
proceeds, so try it only on a database you are willing to upgrade — or with
`--max-seconds=0`, which always refuses.

## Checks

Every pull request runs these in GitHub Actions; run them locally first.

```bash
# Unit tests — framework-free, no Magento install needed
phpunit

# From the Magento root: coding standard and static analysis
vendor/bin/phpcs --standard=Magento2 --severity=10 --extensions=php app/code/Byte8/MigrationForecast
vendor/bin/phpstan analyse -c app/code/Byte8/MigrationForecast/phpstan.neon.dist --autoload-file vendor/autoload.php
```

CI also installs the module into clean Mage-OS and Magento projects and checks,
end to end, that a deliberately introduced schema drift shows up in the
forecast, that `setup:guarded-upgrade` refuses over a limit and upgrades within
it, and that the core shortcuts (`s:up`, `s:d:s`) still resolve.

## Naming commands

A new command must not start with `setup:up`. Magento resolves shortcuts by
prefix, so such a name makes the everyday `bin/magento s:up` ambiguous. The CI
shortcut check exists because this happened once.

## Changing the classification

`Model/Classifier.php` holds the online-DDL matrix. A change there needs:

1. a link to the MySQL or MariaDB documentation that supports it, in the pull
   request description;
2. a case in `Test/Unit/Model/ClassifierTest.php`;
3. the matrix in `README.md` updated to match.

When the server's behaviour depends on something the module cannot know, the
slower class wins. A forecast that is too cautious is acceptable; one that
promises a safe deploy and is wrong is not.

## Commits

- Use [Conventional Commits](https://www.conventionalcommits.org/) (`feat:`,
  `fix:`, `docs:` …). Releases and the changelog are generated from them — see
  [RELEASING.md](RELEASING.md).
- Sign off every commit (`git commit -s`). The sign-off certifies the
  [Developer Certificate of Origin](https://developercertificate.org/): that
  you wrote the change or have the right to submit it under the MIT licence.

## Conduct

This project follows the [Code of Conduct](CODE_OF_CONDUCT.md).
