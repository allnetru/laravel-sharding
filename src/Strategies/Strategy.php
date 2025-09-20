<?php

namespace Allnetru\Sharding\Strategies;

/**
 * Contract for sharding strategy implementations.
 */
interface Strategy
{
    /**
     * Determine the shard connection names for the given key.
     *
     * @param  mixed  $key
     * @param  array  $config  strategy-specific configuration
     * @return array<int, string>  [primary, replica1, ...]
     */
    public function determine(mixed $key, array $config): array;

    /**
     * Persist metadata about the primary shard for the given key.
     *
     * @param  mixed   $key
     * @param  string  $connection
     * @param  array   $config
     * @return void
     */
    public function recordMeta(mixed $key, array $connections, array $config): void;

    /**
     * Persist metadata about a replica shard for the given key.
     *
     * @param  mixed   $key
     * @param  string  $connection
     * @param  array   $config
     * @return void
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
     * @param  string       $table
     * @param  string       $key
     * @param  string|null  $from
     * @param  string|null  $to
     * @param  int|null     $start
     * @param  int|null     $end
     * @param  array        $config
     * @return int number of moved records
     */
    public function rebalance(string $table, string $key, ?string $from, ?string $to, ?int $start, ?int $end, array $config): int;
}
