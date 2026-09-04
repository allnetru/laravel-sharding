# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/)
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## v0.3.1 - 2026-09-04

### What's Changed

* chore: update CHANGELOG for v0.3.0 by @allnetru in https://github.com/allnetru/laravel-sharding/pull/43
* fix: shard connections could not reach PostgreSQL by @allnetru in https://github.com/allnetru/laravel-sharding/pull/44

**Full Changelog**: https://github.com/allnetru/laravel-sharding/compare/v0.3.0...v0.3.1

## v0.3.0 - 2026-09-04

### What's Changed

* chore: update CHANGELOG for v0.2.1 by @allnetru in https://github.com/allnetru/laravel-sharding/pull/41
* Bump the composer group across 1 directory with 9 updates by @dependabot[bot] in https://github.com/allnetru/laravel-sharding/pull/40
* fix: Snowflake identifiers could collide by @allnetru in https://github.com/allnetru/laravel-sharding/pull/42

**Full Changelog**: https://github.com/allnetru/laravel-sharding/compare/v0.2.1...v0.3.0

## Unreleased

### Fixed

* **Snowflake identifiers could collide.** The generator shifted the timestamp
  by 16 bits and filled the low bits with `random_int()`, carrying no worker
  identity at all. Two processes could therefore mint the same identifier, and
  inside a single millisecond ids were kept apart only by 65 536 random values:
  by the birthday bound the collision probability reaches one percent at about
  thirty ids per millisecond and one half at about three hundred, which one
  import batch reaches easily. On a single shard this surfaced as a primary key
  violation, across shards as a silent duplicate that later broke `find()` and
  rebalancing. The generator now follows the original Snowflake layout,
  `41 bits timestamp | 10 bits worker | 12 bits sequence`, with a monotonic
  per-millisecond sequence and protection against a backwards clock jump.

### Added

* `sharding.id_generator.worker_id`, from `SHARDING_WORKER_ID`, identifies the
  minting process, range 0 to 1023. Falls back to a hostname and pid derived
  value when unset.
* `sharding.id_generator.epoch_ms`, from `SHARDING_EPOCH_MS`, defaults to
  2020-01-01.
* `SnowflakeStrategy::reset()` for tests.
* Test coverage for the generator: uniqueness under a tight loop, sortability,
  disjointness across workers, worker id encoding, range validation and epoch
  validation. The generator previously had none.

### Upgrade notes

Safe on a populated database. The new layout shifts by 22 bits against a 2020
epoch and produces 60-bit values, while the previous one shifted by 16 bits
against the Unix epoch and produced 57-bit values, so every new identifier is
strictly larger than every old one. Existing rows keep their ids, ordering is
preserved and no collision with historical values is possible.

Capacity is 4 096 000 identifiers per second per worker, and 41 bits of
timestamp last until 2089.

Set `SHARDING_WORKER_ID` per process before relying on the generator in a
deployment with more than one process minting ids.

## Unreleased

### Fixed

* **Shard connections could not reach PostgreSQL.** The base configuration
  applied MySQL defaults to every driver, so a `pgsql` shard was built with
  `charset` set to `utf8mb4` and PostgreSQL refused the connection outright
  with `invalid value for parameter "client_encoding"`. The configuration is
  now driver aware: PostgreSQL shards carry `charset`, `search_path` and
  `sslmode`, and the MySQL only keys `collation`, `strict`, `engine` and
  `options` are not emitted for them at all.
* **The default shard port was always 3306.** A definition with the port
  omitted, `shard-1:host::database`, silently pointed a PostgreSQL shard at the
  MySQL port. The default now follows the driver: 5432 for `pgsql`, 3306
  otherwise.

### Added

* `DB_SEARCH_PATH` and `DB_SSLMODE` are honoured for PostgreSQL shards.
* Tests covering driver awareness of the generated connections, including that
  MySQL remains the default and keeps its keys.

## Unreleased

### Fixed

* **Colocation by a non-primary shard key could not insert at all.** When a
  model declared `$shardKey` pointing at another column, which is the shape the
  README documents for grouping related tables, the `creating` hook filled only
  the shard key. A non-incrementing primary key stayed null and the insert
  failed, so the documented shape did not work. The primary key is now
  generated through the configured ID generator when it is not
  auto-incrementing and has no value. Auto-incrementing keys are still left to
  the database, and an explicitly assigned key is never overwritten.

### Changed

* README spells out that colocation needs three things together, not two: the
  `groups` entry, the table's `group` key and `$shardKey` on the model. Missing
  the third silently scatters children across shards. It also notes that such a
  child must declare `public $incrementing = false;`, because two shards would
  otherwise hand out the same sequence values.

## Unreleased

### Fixed

* **`belongsTo` on a shardable model lost the related type.** The override
  declared `BelongsTo<Model, $this>`, so a model narrowing its own docblock to
  `BelongsTo<Tenant, $this>` failed static analysis. Consumers had to either
  widen their docblocks and lose the type or lower the analysis level. The
  related model is now templated and the return type is
  `ShardBelongsTo<TRelatedModel, $this>`.

## v0.2.1 - 2026-08-16

### What's Changed

