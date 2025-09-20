<?php

namespace Allnetru\Sharding\Strategies;

use Illuminate\Redis\Connections\PhpRedisConnection;
use Illuminate\Support\Facades\Redis;
use RuntimeException;

/**
 * Stores shard assignments in Redis.
 */
class RedisStrategy implements RowMoveAware, Strategy
{
    use Rebalanceable;

    /**
     * Determine shard connections for the given key from Redis.
     *
     * @return array<int, string>
     */
    public function determine(mixed $key, array $config): array
    {
        $connection = $config['redis_connection'] ?? 'default';
        $prefix = $config['redis_prefix'] ?? ('shard:' . ($config['table'] ?? ''));
        /** @var PhpRedisConnection $redis */
        $redis = Redis::connection($connection);
        $value = $redis->get($prefix . $key);

        if ($value !== null) {
            $decoded = json_decode($value, true);
            if (is_array($decoded)) {
                return $decoded;
            }
            $primary = $value;
        } else {
            $primary = null;
        }

        $connections = $config['connections'] ?? [];
        if ($primary === null) {
            if (!$connections) {
                throw new RuntimeException("Shard for key [$key] not found in Redis");
            }
            $hashStrategy = new HashStrategy;
            $primary = $hashStrategy->determine($key, $config)[0];
        }

        $replicaCount = $config['replica_count'] ?? 0;
        $names = array_keys($connections);
        sort($names);
        $index = array_search($primary, $names, true);
        $replicas = [];
        $total = count($names);

        for ($i = 1; $i <= $replicaCount && $i < $total; $i++) {
            $replicas[] = $names[($index + $i) % $total];
        }

        return array_merge([$primary], $replicas);
    }

    /**
     * @inheritdoc
     */
    public function canRebalance(): bool
    {
        return true;
    }

    /**
     * Update Redis mapping after a record is moved.
     */
    public function rowMoved(int|string $id, string $connection, array $config): void
    {
        $redisConnection = $config['redis_connection'] ?? 'default';
        $prefix = $config['redis_prefix'] ?? ('shard:' . ($config['table'] ?? ''));
        /** @var PhpRedisConnection $redis */
        $redis = Redis::connection($redisConnection);
        $current = $redis->get($prefix . $id);
        $decoded = $current !== null ? json_decode($current, true) : null;
        $currentConnections = is_array($decoded) ? $decoded : array_filter([$decoded]);

        $primary = $currentConnections[0] ?? null;
        $replicas = array_slice($currentConnections, 1);

        if (($idx = array_search($connection, $replicas, true)) !== false && $primary !== null) {
            $replicas[$idx] = $primary;
        } else {
            $names = array_keys($config['connections'] ?? []);
            sort($names);
            $index = array_search($connection, $names, true);
            $total = count($names);
            $replicaCount = $config['replica_count'] ?? 0;
            $replicas = [];
            for ($i = 1; $i <= $replicaCount && $i < $total; $i++) {
                $replicas[] = $names[($index + $i) % $total];
            }
        }

        $redis->set($prefix . $id, json_encode(array_merge([$connection], $replicas)));
    }

    /**
     * @inheritdoc
     */
    public function recordMeta(mixed $key, array $connections, array $config): void
    {
        $redisConnection = $config['redis_connection'] ?? 'default';
        $prefix = $config['redis_prefix'] ?? ('shard:' . ($config['table'] ?? ''));
        /** @var PhpRedisConnection $redis */
        $redis = Redis::connection($redisConnection);
        $redis->set($prefix . $key, json_encode($connections));
    }

    /**
     * @inheritdoc
     */
    public function recordReplica(mixed $key, string $connection, array $config): void
    {
        $redisConnection = $config['redis_connection'] ?? 'default';
        $prefix = $config['redis_prefix'] ?? ('shard:' . ($config['table'] ?? ''));
        /** @var PhpRedisConnection $redis */
        $redis = Redis::connection($redisConnection);
        $current = $redis->get($prefix . $key);
        $decoded = $current !== null ? json_decode($current, true) : null;
        $connections = is_array($decoded) ? $decoded : array_filter([$decoded]);

        if (!in_array($connection, $connections, true)) {
            $connections[] = $connection;
            $redis->set($prefix . $key, json_encode($connections));
        }
    }
}
