<?php

namespace Allnetru\Sharding\Strategies;

use InvalidArgumentException;

/**
 * Distributes records across shards using configured numeric ranges.
 */
class RangeStrategy implements Strategy, SupportsAfterRebalance
{
    use Rebalanceable;

    /**
     * Determine shard connections for a key based on configured ranges.
     *
     * @param mixed $key
     * @param array $config
     * @return array<int, string>
     */
    public function determine(mixed $key, array $config): array
    {
        foreach ($config['ranges'] ?? [] as $range) {
            $start = $range['start'] ?? null;
            $end = $range['end'] ?? null;
            if (($start === null || $key >= $start) && ($end === null || $key <= $end)) {
                $primary = $range['connection'];
                $replicaCount = $config['replica_count'] ?? 0;
                $connections = array_keys($config['connections'] ?? []);
                sort($connections);
                $index = array_search($primary, $connections, true);
                $replicas = [];
                $total = count($connections);

                for ($i = 1; $i <= $replicaCount && $i < $total; $i++) {
                    $replicas[] = $connections[($index + $i) % $total];
                }

                return array_merge([$primary], $replicas);
            }
        }

        throw new InvalidArgumentException("No range configured for key [$key]");
    }

    /**
     * @inheritdoc
     */
    public function recordMeta(mixed $key, array $connections, array $config): void
    {
        // Range strategy has no metadata store.
    }

    /**
     * @inheritdoc
     */
    public function recordReplica(mixed $key, string $connection, array $config): void
    {
        // Range strategy has no metadata store.
    }

    /**
     * @inheritdoc
     */
    public function canRebalance(): bool
    {
        return true;
    }

    /**
     * Update configuration ranges after rebalancing.
     *
     * @param string $table
     * @param string $shardKey The column a slot is computed from.
     * @param string|null $from
     * @param string|null $to
     * @param int|null $start
     * @param int|null $end
     * @param array $config
     * @return void
     */
    public function afterRebalance(string $table, string $shardKey, ?string $from, ?string $to, ?int $start, ?int $end, array $config): void
    {
        if (!$to) {
            return;
        }

        /*
        | Written where it is read from. `$table` is the table whose rows
        | moved, which for a colocated child is not where its ranges live:
        | `ShardingManager` resolves the group's owner, so the ranges this
        | strategy was handed came from the owner's entry and a new one written
        | under the child's name is read by nobody. The rows moved and the
        | range went on naming the old connection.
        |
        | `DbRangeStrategy` already resolved the scope this way; this one did
        | not, which is the whole of the difference.
        */
        $scope = $config['table'] ?? $table;

        /*
        | Put in front, not appended. `determine()` takes the first range that
        | contains the key, and a rebalance re-homes a sub-range of one that
        | already exists — so appended, the range being replaced went on
        | matching first and the handoff did nothing at all. Disjoint ranges
        | are unaffected by the order.
        */
        config(["sharding.tables.{$scope}.ranges" => array_merge(
            [['start' => $start, 'end' => $end, 'connection' => $to]],
            (array) ($config['ranges'] ?? []),
        )]);
    }
}
