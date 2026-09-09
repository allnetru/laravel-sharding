<?php

namespace Allnetru\Sharding\Strategies;

use Allnetru\Sharding\Support\ShardedTable;

/**
 * Contract for sharding strategy implementations.
 */
interface Strategy
{
    /**
     * Determine the shard connection names for the given key.
     *
     * @param mixed $key
     * @param array $config strategy-specific configuration
     * @return array<int, string> [primary, replica1, ...]
     */
    public function determine(mixed $key, array $config): array;

    /**
     * Persist metadata about the primary shard for the given key.
     *
     * @param mixed $key
     * @param array<int, string> $connections
     * @param array $config
     */
    public function recordMeta(mixed $key, array $connections, array $config): void;

    /**
     * Persist metadata about a replica shard for the given key.
     *
     * @param mixed $key
     * @param string $connection
     * @param array $config
     */
    public function recordReplica(mixed $key, string $connection, array $config): void;

    /**
     * Whether the strategy supports rebalancing records.
     *
     * @return bool
     */
    public function canRebalance(): bool;

    /**
     * Move records between shards.
     *
     * Takes every table of a colocation group at once. The routing a
     * rebalance hands over belongs to the group, not to a table: moving one
     * table's rows and redirecting the key sends every sibling's reads to the
     * new connection while their rows are still on the old one. So the tables
     * come as a list, their rows move together, and the routing changes once,
     * after all of them have arrived. A table that is not in a group is a list
     * of one.
     *
     * @param list<ShardedTable> $tables The tables to move, one per table of the group.
     * @param string|null $from Read from this connection only.
     * @param string|null $to Send every row here, overriding the routing.
     * @param int|null $start The lower bound of the shard key, inclusive.
     * @param int|null $end The upper bound of the shard key, inclusive.
     * @param array $config The group owner's configuration, as `ShardingManager::strategyFor()` gives it.
     * @return int number of moved records
     */
    public function rebalance(
        array $tables,
        ?string $from,
        ?string $to,
        ?int $start,
        ?int $end,
        array $config
    ): int;
}
