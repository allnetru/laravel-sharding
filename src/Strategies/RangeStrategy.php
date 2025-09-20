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
     * @param string $key
     * @param string|null $from
     * @param string|null $to
     * @param int|null $start
     * @param int|null $end
     * @param array $config
     * @return void
     */
    public function afterRebalance(string $table, string $key, ?string $from, ?string $to, ?int $start, ?int $end, array $config): void
    {
        if (!$to) {
            return;
        }

        $ranges = $config['ranges'] ?? [];
        $ranges[] = ['start' => $start, 'end' => $end, 'connection' => $to];
        config(["sharding.tables.{$table}.ranges" => $ranges]);
    }
}
