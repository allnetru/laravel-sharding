<?php

namespace Allnetru\Sharding\Strategies;

use Allnetru\Sharding\Contracts\MetricServiceInterface;
use Allnetru\Sharding\ShardingManager;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Shared logic for moving rows between shard connections.
 */
trait Rebalanceable
{
    /**
     * Move records between shard connections.
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
    public function rebalance(string $table, string $key, ?string $from, ?string $to, ?int $start, ?int $end, array $config): int
    {
        /** @var ShardingManager $manager */
        $manager = app(ShardingManager::class);
        $connections = array_keys($manager->connectionsFor($table));
        if ($from) {
            $connections = [$from];
        }

        $chunk = 1000;
        $moved = 0;
        $failed = 0;

        foreach ($connections as $connection) {
            $query = DB::connection($connection)->table($table);
            if ($start !== null) {
                $query->where($key, '>=', $start);
            }
            if ($end !== null) {
                $query->where($key, '<=', $end);
            }

            $query->chunkById($chunk, function ($rows) use ($manager, $table, $key, $config, $connection, $to, &$moved, &$failed) {
                foreach ($rows as $row) {
                    $targetConnections = $to ? [$to] : $manager->connectionFor($table, $row->$key);
                    $target = $targetConnections[0];
                    if ($target === $connection) {
                        continue;
                    }

                    $targetConn = DB::connection($target);
                    $sourceConn = DB::connection($connection);

                    $targetConn->beginTransaction();
                    $sourceConn->beginTransaction();

                    try {
                        $existing = $targetConn->table($table)->where($key, $row->$key)->first();
                        if ($existing) {
                            $targetConn->table($table)->where($key, $row->$key)->update(array_merge((array) $row, ['is_replica' => false]));
                            $sourceConn->table($table)->where($key, $row->$key)->update(['is_replica' => true]);
                        } else {
                            $targetConn->table($table)->insert((array) $row);
                            $sourceConn->table($table)->where($key, $row->$key)->delete();
                        }

                        $targetConn->commit();
                        $sourceConn->commit();

                        if ($this instanceof RowMoveAware) {
                            $this->rowMoved($row->$key, $target, $config);
                        }

                        $moved++;
                    } catch (\Throwable $e) {
                        $targetConn->rollBack();
                        $sourceConn->rollBack();

                        Log::error('Failed to move row during rebalance', [
                            'table' => $table,
                            'id' => $row->$key,
                            'from' => $connection,
                            'to' => $target,
                            'exception' => $e,
                        ]);

                        $failed++;
                    }
                }
            }, $key);
        }

        if ($this instanceof SupportsAfterRebalance) {
            $this->afterRebalance($table, $key, $from, $to, $start, $end, $config);
        }

        Log::info('Rebalance completed', [
            'table' => $table,
            'moved' => $moved,
            'failed' => $failed,
        ]);

        if (app()->bound(MetricServiceInterface::class)) {
            $metrics = app(MetricServiceInterface::class);
            $metrics->increment('sharding.rebalance.success', $moved);
            $metrics->increment('sharding.rebalance.failed', $failed);
        }

        return $moved;
    }
}
