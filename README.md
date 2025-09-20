# Laravel Sharding

Laravel Sharding is a toolkit for distributing data across multiple databases while keeping a familiar Eloquent workflow. The package powers production applications and provides pluggable strategies so each table can select the most appropriate sharding approach.

## Requirements

- PHP ^8.2
- Laravel 12.x or any framework using Illuminate 12 components
- MySQL-compatible databases for shard connections
- Redis (optional) when using the Redis-backed strategy

## Installation

Require the package via Composer:

```bash
composer require allnetru/laravel-sharding
```

The service provider is auto-discovered. Publish the configuration and optional migrations with:

```bash
php artisan vendor:publish --tag=laravel-sharding-config
php artisan vendor:publish --tag=laravel-sharding-migrations
```

Run the migrations to create metadata tables used by the built-in strategies:

```bash
php artisan migrate
```

## Configuration

1. Define shard connections through the `DB_SHARDS` environment variable. Each entry follows the format `name:host:port:database` and multiple shards are separated by semicolons.
2. When preparing to migrate or remove shards, list them in `DB_SHARD_MIGRATIONS`. New writes are skipped for shards in this list until you finish rebalancing.
3. Review `config/sharding.php` to map tables to strategies, configure shard groups, and choose ID generators. Every shard-aware model should use the provided `Shardable` trait.

A full walkthrough is available in [docs/en/sharding.md](docs/en/sharding.md).

## Usage

### Creating sharded tables

Create tables with an unsigned big integer primary key and the `is_replica` flag to track replicated rows:

```php
Schema::create('items', function (Blueprint $table) {
    $table->unsignedBigInteger('id')->primary();
    $table->boolean('is_replica')->default(false);
    $table->timestamps();
});
```

Then register the table inside `config/sharding.php`, select a strategy (`hash`, `redis`, `range`, `db_range`, or `db_hash_range`), and list the shard connections the table can use.

### ID generation

The default `snowflake` generator creates sortable 64-bit identifiers. You can switch the global default or override per table to use a database-backed `sequence` generator or any other configured strategy.

### Grouping related tables

Group tables so records that belong together end up on the same shard:

```php
'groups' => [
    'user_data' => ['users', 'organizations', 'billing', 'transactions'],
],
```

When models belong to a group they reuse the shard selected for the group's primary table (for example, `users`).

### Working with data

Models using the `Shardable` trait behave like standard Eloquent models:

```php
$user = User::find(15);

$partners = Organization::where('status', OrganizationStatus::partner)
    ->paginate(50);
```

Insertions also resolve the target shard automatically. If you omit the primary key the configured ID generator assigns one before the record is saved.

### Console tooling

Use the bundled Artisan commands to inspect and maintain shards:

- `php artisan shards:distribute {model}` – backfill existing tables into shards in chunks once strategies are configured.
- `php artisan shards:rebalance {table}` – migrate rows between shards with optional `--from`, `--to`, `--start`, and `--end` filters.
- `php artisan shards:migrate` – run shard-specific migrations across every configured connection.

## Testing

Clone the repository and install dependencies before running the test suite:

```bash
composer install
composer test
```

## Contributing

Please review the [CONTRIBUTING.md](CONTRIBUTING.md) guide for details about our workflow, coding standards, and security policy.

## Security

If you discover a security vulnerability, please follow the disclosure process described in [CONTRIBUTING.md](CONTRIBUTING.md#security-vulnerabilities).

## License

Laravel Sharding is open-sourced software licensed under the [MIT license](LICENSE.md).
