<?php

namespace Allnetru\Sharding\Strategies;

use Allnetru\Sharding\Models\ShardSlot;
use Allnetru\Sharding\Support\Database\UniqueConstraintViolationDetector;
use Allnetru\Sharding\Support\RoutingCache;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Hybrid strategy combining hashing with persistent slot assignments.
 */
class DbHashRangeStrategy implements RowMoveAware, Strategy
{
    use Rebalanceable;

    /**
     * Determine shard connections for a key using hash slots stored in the database.
     *
     * @param mixed $key
     * @param array $config
     * @return array<int, string>
     */
    public function determine(mixed $key, array $config): array
    {
        $slotId = $this->slotFor($key, $config);
        $scope = $config['group'] ?? $config['table'] ?? null;

        if (!$scope) {
            throw new InvalidArgumentException('No table scope provided for sharding.');
        }

        /*
        | Remembered rather than looked up per query. The slot table is on the
        | metadata connection, so without this every pinned read paid a round
        | trip there before it could run — which on two shards made the pinned
        | read slower than the fan-out it replaced. `recordMeta()` and
        | `rowMoved()` write through, so the cache lags the metadata by the
        | length of one write.
        */
        return RoutingCache::remember(
            RoutingCache::namespaceFor($config),
            "slot:{$slotId}",
            fn (): array => $this->lookUp($slotId, $scope, $config),
        );
    }

    /**
     * The slot's placement as the metadata has it, or as the hash would have it.
     *
     * The two are told apart in the answer. A hashed placement is worth
     * remembering — it is what the row will be written by — but it is not
     * evidence the slot was ever recorded, and `recordMeta()` skips its write
     * only when the metadata is known to hold this already.
     *
     * @param int $slotId
     * @param string $scope
     * @param array<string, mixed> $config
     * @return array{placement: list<string>, recorded: bool}
     */
    protected function lookUp(int $slotId, string $scope, array $config): array
    {
        $metaConnection = $config['meta_connection'] ?? 'mysql';
        $slotTable = $config['slot_table'] ?? 'shard_slots';

        $slot = ShardSlot::on($metaConnection)->from($slotTable)
            ->where('table', $scope)
            ->where('slot', $slotId)
            ->first();

        if ($slot) {
            $slot->setTable($slotTable);
            $primary = (string) $slot->getAttribute('connection');
            $replicas = $slot->getAttribute('replicas');
            if (!is_array($replicas)) {
                $replicas = [];
            }
            if (!$replicas && ($config['replica_count'] ?? 0) > 0) {
                $connections = array_keys($config['connections'] ?? []);
                sort($connections);
                $index = array_search($primary, $connections, true);
                $replicas = $this->buildReplicas($connections, $index, $config['replica_count']);
            }

            return ['placement' => array_merge([$primary], array_values($replicas)), 'recorded' => true];
        }

        $primary = app(HashStrategy::class)->determine($slotId, $config)[0];
        $connections = array_keys($config['connections'] ?? []);
        sort($connections);
        $index = array_search($primary, $connections, true);
        $replicas = $this->buildReplicas($connections, $index, $config['replica_count'] ?? 0);

        return ['placement' => array_merge([$primary], $replicas), 'recorded' => false];
    }

    /**
     * The slot a key hashes into.
     *
     * @param mixed $key
     * @param array<string, mixed> $config
     * @return int
     */
    protected function slotFor(mixed $key, array $config): int
    {
        $hash = (int) sprintf('%u', crc32((string) $key));
        $slotSize = $config['slot_size'] ?? 1_000_000;

        return intdiv($hash, $slotSize);
    }

    /**
     * @inheritdoc
     */
    public function recordMeta(mixed $key, array $connections, array $config): void
    {
        $slotId = $this->slotFor($key, $config);
        $metaConnection = $config['meta_connection'] ?? 'mysql';
        $slotTable = $config['slot_table'] ?? 'shard_slots';
        $scope = $config['group'] ?? $config['table'] ?? null;

        if (!$scope) {
            throw new InvalidArgumentException('No table scope provided for sharding.');
        }

        $primary = $connections[0] ?? '';
        $replicas = array_slice($connections, 1);

        /*
        | Called after every insert, and a slot is recorded once. When the
        | cache already says what is about to be written there is nothing to
        | write: the transaction below is a locked read and a save on the
        | metadata connection — three round trips per insert to write down
        | what the metadata already said.
        */
        $namespace = RoutingCache::namespaceFor($config);
        $placement = array_merge([$primary], $replicas);
        $known = RoutingCache::get($namespace, "slot:{$slotId}");

        if ($known !== null && $known['recorded'] && $known['placement'] === $placement) {
            return;
        }

        DB::connection($metaConnection)->transaction(function () use ($scope, $slotId, $slotTable, $metaConnection, $primary, $replicas) {
            $query = ShardSlot::on($metaConnection)->from($slotTable)->where('table', $scope);
            $slot = $query->where('slot', $slotId)->lockForUpdate()->first();
            if ($slot) {
                $slot->setTable($slotTable);
                $slot->setAttribute('connection', $primary);
                $slot->setAttribute('replicas', $replicas);
                $slot->save();

                return;
            }

            $slotModel = new ShardSlot([
                'table' => $scope,
                'slot' => $slotId,
                'connection' => $primary,
                'replicas' => $replicas,
            ]);
            $slotModel->setConnection($metaConnection);
            $slotModel->setTable($slotTable);
            $slotModel->save();
        });

        RoutingCache::put($namespace, "slot:{$slotId}", $placement, true);
    }

