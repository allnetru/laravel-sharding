<?php

use Allnetru\Sharding\Support\Config\Shards;

return [
    /*
    |--------------------------------------------------------------------------
    | Default Strategy
    |--------------------------------------------------------------------------
    |
    | The strategy used when a table definition does not explicitly specify one.
    | Supported strategies are registered in the `strategies` array below.
    */
    'default' => 'hash',

    /*
    |--------------------------------------------------------------------------
    | Strategy Registry
    |--------------------------------------------------------------------------
    |
    | Register the strategy classes that can be referenced by your tables. You
    | may extend the package by adding your own strategy implementation here.
    */
    'strategies' => [
        'hash' => Allnetru\Sharding\Strategies\HashStrategy::class,
        'redis' => Allnetru\Sharding\Strategies\RedisStrategy::class,
        'range' => Allnetru\Sharding\Strategies\RangeStrategy::class,
        'db_range' => Allnetru\Sharding\Strategies\DbRangeStrategy::class,
        'db_hash_range' => Allnetru\Sharding\Strategies\DbHashRangeStrategy::class,
    ],

    /*
    |--------------------------------------------------------------------------
    | ID Generation
    |--------------------------------------------------------------------------
    |
    | Configure how primary keys are generated for sharded models. The
    | Snowflake generator is a good default, but you may switch the default or
    | override it per-table. When using the sequence strategy, the package will
    | store counters inside the `sequence_table` on the `meta_connection`.
    */
    'id_generator' => [
        'default' => 'snowflake',
        'strategies' => [
            'snowflake' => Allnetru\Sharding\IdGenerators\SnowflakeStrategy::class,
            'sequence' => Allnetru\Sharding\IdGenerators\TableSequenceStrategy::class,
        ],
        'sequence_table' => 'shard_sequences',

        /*
        | Snowflake worker identity, 0..1023. Each process that mints ids must
        | have its own value: the sequence counter is per process, so two
        | processes sharing a worker id share a counter space and can collide.
        | When unset the value is derived from hostname and pid, which is
        | adequate for a single node but is a fallback, not a guarantee.
        */
        'worker_id' => env('SHARDING_WORKER_ID'),

        /*
        | Snowflake epoch in milliseconds. Forty one bits cover about 69 years
        | from it. Changing the epoch on a populated database reorders ids, so
        | treat it as fixed once data exists.
        */
        'epoch_ms' => env('SHARDING_EPOCH_MS', 1577836800000),
        // 'meta_connection' => 'mysql',
    ],

    /*
    |--------------------------------------------------------------------------
    | Global Connections & Migrations
    |--------------------------------------------------------------------------
    |
    | Shard definitions are collected from environment variables through the
    | Shards helper. Define the credentials in config/database.php and expose
    | shard names via the DB_SHARDS variable. You may also temporarily exclude
    | shards from selection by listing them in DB_SHARD_MIGRATIONS.
    */
    'connections' => Shards::weights(env('DB_SHARDS')),
    'migrations' => Shards::migrations(env('DB_SHARD_MIGRATIONS')),

    /*
    |--------------------------------------------------------------------------
    | Replica Writes
    |--------------------------------------------------------------------------
    |
    | Number of replicas to write to in addition to the primary connection. The
    | strategy will pick the fastest replicas from the configured connection
    | pool. Set to zero to disable fan-out writes.
    */
    'replica_count' => 1,

    /*
    |--------------------------------------------------------------------------
    | Pinning A Query By Its Own Shard Key
    |--------------------------------------------------------------------------
    |
    | When a query's predicate is a conjunction that contains an equality on
    | the shard key — `where('tenant_id', 5)` — the builder resolves that key
    | and reads only the connections it lives on, instead of every shard. This
    | is partition pruning: it changes how many connections are asked, never
    | what the query returns, because no row on another shard can satisfy an
    | AND that names the key.
    |
    | Turn it off while rebalancing. `shards:rebalance` moves rows and updates
    | slots without atomicity between the two, so for the length of a move a
    | row can sit on one connection while the slot names another. A fan-out
    | finds it either way; a pinned read asks the connection the slot names and
    | can miss it. That window is the one reason this exists as a switch.
    */
    'pin_by_key' => env('SHARDING_PIN_BY_KEY', true),

    /*
    |--------------------------------------------------------------------------
    | Routing cache
    |--------------------------------------------------------------------------
    |
    | Where a slot's rows live, remembered so the question is not asked per
    | query. A strategy that keeps its routing in a table — db_hash_range —
    | looked the slot up on the metadata connection before every pinned read,
    | which on two shards made the pinned read slower than the fan-out it
    | replaced: three round trips in sequence against two in parallel.
    |
    | Written through whenever the strategy changes the routing itself, so the
    | cache lags the metadata by one write across every process sharing the
    | store; namespaced by the connection list, so adding a shard is a new
    | namespace rather than a window of stale answers. A store that cannot be
    | reached is simply not consulted.
    |
    | `store` names a cache store from config/cache.php; null means the
    | default one. Prefer something in memory and shared — Redis or Valkey.
    | `ttl` is in seconds and only bounds how long an entry nobody has written
    | through survives.
    |
    */
    'routing_cache' => [
        'enabled' => (bool) env('SHARDING_ROUTING_CACHE', true),
        'store' => env('SHARDING_ROUTING_CACHE_STORE'),
        'ttl' => (int) env('SHARDING_ROUTING_CACHE_TTL', 300),
    ],

    /*
    |--------------------------------------------------------------------------
    | Coroutine Drivers
    |--------------------------------------------------------------------------
    |
    | Configure how shard fan-out queries leverage coroutine runtimes. By
    | default the package will use the Swoole extension when present. Set the
    | default driver to "sync" to disable concurrency or register custom
    | drivers to integrate with other runtimes.
    */
    'coroutines' => [
        'default' => env('SHARDING_COROUTINE_DRIVER', 'swoole'),
        'drivers' => [
            'swoole' => Allnetru\Sharding\Support\Coroutine\Drivers\SwooleCoroutineDriver::class,
            'sync' => Allnetru\Sharding\Support\Coroutine\Drivers\SyncCoroutineDriver::class,
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Table Definitions
    |--------------------------------------------------------------------------
    |
    | Configure how individual tables are distributed. Each entry may specify
    | a strategy, custom connections, replica count, and more. Commented
    | examples below illustrate common layouts used in production projects.
    */
    'tables' => [
        /*
        |--------------------------------------------------------------------------
        | Example: Redis-backed lookup table
        |--------------------------------------------------------------------------
        |
        | Keeps shard assignments inside Redis so rebalancing does not require
        | writing to the database. Ideal for user-centric tables where a small
        | lookup determines the shard for the entire group.
        */
        // 'users' => [
        //     'strategy' => 'redis',
        //     'redis_connection' => 'shards',
        //     'redis_prefix' => 'user_shard:',
        //     'id_generator' => 'snowflake',
        //     'connections' => [
        //         'boost-shard-1' => ['weight' => 2],
        //         'boost-shard-2' => ['weight' => 1],
        //     ],
        //     'group' => 'user_data',
        // ],
        // 'user_profiles' => [
        //     'group' => 'user_data',
        //     // Tables without a strategy inherit the one from the group owner.
        // ],

        /*
        |--------------------------------------------------------------------------
        | Example: Static range allocation
        |--------------------------------------------------------------------------
        |
        | Use when you want full control over which ranges live on each shard.
        | Ranges may be open-ended by omitting the `end` value.
        */
        // 'orders' => [
        //     'strategy' => 'range',
        //     'replica_count' => 0,
        //     'connections' => [
        //         'shard-1' => ['weight' => 1],
        //         'shard-2' => ['weight' => 1],
        //     ],
        //     'ranges' => [
        //         ['start' => 1, 'end' => 1_000_000, 'connection' => 'shard-1'],
        //         ['start' => 1_000_001, 'end' => null, 'connection' => 'shard-2'],
        //     ],
        // ],

        /*
        |--------------------------------------------------------------------------
        | Example: Auto-expanding database ranges
        |--------------------------------------------------------------------------
        |
        | The DB range strategy stores the mapping inside a metadata table. The
        | package will automatically allocate new ranges when the current one is
        | exhausted. You may override the meta connection or range table name.
        */
        // 'invoices' => [
        //     'strategy' => 'db_range',
        //     'connections' => [
        //         'finance-shard-1' => ['weight' => 1],
        //         'finance-shard-2' => ['weight' => 1],
        //     ],
        //     'range_size' => 100_000,
        //     'meta_connection' => 'mysql',
        //     // 'range_table' => 'custom_shard_ranges',
        // ],

        /*
        |--------------------------------------------------------------------------
        | Example: Hybrid hash + range strategy
        |--------------------------------------------------------------------------
        |
        | Spreads IDs across hash slots that are persisted in the database. Each
        | slot can be migrated independently which helps when dealing with a
        | large number of tenants or customers.
        */
        // 'tenants' => [
        //     'strategy' => 'db_hash_range',
        //     'slot_size' => 250_000,
        //     'connections' => [
        //         'tenant-east' => ['weight' => 1],
        //         'tenant-west' => ['weight' => 1],
        //         'tenant-backup' => ['weight' => 1, 'replica' => true],
        //     ],
        //     'meta_connection' => 'mysql',
        // ],

        'shard_tests' => [
            'strategy' => 'db_hash_range',
            'slot_size' => 429496729,
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Table Groups
    |--------------------------------------------------------------------------
    |
    | Groups bind tables together so they reuse the same shard as the group
    | owner (typically the first table listed). This ensures related data lives
    | on the same connection without duplicating strategy configuration.
    */
    'groups' => [
        // 'user_data' => ['users', 'user_profiles', 'billing_accounts', 'invoices'],
    ],
];