* chore: update CHANGELOG for v0.2.0 by @allnetru in https://github.com/allnetru/laravel-sharding/pull/35
* Bump league/commonmark from 2.8.1 to 2.8.2 in the composer group across 1 directory by @dependabot[bot] in https://github.com/allnetru/laravel-sharding/pull/36
* fix: Laravel 13 compatibility for ShardBuilder::updateOrCreate by @allnetru in https://github.com/allnetru/laravel-sharding/pull/39
* Bump symfony/routing from 7.3.2 to 7.4.15 in the composer group across 1 directory by @dependabot[bot] in https://github.com/allnetru/laravel-sharding/pull/37
* Bump actions/checkout from 6 to 7 by @dependabot[bot] in https://github.com/allnetru/laravel-sharding/pull/38

**Full Changelog**: https://github.com/allnetru/laravel-sharding/compare/v0.2.0...v0.2.1

## v0.2.0 - 2026-03-18

### What's Changed

* chore: update CHANGELOG for v0.1.6 by @allnetru in https://github.com/allnetru/laravel-sharding/pull/33
* Laravel 13 by @allnetru in https://github.com/allnetru/laravel-sharding/pull/34

**Full Changelog**: https://github.com/allnetru/laravel-sharding/compare/v0.1.6...v0.2.0

## v0.1.6 - 2026-03-18

### What's Changed

* chore: update CHANGELOG for v0.1.5 by @allnetru in https://github.com/allnetru/laravel-sharding/pull/24
* Bump actions/checkout from 5 to 6 by @dependabot[bot] in https://github.com/allnetru/laravel-sharding/pull/25
* Bump symfony/http-foundation from 7.3.3 to 7.4.0 in the composer group across 1 directory by @dependabot[bot] in https://github.com/allnetru/laravel-sharding/pull/26
* Bump peter-evans/create-pull-request from 7 to 8 by @dependabot[bot] in https://github.com/allnetru/laravel-sharding/pull/27
* Bump phpunit/phpunit from 11.5.39 to 11.5.50 in the composer group across 1 directory by @dependabot[bot] in https://github.com/allnetru/laravel-sharding/pull/28
* Claude skills by @allnetru in https://github.com/allnetru/laravel-sharding/pull/31
* Bump the composer group across 1 directory with 2 updates by @dependabot[bot] in https://github.com/allnetru/laravel-sharding/pull/29
* Bump ramsey/composer-install from 3 to 4 by @dependabot[bot] in https://github.com/allnetru/laravel-sharding/pull/30
* Bump league/commonmark from 2.7.1 to 2.8.1 in the composer group across 1 directory by @dependabot[bot] in https://github.com/allnetru/laravel-sharding/pull/32

**Full Changelog**: https://github.com/allnetru/laravel-sharding/compare/v0.1.5...v0.1.6

## v0.1.5 - 2025-09-22

### What's Changed

* chore: update CHANGELOG for v0.1.4 by @allnetru in https://github.com/allnetru/laravel-sharding/pull/21
* Bump peter-evans/create-pull-request from 6 to 7 by @dependabot[bot] in https://github.com/allnetru/laravel-sharding/pull/22
* Handle unique constraint violations across drivers by @allnetru in https://github.com/allnetru/laravel-sharding/pull/23

### New Contributors

* @dependabot[bot] made their first contribution in https://github.com/allnetru/laravel-sharding/pull/22

**Full Changelog**: https://github.com/allnetru/laravel-sharding/compare/v0.1.4...v0.1.5

## v0.1.4 - 2025-09-22

### What's Changed

* Gitflow changelog pr by PAT secret by @allnetru in https://github.com/allnetru/laravel-sharding/pull/20

**Full Changelog**: https://github.com/allnetru/laravel-sharding/compare/v0.1.3...v0.1.4

## v0.1.1 - 2025-09-21

### What's Changed

* Add workflow to update changelog on release by @allnetru in https://github.com/allnetru/laravel-sharding/pull/8
* Add Packagist and CI badges to README by @allnetru in https://github.com/allnetru/laravel-sharding/pull/9
* Update shard examples in docs by @allnetru in https://github.com/allnetru/laravel-sharding/pull/10
* Configure phpunit to use in-memory SQLite by @allnetru in https://github.com/allnetru/laravel-sharding/pull/11
* docs: update README strategy configuration example by @allnetru in https://github.com/allnetru/laravel-sharding/pull/12
* Update PHPUnit configuration and composer scripts by @allnetru in https://github.com/allnetru/laravel-sharding/pull/13
* Simplify shard env usage by @allnetru in https://github.com/allnetru/laravel-sharding/pull/15
* Improve sharding coverage and modernize tests by @allnetru in https://github.com/allnetru/laravel-sharding/pull/14

**Full Changelog**: https://github.com/allnetru/laravel-sharding/compare/v0.1.0...v0.1.1

## [Unreleased]

### Added

- Pending changes.

## [0.1.0]

### Added

- Extracted the sharding toolkit from Boost into a reusable Laravel package.
- Implemented shard strategies, metadata migrations and console tooling.
- Added shard-aware Eloquent integrations and ID generators.
