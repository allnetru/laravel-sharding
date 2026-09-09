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
     * **Two keys, and they are not interchangeable.** The shard key is what a
     * slot is computed from, so it is what the range filter and the routing
     * use. The row key is what identifies one row, so it is what the insert,
     * the update, the delete and the paging use.
     *
     * On a table whose shard key is unique they are the same column and the
     * distinction costs nothing. On a colocated one-to-many table — several
     * `user_roles` for one `user_id` — using the shard key for identity means
     * `where('user_id', …)->delete()` after inserting a single row, which
     * deletes every other role that user had. Routing by the wrong key
     * misplaces rows; identifying by the wrong key destroys them.
     *
     * @param string $table
     * @param string $shardKey The column a slot is computed from.
     * @param string $rowKey The column that identifies one row.
     * @param string|null $from
     * @param string|null $to
     * @param int|null $start
     * @param int|null $end
     * @param array $config
     * @return int number of moved records
     */
    public function rebalance(
        string $table,
        string $shardKey,
        string $rowKey,
        ?string $from,
        ?string $to,
        ?int $start,
        ?int $end,
        array $config
    ): int {
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
            // the range is over the shard key: a slot is a range of it
            if ($start !== null) {
                $query->where($shardKey, '>=', $start);
            }
            if ($end !== null) {
                $query->where($shardKey, '<=', $end);
            }

            // paged by the row key, because the rows being deleted underneath
            // this walk are the ones it is walking
            $query->chunkById($chunk, function ($rows) use ($manager, $table, $shardKey, $rowKey, $config, $connection, $to, &$moved, &$failed) {
                foreach ($rows as $row) {
                    $targetConnections = $to ? [$to] : $manager->connectionFor($table, $row->$shardKey);
                    $target = $targetConnections[0];
                    if ($target === $connection) {
                        continue;
                    }

                    $targetConn = DB::connection($target);
                    $sourceConn = DB::connection($connection);

                    $targetConn->beginTransaction();
                    $sourceConn->beginTransaction();

                    try {
                        $existing = $targetConn->table($table)->where($rowKey, $row->$rowKey)->first();
                        if ($existing) {
                            $targetConn->table($table)->where($rowKey, $row->$rowKey)->update(array_merge((array) $row, ['is_replica' => false]));
                            $sourceConn->table($table)->where($rowKey, $row->$rowKey)->update(['is_replica' => true]);
                        } else {
                            $targetConn->table($table)->insert((array) $row);
                            $sourceConn->table($table)->where($rowKey, $row->$rowKey)->delete();
                        }

                        $targetConn->commit();
                        $sourceConn->commit();

                        if ($this instanceof RowMoveAware) {
                            // the slot is the shard key's, not the row's
                            $this->rowMoved($row->$shardKey, $target, $config);
                        }

                        $moved++;
                    } catch (\Throwable $e) {
                        $targetConn->rollBack();
                        $sourceConn->rollBack();

                        Log::error('Failed to move row during rebalance', [
                            'table' => $table,
                            'id' => $row->$rowKey,
                            'shard_key' => $row->$shardKey,
                            'from' => $connection,
                            'to' => $target,
                            'exception' => $e,
                        ]);

                        $failed++;
                    }
                }
            }, $rowKey);
        }

        if ($this instanceof SupportsAfterRebalance) {
            $this->afterRebalance($table, $shardKey, $from, $to, $start, $end, $config);
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
