<?php

namespace Allnetru\Sharding\Strategies;

use InvalidArgumentException;

/**
 * Assigns shards based on a weighted hash of the record key.
 */
class HashStrategy implements Strategy
{
    /**
     * Determine shard connections for the given key.
     *
     * @param mixed $key
     * @param array $config
     * @return array<int, string>
     */
    public function determine(mixed $key, array $config): array
    {
        $shards = $config['connections'] ?? [];

        if (empty($shards)) {
            throw new InvalidArgumentException('No shards configured.');
        }

        $total = array_sum(array_map(fn ($s) => $s['weight'] ?? 1, $shards));
        $hash = (int) sprintf('%u', crc32((string) $key));
        $hash %= $total;

        $primary = array_key_first($shards);

        foreach ($shards as $name => $shardConfig) {
            $weight = $shardConfig['weight'] ?? 1;
            if ($hash < $weight) {
                $primary = $name;
                break;
            }
            $hash -= $weight;
        }

        $replicaCount = $config['replica_count'] ?? 0;
        $names = array_keys($shards);
        sort($names);
        $index = array_search($primary, $names, true);
        $replicas = [];
        $totalNames = count($names);

        for ($i = 1; $i <= $replicaCount && $i < $totalNames; $i++) {
            $replicas[] = $names[($index + $i) % $totalNames];
        }

        return array_merge([$primary], $replicas);
    }

    /**
     * @inheritdoc
     */
    public function recordMeta(mixed $key, array $connections, array $config): void
    {
        // Hash strategy has no metadata store.
    }

    /**
     * @inheritdoc
     */
    public function recordReplica(mixed $key, string $connection, array $config): void
    {
        // Hash strategy has no metadata store.
    }

    /**
     * @inheritdoc
     */
    public function canRebalance(): bool
    {
        return false;
    }

    /**
     * @inheritdoc
     */
    public function rebalance(string $table, string $key, ?string $from, ?string $to, ?int $start, ?int $end, array $config): int
    {
        throw new \RuntimeException('Rebalancing is not supported for hash strategy.');
    }
}
