# Laravel Sharding

This package extracts the sharding toolkit from the Boost application and makes it available as a reusable Laravel component.

## Features

- flexible sharding manager with table grouping support;
- weighted hash distribution via `HashStrategy`;
- range-based strategies (`RangeStrategy`, `DbRangeStrategy`);
- hybrid `DbHashRangeStrategy` that manages slots in the database and supports rebalancing;
- Redis-backed shard assignment through `RedisStrategy`;
- ID generators (Snowflake and table sequence strategies);
- shard-aware Eloquent builder that handles pagination, limits and replicas;
- Eloquent `Shardable` trait and relationship implementations that resolve shards automatically;
- console tooling for distributing, migrating and rebalancing shards;
- configuration helpers and publishable migrations.

## Installation

```bash
composer require allnetru/sharding
```

The package auto-discovers the `ShardingServiceProvider`. Publish the configuration and migrations with:

```bash
php artisan vendor:publish --tag=laravel-sharding-config
php artisan vendor:publish --tag=laravel-sharding-migrations
```

## Quick start

1. Configure shard connections via `DB_SHARDS` and `DB_SHARD_MIGRATIONS`. The DSN format is `name:host:port:database` and multiple entries are separated with `;`.
2. Review `config/sharding.php` to define strategies and table mappings.
3. Add the `Shardable` trait to every model that should participate in sharding.
4. Run migrations to create the metadata tables: `php artisan migrate`.

## Testing

```bash
composer install
composer test
```

## License

MIT
