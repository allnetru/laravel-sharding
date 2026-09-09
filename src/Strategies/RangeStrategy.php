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
     * @param string $table The group owner's table, which is where the ranges live.
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
        /*
        | Nothing to hand over without a target, and nothing that may be handed
        | over without a start. A range with neither bound is a catch-all, and
        | put in front — see below — it would send every key of the table to
        | `$to`, including every key that never moved. The trait refuses a
        | boundless `--to` for a range strategy before any row moves; this is
        | the same rule kept where a direct caller would otherwise get past it.
        */
        if ($to === null || $start === null) {
            return;
        }

        /*
        | Written where it is read from. `$table` is the group owner's, and
        | `ShardingManager` resolves a colocated child to that owner, so the
        | ranges this strategy was handed came from the owner's entry — a range
        | written under a child's name is read by nobody.
        */
        $scope = $config['table'] ?? $table;
        $handed = ['start' => $start, 'end' => $end, 'connection' => $to];
        $ranges = array_values((array) ($config['ranges'] ?? []));

        /*
        | A rerun finds its own range already in front and leaves the config
        | as it is: re-running is the documented recovery for an interrupted
        | rebalance, and it must not stack a copy of the range per attempt.
        */
        if (($ranges[0] ?? null) == $handed) {
            return;
        }

        /*
        | Put in front, not appended. `determine()` takes the first range that
        | contains the key, and a rebalance re-homes a sub-range of one that
        | already exists — so appended, the range being replaced went on
        | matching first and the handoff did nothing at all. Disjoint ranges
        | are unaffected by the order.
        */
        config(["sharding.tables.{$scope}.ranges" => array_merge([$handed], $ranges)]);
    }
}
