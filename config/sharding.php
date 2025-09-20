<?php

use Allnetru\Sharding\Support\Config\Shards;

return [
    // Default strategy used when table-specific strategy is not defined
    'default' => 'hash',

    // Available strategies
    'strategies' => [
        'hash' => Allnetru\Sharding\Strategies\HashStrategy::class,
        'redis' => Allnetru\Sharding\Strategies\RedisStrategy::class,
        'range' => Allnetru\Sharding\Strategies\RangeStrategy::class,
        'db_range' => Allnetru\Sharding\Strategies\DbRangeStrategy::class,
        'db_hash_range' => Allnetru\Sharding\Strategies\DbHashRangeStrategy::class,
    ],

    'id_generator' => [
        'default' => 'snowflake',
        'strategies' => [
            'snowflake' => Allnetru\Sharding\IdGenerators\SnowflakeStrategy::class,
            'sequence' => Allnetru\Sharding\IdGenerators\TableSequenceStrategy::class,
        ],
        'sequence_table' => 'shard_sequences',
        // 'meta_connection' => 'mysql',
    ],

    // Global shard connections with optional weights.
    // Connection credentials are defined in config/database.php under the same names.
    'connections' => Shards::weights(),
    // Optional list of shards being migrated; they are excluded from selection.
    'migrations' => Shards::migrations(),
    // Number of replicas to write to in addition to the primary connection.
    'replica_count' => 1,

    // Per table configuration
    'tables' => [
        // Example of redis strategy where shard assignments are stored in Redis.
        // 'users' => [
        //     'strategy' => 'redis',
        //     'redis_connection' => 'shards',
        //     'redis_prefix' => 'user_shard:',
        //     'connections' => [
        //         'boost-shard-1' => ['weight' => 1],
        //         'boost-shard-2' => ['weight' => 1],
        //     ],
        //     'group' => 'user_data',
        // ],
        // 'organizations' => [
        //     'group' => 'user_data',
        // ],

        // Example of range strategy mapping id ranges to shards.
        // 'orders' => [
        //     'strategy' => 'range',
        //     'replica_count' => 1,
        //     'connections' => [
        //         'shard-1' => ['weight' => 1],
        //         'shard-2' => ['weight' => 1],
        //     ],
        //     'ranges' => [
        //         ['start' => 1, 'end' => 1000, 'connection' => 'shard-1'],
        //         ['start' => 1001, 'end' => null, 'connection' => 'shard-2'],
        //     ],
        // ],

        // Example of database-backed range strategy that automatically expands.
        // 'invoices' => [
        //     'strategy' => 'db_range',
        //     'connections' => [
        //         'shard-1' => ['weight' => 1],
        //         'shard-2' => ['weight' => 1],
        //     ],
        //     'range_size' => 1000,
        //     'meta_connection' => 'mysql',
        //     // optional custom table storing ranges
        //     // 'range_table' => 'shard_ranges',
        // ],

        'shard_tests' => [
            'strategy' => 'db_hash_range',
            'slot_size' => 429496729,
        ],
    ],

    // Groups of tables that should reside on the same shard
    'groups' => [
        // 'user_data' => ['users', 'organizations', 'billing', 'transactions'],
    ],
];
