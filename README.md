# Laravel Sharding

[![Packagist Version](https://img.shields.io/packagist/v/allnetru/laravel-sharding.svg)](https://packagist.org/packages/allnetru/laravel-sharding)
[![Tests](https://github.com/allnetru/laravel-sharding/actions/workflows/run-tests.yml/badge.svg?branch=main)](https://github.com/allnetru/laravel-sharding/actions/workflows/run-tests.yml)

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

A minimal example stitches these pieces together:

```dotenv
# .env
DB_SHARDS="shard-1:10.0.0.10:3306:app_shard_1;shard-2:10.0.0.11:3306:app_shard_2;shard-archive:10.0.0.12:3306:app_archive"
DB_SHARD_MIGRATIONS="shard-legacy;shard-archive"
```

```php
// config/sharding.php
return [
    'default' => 'hash',

    'id_generator' => [
        'default' => 'snowflake',
        'strategies' => [
            'snowflake' => Allnetru\\Sharding\\IdGenerators\\SnowflakeStrategy::class,
            'sequence' => Allnetru\\Sharding\\IdGenerators\\TableSequenceStrategy::class,
        ],
        'sequence_table' => 'shard_sequences',
        // 'meta_connection' => 'mysql',
    ],

    'connections' => [
        'shard-1' => ['weight' => 2],
        'shard-2' => ['weight' => 1],
        // 'shard-archive' => ['weight' => 1, 'replica' => true],
    ],

    'replica_count' => 1,

    'tables' => [
        // 'users' => [
        //     'strategy' => 'redis',
        //     'redis_connection' => 'shards',
        //     'redis_prefix' => 'user_shard:',
        //     'group' => 'user_data',
        // ],

        'users' => [
            'strategy' => 'db_hash_range',
            'slot_size' => 250_000,
            'connections' => [
                'shard-1' => ['weight' => 2],
                'shard-2' => ['weight' => 1],
            ],
            'meta_connection' => 'mysql',
            'group' => 'user_data',
        ],

        'profiles' => [
            // inherits the shard selected for the `users` table
            'group' => 'user_data',
            // 'id_generator' => 'sequence',
        ],

        'orders' => [
            'strategy' => 'db_range',
            'connections' => [
                'shard-1' => ['weight' => 2],
                'shard-2' => ['weight' => 1],
            ],
            'range_size' => 50_000,
            'meta_connection' => 'mysql',
            // 'range_table' => 'order_ranges',
        ],

        // 'payments' => [
        //     'strategy' => 'range',
        //     'ranges' => [
        //         ['start' => 1, 'end' => 1_000_000, 'connection' => 'shard-1'],
        //         ['start' => 1_000_001, 'end' => null, 'connection' => 'shard-2'],
        //     ],
        // ],
    ],

    'groups' => [
        'user_data' => ['users', 'profiles', 'orders'],
        // 'billing' => ['payments', 'refunds'],
    ],
];
```

Update `config/database.php` to merge the generated shard connections with your base definitions:

```php
// config/database.php (excerpt)

use Allnetru\Sharding\Support\Config\Shards;

return [
    'default' => env('DB_CONNECTION', 'mysql'),

    'connections' => array_merge([
        'mysql' => [
            'driver' => 'mysql',
            'url' => env('DB_URL'),
            'host' => env('DB_HOST', '127.0.0.1'),
            'port' => env('DB_PORT', '3306'),
            'database' => env('DB_DATABASE', 'forge'),
            'username' => env('DB_USERNAME', 'forge'),
            'password' => env('DB_PASSWORD', ''),
            // ... keep the rest of your base connection definition
        ],

        // other non-sharded connections...
    ], Shards::databaseConnections()),

    // ...
];
```

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
