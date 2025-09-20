<?php

use Illuminate\Support\Env;

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
    | Environment configuration
    |--------------------------------------------------------------------------
    |
    | Capture environment-driven values so they remain accessible after the
    | config cache is generated. Runtime helpers pull values from this array
    | instead of calling env() outside of this file.
    */
    'env' => [
        'driver' => Env::get('DB_SHARD_DRIVER', 'mysql'),
        'username' => Env::get('DB_USERNAME', 'forge'),
        'password' => Env::get('DB_PASSWORD', ''),
        'charset' => Env::get('DB_CHARSET', 'utf8mb4'),
        'collation' => Env::get('DB_COLLATION', 'utf8mb4_unicode_ci'),
        'port' => Env::get('DB_PORT', '3306'),
        'mysql_attr_ssl_ca' => Env::get('MYSQL_ATTR_SSL_CA'),
        'db_shards' => Env::get('DB_SHARDS', ''),
        'db_shard_migrations' => Env::get('DB_SHARD_MIGRATIONS', ''),
    ],

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
    'connections' => Allnetru\Sharding\Support\Config\Shards::weights(),
    'migrations' => Allnetru\Sharding\Support\Config\Shards::migrations(),

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
