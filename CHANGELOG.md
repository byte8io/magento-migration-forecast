# Changelog

## [0.3.2](https://github.com/byte8io/magento-migration-forecast/compare/v0.3.1...v0.3.2) (2026-10-10)


### Bug Fixes

* clearer forecast summary for patch-only releases ([b39a1b2](https://github.com/byte8io/magento-migration-forecast/commit/b39a1b2319ee42f243be54b3e2f12b7255d00302))

## [0.3.1](https://github.com/byte8io/magento-migration-forecast/compare/v0.3.0...v0.3.1) (2026-10-10)


### Documentation

* command reference for both commands, JSON fields and exit codes ([009e71a](https://github.com/byte8io/magento-migration-forecast/commit/009e71a383a99220694133bf1554edec138b0536))

## [0.3.0](https://github.com/byte8io/magento-migration-forecast/compare/v0.2.0...v0.3.0) (2026-10-09)


### Features

* add setup:db:guarded-upgrade ([7b4cb75](https://github.com/byte8io/magento-migration-forecast/commit/7b4cb753af9184aa5df669f2a647012e9dbb8647))


### Bug Fixes

* name the guarded command setup:guarded-upgrade ([f9330c2](https://github.com/byte8io/magento-migration-forecast/commit/f9330c2d026be324815e3c21ea7acd97cddad895))
* never prompt when no terminal is attached ([fd4930b](https://github.com/byte8io/magento-migration-forecast/commit/fd4930b0eda6a44aa9353a4a88704afc0740db42))

## [0.2.0](https://github.com/byte8io/magento-migration-forecast/compare/v0.1.1...v0.2.0) (2026-10-09)


### ⚠ BREAKING CHANGES

* setup:upgrade:forecast is now setup:db:forecast.

### Features

* report pending patches beside the DDL forecast, not inside it ([a122a6b](https://github.com/byte8io/magento-migration-forecast/commit/a122a6bcbf3ef6faf282987aba9016a994e4b600))


### Bug Fixes

* rename command to setup:db:forecast ([ea3ad63](https://github.com/byte8io/magento-migration-forecast/commit/ea3ad63a310f3b9b78b2a5182cdd0fe86c8129ee))

## [0.1.1](https://github.com/byte8io/magento-migration-forecast/compare/v0.1.0...v0.1.1) (2026-10-08)


### Documentation

* compatibility matrix, Mage-OS notes and community files ([18a48a0](https://github.com/byte8io/magento-migration-forecast/commit/18a48a0423128a388a675712140c81a7f27fa931))
* name Mage-OS release lines correctly in CONTRIBUTING ([5be7c75](https://github.com/byte8io/magento-migration-forecast/commit/5be7c75bd3f1f030f603d9b9cc6d9dc8dd94ea69))

## 0.1.0 (2026-10-07)


### Features

* setup:upgrade:forecast command ([75e648f](https://github.com/byte8io/magento-migration-forecast/commit/75e648fe4d61d8d1f8a0363446b1de7e6bef5fa1))