    /**
     * @inheritdoc
     */
    public function recordReplica(mixed $key, string $connection, array $config): void
    {
        $slotId = $this->slotFor($key, $config);
        $metaConnection = $config['meta_connection'] ?? 'mysql';
        $slotTable = $config['slot_table'] ?? 'shard_slots';
        $scope = $config['group'] ?? $config['table'] ?? null;

        if (!$scope) {
            throw new InvalidArgumentException('No table scope provided for sharding.');
        }

        DB::connection($metaConnection)->transaction(function () use ($scope, $slotId, $slotTable, $metaConnection, $connection) {
            $query = ShardSlot::on($metaConnection)->from($slotTable)->where('table', $scope);
            $slot = $query->where('slot', $slotId)->lockForUpdate()->first();
            if ($slot) {
                $slot->setTable($slotTable);
                $replicas = $slot->getAttribute('replicas');
                if (!is_array($replicas)) {
                    $replicas = [];
                }
                if (!in_array($connection, $replicas, true)) {
                    $replicas[] = $connection;
                    $slot->setAttribute('replicas', $replicas);
                    $slot->save();
                }

                return;
            }

            $slotModel = new ShardSlot([
                'table' => $scope,
                'slot' => $slotId,
                'connection' => $connection,
                'replicas' => [],
            ]);
            $slotModel->setConnection($metaConnection);
            $slotModel->setTable($slotTable);
            $slotModel->save();
        });

        // the placement changed shape and the next lookup should read it
        RoutingCache::forget(RoutingCache::namespaceFor($config), "slot:{$slotId}");
    }

    /**
     * @inheritdoc
     */
    public function canRebalance(): bool
    {
        return true;
    }

    /**
     * Handle updates after a record is moved.
     *
     * @param int|string $id
     * @param string $connection
     * @param array $config
     * @return void
     */
    public function rowMoved(int|string $id, string $connection, array $config): void
    {
        $slotId = $this->slotFor($id, $config);
        $metaConnection = $config['meta_connection'] ?? 'mysql';
        $slotTable = $config['slot_table'] ?? 'shard_slots';
        $scope = $config['group'] ?? $config['table'] ?? '';

        $connections = array_keys($config['connections'] ?? []);
        sort($connections);
        $index = array_search($connection, $connections, true);
        $defaultReplicas = $this->buildReplicas($connections, $index, $config['replica_count'] ?? 0);

        $placement = DB::connection($metaConnection)->transaction(function () use ($scope, $slotId, $slotTable, $metaConnection, $connection, $defaultReplicas): array {
            $query = ShardSlot::on($metaConnection)->from($slotTable)->where('table', $scope);

            while (true) {
                $slot = (clone $query)->where('slot', $slotId)->lockForUpdate()->first();
                if ($slot) {
                    $slot->setTable($slotTable);
                    $replicas = $slot->getAttribute('replicas');
                    if (!is_array($replicas)) {
                        $replicas = [];
                    }
                    $oldPrimary = (string) $slot->getAttribute('connection');
                    if (($i = array_search($connection, $replicas, true)) !== false) {
                        $replicas[$i] = $oldPrimary;
                    } elseif (empty($replicas)) {
                        $replicas = $defaultReplicas;
                    }
                    $slot->setAttribute('connection', $connection);
                    $slot->setAttribute('replicas', $replicas);
                    $slot->save();

                    return array_merge([$connection], array_values($replicas));
                }

                try {
                    $slotModel = new ShardSlot([
                        'table' => $scope,
                        'slot' => $slotId,
                        'connection' => $connection,
                        'replicas' => $defaultReplicas,
                    ]);
                    $slotModel->setConnection($metaConnection);
                    $slotModel->setTable($slotTable);
                    $slotModel->save();

                    return array_merge([$connection], $defaultReplicas);
                } catch (QueryException $e) {
                    if (!UniqueConstraintViolationDetector::causedBy($e)) {
                        throw $e;
                    }

                    // Another process inserted the same slot, retry
                }
            }
        });

        /*
        | Written through, and this is what makes the cache safe to have: the
        | routing changed here, so every process sharing the store sees the new
        | placement from the next lookup on, rather than after a TTL.
        */
        RoutingCache::put(RoutingCache::namespaceFor($config), "slot:{$slotId}", $placement, true);
    }

    /**
     * Build replica connection list.
     *
     * @param array<int, string> $connections
     * @param int $index
     * @param int $replicaCount
     * @return array<int, string>
     */
    private function buildReplicas(array $connections, int $index, int $replicaCount): array
    {
        $replicas = [];
        $total = count($connections);

        for ($i = 1; $i <= $replicaCount && $i < $total; $i++) {
            $replicas[] = $connections[($index + $i) % $total];
        }

        return $replicas;
    }
}
