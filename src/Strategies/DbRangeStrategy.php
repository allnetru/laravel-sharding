<?php

namespace Allnetru\Sharding\Strategies;

use Allnetru\Sharding\Models\ShardRange;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Stores shard range mappings in a database table.
 */
class DbRangeStrategy implements Strategy, SupportsAfterRebalance
{
    use Rebalanceable;

    /**
     * Determine shard connections for a key using database ranges.
     *
     * @param mixed $key
     * @param array $config
     * @return array<int, string>
     */
    public function determine(mixed $key, array $config): array
    {
        $metaConnection = $config['meta_connection'] ?? 'mysql';
        $rangeTable = $config['range_table'] ?? 'shard_ranges';
        $rangeSize = $config['range_size'] ?? 1000;
        $scope = $config['group'] ?? $config['table'] ?? null;

        if (!$scope) {
            throw new InvalidArgumentException('No table scope provided for sharding.');
        }

        $range = ShardRange::on($metaConnection)->from($rangeTable)
            ->where('table', $scope)
            ->where('start', '<=', $key)
            ->where('end', '>=', $key)
            ->first();

        if ($range) {
            $range->setTable($rangeTable);
            $primary = (string) $range->getAttribute('connection');
            $replicas = $range->getAttribute('replicas');
            if (!is_array($replicas)) {
                $replicas = [];
            }
            if (!$replicas && ($config['replica_count'] ?? 0) > 0) {
                $connections = array_keys($config['connections'] ?? []);
                sort($connections);
                $index = array_search($primary, $connections, true);
                $replicas = $this->buildReplicas($connections, $index, $config['replica_count']);
            }

            return array_merge([$primary], $replicas);
        }

        $start = intdiv((int) $key - 1, $rangeSize) * $rangeSize + 1;
        $primary = app(HashStrategy::class)->determine($start, $config)[0];
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
        $metaConnection = $config['meta_connection'] ?? 'mysql';
        $rangeTable = $config['range_table'] ?? 'shard_ranges';
        $rangeSize = $config['range_size'] ?? 1000;
        $scope = $config['group'] ?? $config['table'] ?? null;

        if (!$scope) {
            throw new InvalidArgumentException('No table scope provided for sharding.');
        }

        $start = intdiv((int) $key - 1, $rangeSize) * $rangeSize + 1;
        $end = $start + $rangeSize - 1;
        $primary = $connections[0] ?? '';
        $replicas = array_slice($connections, 1);

        DB::connection($metaConnection)->transaction(function () use ($scope, $start, $end, $rangeTable, $metaConnection, $primary, $replicas) {
            $query = ShardRange::on($metaConnection)->from($rangeTable)->where('table', $scope);
            $range = $query->where('start', $start)->where('end', $end)->lockForUpdate()->first();
            if ($range) {
                $range->setTable($rangeTable);
                $range->setAttribute('connection', $primary);
                $range->setAttribute('replicas', $replicas);
                $range->save();

                return;
            }

            $rangeModel = new ShardRange([
                'table' => $scope,
                'start' => $start,
                'end' => $end,
                'connection' => $primary,
                'replicas' => $replicas,
            ]);
            $rangeModel->setConnection($metaConnection);
            $rangeModel->setTable($rangeTable);
            $rangeModel->save();
        });
    }

    /**
     * @inheritdoc
     */
    public function recordReplica(mixed $key, string $connection, array $config): void
    {
        $metaConnection = $config['meta_connection'] ?? 'mysql';
        $rangeTable = $config['range_table'] ?? 'shard_ranges';
        $rangeSize = $config['range_size'] ?? 1000;
        $scope = $config['group'] ?? $config['table'] ?? null;

        if (!$scope) {
            throw new InvalidArgumentException('No table scope provided for sharding.');
        }

        $start = intdiv((int) $key - 1, $rangeSize) * $rangeSize + 1;
        $end = $start + $rangeSize - 1;

        DB::connection($metaConnection)->transaction(function () use ($scope, $start, $end, $rangeTable, $metaConnection, $connection) {
            $query = ShardRange::on($metaConnection)->from($rangeTable)->where('table', $scope);
            $range = $query->where('start', $start)->where('end', $end)->lockForUpdate()->first();
            if ($range) {
                $range->setTable($rangeTable);
                $replicas = $range->getAttribute('replicas');
                if (!is_array($replicas)) {
                    $replicas = [];
                }
                if (!in_array($connection, $replicas, true)) {
                    $replicas[] = $connection;
                    $range->setAttribute('replicas', $replicas);
                    $range->save();
                }

                return;
            }

            $rangeModel = new ShardRange([
                'table' => $scope,
                'start' => $start,
                'end' => $end,
                'connection' => $connection,
                'replicas' => [],
            ]);
            $rangeModel->setConnection($metaConnection);
            $rangeModel->setTable($rangeTable);
            $rangeModel->save();
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
     * Persist new range information after rebalancing.
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
        if (!$to || $start === null) {
            return;
        }

        $metaConnection = $config['meta_connection'] ?? 'mysql';
        $rangeTable = $config['range_table'] ?? 'shard_ranges';
        $scope = $config['group'] ?? $config['table'] ?? $table;

        $connections = array_keys($config['connections'] ?? []);
        sort($connections);
        $index = array_search($to, $connections, true);
        $replicas = $this->buildReplicas($connections, $index, $config['replica_count'] ?? 0);

        $range = new ShardRange([
            'table' => $scope,
            'start' => $start,
            'end' => $end,
            'connection' => $to,
            'replicas' => $replicas,
        ]);
        $range->setConnection($metaConnection);
        $range->setTable($rangeTable);
        $range->save();
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
