# Sharding

This application provides a pluggable sharding system that allows each table to choose its own strategy.

## Configuration

Shard connections are defined via the `DB_SHARDS` environment variable. Each shard is written as `name:host:port:database` and multiple shards are separated by semicolons:

```
DB_SHARDS="shard-1:db1.example.com:3306:app_db1;shard-2:db2.example.com:3306:app_db2"
```

Each environment can set its own `DB_SHARDS` value to match the number of servers. Other options like `DB_USERNAME` and `DB_PASSWORD` are shared across all shards.

When preparing to migrate or decommission shards, list them in `DB_SHARD_MIGRATIONS`. Each entry is separated by semicolons:

```
DB_SHARD_MIGRATIONS="shard-1;shard-2"
```

Shards listed here are skipped for new writes until data is moved.

### Database configuration

Merge the generated shard connections into `config/database.php` so Laravel can resolve them just like first-party connections:

```php
use Allnetru\Sharding\Support\Config\Shards;

return [
    'default' => env('DB_CONNECTION', 'mysql'),

    'connections' => array_merge([
        'mysql' => [
            'driver' => 'mysql',
            'host' => env('DB_HOST', '127.0.0.1'),
            'database' => env('DB_DATABASE', 'forge'),
            // ... keep your existing base connections here
        ],
        // other non-sharded connections...
    ], Shards::databaseConnections(env('DB_SHARDS', ''))),

    // ...
];
```

> **Tip**
> Passing the `DB_SHARDS` string makes the helper work during the configuration
> bootstrap phase. In other contexts you can call the helper without arguments
> and it will read the `DB_SHARDS` environment variable directly.

### Creating a sharded table

1. Create a migration with an unsigned BIGINT primary key and an `is_replica` boolean column:

   ```php
   Schema::create('items', function (Blueprint $table) {
       $table->unsignedBigInteger('id')->primary();
       $table->boolean('is_replica')->default(false);
       $table->timestamps();
   });
   ```

2. Add a model that uses the `Shardable` trait.
3. Configure the table in `config/sharding.php` with a strategy and list of connections.

### Strategies

Available strategies include:

- `hash` – hashes the shard key and chooses from the weighted connection list.
- `redis` – stores shard assignments in Redis and falls back to the hash strategy when missing.
- `range` – maps fixed ID ranges to connections.
- `db_range` – stores and expands ranges in a database table.
- `db_hash_range` – combines hashing with database ranges for automatic expansion.

Only strategies that support rebalancing can be used with the `shards:rebalance` command.

### ID generation

Unique identifiers are generated using strategies defined in `config/sharding.php`.
The default `snowflake` generator produces sortable 64‑bit IDs. To use an auto‑increment
sequence stored in the database, set `id_generator.default` to `sequence`.
A table may override the generator via the `id_generator` option in its configuration.

### Migrating data

1. Add the new shard to `DB_SHARDS` and deploy.
2. List shards being migrated in `DB_SHARD_MIGRATIONS` so new writes avoid them.
3. Move rows with the rebalance command:

   ```bash
   php artisan shards:rebalance items --from=shard-1 --to=shard-10
   ```

   Use `--start` and `--end` to limit the ID range. Supported strategies update any
   metadata, such as Redis mappings, during the move.

4. After all data is copied, remove the shard from `DB_SHARDS` and clear
   `DB_SHARD_MIGRATIONS`.

## Groups

Tables can be grouped so related records share the same shard. Configure the group in `config/sharding.php`:

```php
return [
    'groups' => [
        'user_data' => ['users', 'organizations', 'billing', 'transactions'],
    ],

    'tables' => [
        'users' => [
            'strategy' => 'redis',
            'redis_connection' => 'shards',
            'redis_prefix' => 'user_shard:',
            'connections' => [
                'shard-1' => ['weight' => 1],
                'shard-2' => ['weight' => 1],
            ],
            'group' => 'user_data',
        ],
        'organizations' => [
            'group' => 'user_data',
        ],
    ],
];
```

The Redis strategy looks up shard assignments in Redis. If a key is missing, it
selects a shard using the weighted hash strategy from the configured
`connections` and stores the result in Redis so future requests use the same
shard.

All tables in the `user_data` group will resolve to the same shard based on the strategy defined for `users`.

## Query examples

Models using the `Shardable` trait work with standard Eloquent methods:

```php
$user = User::find(15);

$partners = Organization::where('status', OrganizationStatus::partner)
    ->paginate(50);
```

These calls transparently span all shards defined for the target table.

### Coroutine execution with Swoole

When the application runs within a Swoole coroutine runtime, read queries that
fan out across multiple shards are executed concurrently. Laravel Octane with
the Swoole engine automatically enables this behaviour. The package aggregates
the results through coroutine channels so the request waits only for the
slowest shard. When code executes outside an existing coroutine, the dispatcher
boots a lightweight coroutine scheduler so queries still complete in
parallel.

#### Custom coroutine drivers

You may override the coroutine driver through the `coroutines` section in
`config/sharding.php`. Any class or closure that produces an implementation of
`Allnetru\Sharding\Support\Coroutine\CoroutineDriver` may be registered, letting
you disable concurrency entirely or plug in a different runtime:

```php
'coroutines' => [
    'default' => env('SHARDING_COROUTINE_DRIVER', 'swoole'),
    'drivers' => [
        'swoole' => Allnetru\Sharding\Support\Coroutine\Drivers\SwooleCoroutineDriver::class,
        'sync' => Allnetru\Sharding\Support\Coroutine\Drivers\SyncCoroutineDriver::class,
        'amphp' => App\Sharding\AmpCoroutineDriver::class,
    ],
],
```

Setting the default driver (or `SHARDING_COROUTINE_DRIVER` environment variable)
to `sync` keeps fan-out reads synchronous. Custom drivers may be resolved
through Laravel's service container, enabling you to bind stateful instances or
closures that depend on other services.

## Creating records

Models also handle inserts across shards. When a `Shardable` model is created
without a primary key, a unique identifier is generated and the row is written
to the proper shard automatically:

```php
$user = User::create(['name' => 'Alice']);

$team = Organization::firstOrCreate(['slug' => 'acme'], ['name' => 'Acme']);

Organization::updateOrCreate(['slug' => 'acme'], ['status' => 'partner']);
```

### Relationships

`Shardable` models support cross-shard relationships. In addition to
`belongsTo`, the relations `hasOne`, `hasMany`, `hasOneThrough`,
`hasManyThrough`, `belongsToMany` and all polymorphic variants
(`morphTo`, `morphOne`, `morphMany`, `morphToMany`, `morphedByMany`)
automatically resolve the target shard based on the local or foreign
key.
