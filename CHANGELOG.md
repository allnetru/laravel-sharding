# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/)
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

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

- Dispatch shard fan-out queries concurrently when running inside a Swoole coroutine.

## [0.1.0]

### Added

- Extracted the sharding toolkit from Boost into a reusable Laravel package.
- Implemented shard strategies, metadata migrations and console tooling.
- Added shard-aware Eloquent integrations and ID generators.
