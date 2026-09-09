<?php

namespace Allnetru\Sharding\Strategies;

use Allnetru\Sharding\Contracts\MetricServiceInterface;
use Allnetru\Sharding\Exceptions\RebalanceIncomplete;
use Allnetru\Sharding\ShardingManager;
use Allnetru\Sharding\Support\RowComparison;
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
     * **Two passes, and the first one writes nothing.** A rebalance is not one
     * operation but two that have to agree: rows move, and the metadata saying
     * where a key lives moves with them. Doing the second while the first only
     * partly happened is what makes rows unreachable — and either direction
     * does it. Hand the range over and the rows left behind are stranded;
     * withhold it and the rows already moved are stranded, because the old
     * range still points at a connection they have left.
     *
     * There is no way to choose correctly after the fact, so the choice is
     * removed: the first pass reads every row that would move and refuses the
     * whole run if any destination is occupied by a different row. That is the
     * failure this can predict, and it is the common one — it is what adopting
     * sharding over databases that counted their own identifiers looks like.
     *
     * What the first pass cannot predict is a connection dropping halfway.
     * Then rows have moved, the routing is deliberately not advanced, and
     * `RebalanceIncomplete` says so: re-running finishes the move and advances
     * the routing at the end of it. **A rebalance is run with
     * `SHARDING_PIN_BY_KEY=false`** — the upgrade notes say so for the same
     * reason — which is what makes that window safe, because an unpinned read
     * finds a row wherever it currently is.
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

        $clashes = 0;

        foreach ($connections as $connection) {
            $this->walkRows($table, $shardKey, $rowKey, $connection, $start, $end, $chunk, function ($row) use ($manager, $table, $shardKey, $rowKey, $to, $connection, &$clashes): void {
                $target = $this->placementFor($manager, $table, $shardKey, $to, $row)[0];

                if ($target === $connection) {
                    return;
                }

                $existing = DB::connection($target)->table($table)->where($rowKey, $row->$rowKey)->first();

                if ($existing === null || RowComparison::same((array) $existing, (array) $row)) {
                    return;
                }

                Log::error('Refused to move a row onto a different row with the same key', [
                    'table' => $table,
                    'id' => $row->$rowKey,
                    'from' => $connection,
                    'to' => $target,
                ]);

                $clashes++;
            });
        }

        if ($clashes > 0) {
            throw new RebalanceIncomplete($table, 0, $clashes);
        }

        $moved = 0;
        $failed = 0;

        /*
        | Which keys ended up where, applied only once the whole run is
        | through. `rowMoved()` redirects a key, and on a colocated
        | one-to-many table one key covers several rows: redirecting it after
        | the first of them points the routing away from the siblings still on
        | the source. Deduplicated by key, so this holds distinct keys rather
        | than rows.
        */
        $redirects = [];

        foreach ($connections as $connection) {
            $this->walkRows($table, $shardKey, $rowKey, $connection, $start, $end, $chunk, function ($row) use ($manager, $table, $shardKey, $rowKey, $connection, $to, &$moved, &$failed, &$redirects): void {
                $targetConnections = $this->placementFor($manager, $table, $shardKey, $to, $row);
                $target = $targetConnections[0];

                if ($target === $connection) {
                    return;
                }

                $targetConn = DB::connection($target);
                $sourceConn = DB::connection($connection);

                $targetConn->beginTransaction();
                $sourceConn->beginTransaction();

                try {
                    $existing = $targetConn->table($table)->where($rowKey, $row->$rowKey)->first();

                    if ($existing) {
                        $targetConn->table($table)->where($rowKey, $row->$rowKey)->update(array_merge((array) $row, ['is_replica' => false]));
                    } else {
                        $targetConn->table($table)->insert((array) $row);
                    }

                    /*
                    | The copy left behind is kept only when this key's
                    | replicas belong on that connection. Marking it a replica
                    | anywhere else — which is what happened whenever the
                    | target already held the row — leaves a copy nothing
                    | advertises, hidden by the `is_replica = false` scope, so
                    | the table carries a duplicate that nothing can see and
                    | nothing rebuilds.
                    */
                    if (in_array($connection, array_slice($targetConnections, 1), true)) {
                        $sourceConn->table($table)->where($rowKey, $row->$rowKey)->update(['is_replica' => true]);
                    } else {
                        $sourceConn->table($table)->where($rowKey, $row->$rowKey)->delete();
                    }

                    $targetConn->commit();
                    $sourceConn->commit();

                    // the slot is the shard key's, not the row's
                    $redirects[(string) $row->$shardKey] = [$row->$shardKey, $target];

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
            });
        }

        /*
        | Both metadata steps, and only when everything arrived. A key
        | redirected while one of its rows is still on the old connection, or a
        | range handed over while any row is, is what makes rows unreachable.
        */
        if ($failed === 0) {
            if ($this instanceof RowMoveAware) {
                foreach ($redirects as [$key, $target]) {
                    $this->rowMoved($key, $target, $config);
                }
            }

            if ($this instanceof SupportsAfterRebalance) {
                $this->afterRebalance($table, $shardKey, $from, $to, $start, $end, $config);
            }
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

        /*
        | Raised rather than returned, because the count alone cannot say it:
        | zero moved reads the same whether there was nothing to do or
        | everything was refused, and the caller that cannot tell those apart
        | reports success either way.
        */
        if ($failed > 0) {
            throw new RebalanceIncomplete($table, $moved, $failed);
        }

        return $moved;
    }

    /**
     * Walk one connection's rows in the range, in pages of the row key.
     *
     * Paged by the row key rather than by offset, because the rows being
     * deleted underneath the walk are the ones it is walking: an offset would
     * skip as many rows as it moved.
     *
     * @param string $table
     * @param string $shardKey
     * @param string $rowKey
     * @param string $connection
     * @param int|null $start
     * @param int|null $end
     * @param int $chunk
     * @param callable(object): void $each
     * @return void
     */
    protected function walkRows(
        string $table,
        string $shardKey,
        string $rowKey,
        string $connection,
        ?int $start,
        ?int $end,
        int $chunk,
        callable $each,
    ): void {
        $query = DB::connection($connection)->table($table);

        // the range is over the shard key: a slot is a range of it
        if ($start !== null) {
            $query->where($shardKey, '>=', $start);
        }

        if ($end !== null) {
            $query->where($shardKey, '<=', $end);
        }

        $query->chunkById($chunk, function ($rows) use ($each): void {
            foreach ($rows as $row) {
                $each($row);
            }
        }, $rowKey);
    }

    /**
     * The connections a row belongs on, the primary first.
     *
     * @param ShardingManager $manager
     * @param string $table
     * @param string $shardKey
     * @param string|null $to An explicit destination, which overrides the routing.
     * @param object $row
     * @return list<string>
     */
    protected function placementFor(
        ShardingManager $manager,
        string $table,
        string $shardKey,
        ?string $to,
        object $row,
    ): array {
        return $to ? [$to] : array_values((array) $manager->connectionFor($table, $row->$shardKey));
    }
}
