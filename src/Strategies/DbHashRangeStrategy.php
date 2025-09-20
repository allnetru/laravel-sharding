<?php

namespace Allnetru\Sharding\Strategies;

use Allnetru\Sharding\Models\ShardSlot;
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
        $hash = (int) sprintf('%u', crc32((string) $key));
        $slotSize = $config['slot_size'] ?? 1_000_000;
        $slotId = intdiv($hash, $slotSize);

        $metaConnection = $config['meta_connection'] ?? 'mysql';
        $slotTable = $config['slot_table'] ?? 'shard_slots';
        $scope = $config['group'] ?? $config['table'] ?? null;

        if (!$scope) {
            throw new InvalidArgumentException('No table scope provided for sharding.');
        }

        $slot = ShardSlot::on($metaConnection)->from($slotTable)
            ->where('table', $scope)
            ->where('slot', $slotId)
            ->first();

        if ($slot) {
            $slot->setTable($slotTable);
            $primary = $slot->connection;
            $replicas = $slot->replicas ?? [];
            if (!$replicas && ($config['replica_count'] ?? 0) > 0) {
                $connections = array_keys($config['connections'] ?? []);
                sort($connections);
                $index = array_search($primary, $connections, true);
                $replicas = $this->buildReplicas($connections, $index, $config['replica_count']);
            }

            return array_merge([$primary], $replicas);
        }

        $primary = app(HashStrategy::class)->determine($slotId, $config)[0];
        $connections = array_keys($config['connections'] ?? []);
        sort($connections);
        $index = array_search($primary, $connections, true);
        $replicas = $this->buildReplicas($connections, $index, $config['replica_count'] ?? 0);

        return array_merge([$primary], $replicas);
    }

    /**
     * @inheritdoc
     */
    public function recordMeta(mixed $key, array $connections, array $config): void
    {
        $hash = (int) sprintf('%u', crc32((string) $key));
        $slotSize = $config['slot_size'] ?? 1_000_000;
        $slotId = intdiv($hash, $slotSize);
        $metaConnection = $config['meta_connection'] ?? 'mysql';
        $slotTable = $config['slot_table'] ?? 'shard_slots';
        $scope = $config['group'] ?? $config['table'] ?? null;

        if (!$scope) {
            throw new InvalidArgumentException('No table scope provided for sharding.');
        }

        $primary = $connections[0] ?? '';
        $replicas = array_slice($connections, 1);

        DB::connection($metaConnection)->transaction(function () use ($scope, $slotId, $slotTable, $metaConnection, $primary, $replicas) {
            $query = ShardSlot::on($metaConnection)->from($slotTable)->where('table', $scope);
            $slot = $query->where('slot', $slotId)->lockForUpdate()->first();
            if ($slot) {
                $slot->setTable($slotTable);
                $slot->connection = $primary;
                $slot->replicas = $replicas;
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
    }

    /**
     * @inheritdoc
     */
    public function recordReplica(mixed $key, string $connection, array $config): void
    {
        $hash = (int) sprintf('%u', crc32((string) $key));
        $slotSize = $config['slot_size'] ?? 1_000_000;
        $slotId = intdiv($hash, $slotSize);
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
                $replicas = $slot->replicas ?? [];
                if (!in_array($connection, $replicas, true)) {
                    $replicas[] = $connection;
                    $slot->replicas = $replicas;
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
        $hash = (int) sprintf('%u', crc32((string) $id));
        $slotSize = $config['slot_size'] ?? 1_000_000;
        $slotId = intdiv($hash, $slotSize);
        $metaConnection = $config['meta_connection'] ?? 'mysql';
        $slotTable = $config['slot_table'] ?? 'shard_slots';
        $scope = $config['group'] ?? $config['table'] ?? '';

        $connections = array_keys($config['connections'] ?? []);
        sort($connections);
        $index = array_search($connection, $connections, true);
        $defaultReplicas = $this->buildReplicas($connections, $index, $config['replica_count'] ?? 0);

        DB::connection($metaConnection)->transaction(function () use ($scope, $slotId, $slotTable, $metaConnection, $connection, $defaultReplicas) {
            $query = ShardSlot::on($metaConnection)->from($slotTable)->where('table', $scope);

            while (true) {
                $slot = (clone $query)->where('slot', $slotId)->lockForUpdate()->first();
                if ($slot) {
                    $slot->setTable($slotTable);
                    $replicas = $slot->replicas ?? [];
                    $oldPrimary = $slot->connection;
                    if (($i = array_search($connection, $replicas, true)) !== false) {
                        $replicas[$i] = $oldPrimary;
                    } elseif (empty($replicas)) {
                        $replicas = $defaultReplicas;
                    }
                    $slot->connection = $connection;
                    $slot->replicas = $replicas;
                    $slot->save();

                    return;
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

                    return;
                } catch (QueryException $e) {
                    if ($e->getCode() !== '23000') {
                        throw $e;
                    }

                    // Another process inserted the same slot, retry
                }
            }
        });
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
