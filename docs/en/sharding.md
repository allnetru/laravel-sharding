# Sharding

This application provides a pluggable sharding system that allows each table to choose its own strategy.

## Configuration

Shard connections are defined via the `DB_SHARDS` environment variable. Each shard is written as `name:host:port:database` and multiple shards are separated by semicolons:

```
DB_SHARDS="shard_1:db1.example.com:3306:app_db1;shard_2:db2.example.com:3306:app_db2"
```

Each environment can set its own `DB_SHARDS` value to match the number of servers. Other options like `DB_USERNAME` and `DB_PASSWORD` are shared across all shards.

When preparing to migrate or decommission shards, list them in `DB_SHARD_MIGRATIONS`. Each entry is separated by semicolons:

```
DB_SHARD_MIGRATIONS="shard-1;shard-2"
```

Shards listed here are skipped for new writes until data is moved.

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
                'shard_1' => ['weight' => 1],
                'shard_2' => ['weight' => 1],
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
