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

        $clashes += $this->sharedRowKeys($table, $shardKey, $rowKey, $connections, $start, $end, $chunk);

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

                    /*
                    | The preflight looked at a target that was empty at the
                    | time, and an earlier row of this same run may have filled
                    | it since — two source connections holding different rows
                    | under one identifier both converge on it. So the
                    | comparison is repeated here, where the occupant is
                    | whatever is actually there.
                    */
                    if ($existing && !RowComparison::same((array) $existing, (array) $row)) {
                        $targetConn->rollBack();
                        $sourceConn->rollBack();

                        Log::error('Refused to move a row onto a different row with the same key', [
                            'table' => $table,
                            'id' => $row->$rowKey,
                            'from' => $connection,
                            'to' => $target,
                        ]);

                        $failed++;

                        return;
                    }

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
                    if (in_array($connection, $this->copiesToKeep($manager, $table, $shardKey, $to, $row, $targetConnections), true)) {
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
     * How many primary keys two source connections both hold.
     *
     * The preflight above asks whether a destination is occupied, which cannot
     * see a clash created *during* the run: with several source connections
     * being swept, two of them can hold different rows under one identifier
     * that both route to a target empty when it was looked at. The first row
     * lands, the second finds it, and without a comparison the first is lost.
     *
     * A primary key is unique within a connection, so any key two source
     * connections share is either that clash or a duplicate of one row — both
     * of which need a person, not a repair tool. Replicas are out of it on both
     * sides — `walkRows()` does not visit them, and the count below asks for
     * primaries only — because a replica sharing its primary's key on another
     * connection is the normal state of a replicated row, and counting it would
     * refuse every rebalance of a replicated table.
     *
     * Checked by intersecting pages rather than by holding every planned key,
     * so this costs a query per page and a page of memory instead of growing
     * with the size of the run.
     *
     * @param string $table
     * @param string $shardKey
     * @param string $rowKey
     * @param list<string> $connections
     * @param int|null $start
     * @param int|null $end
     * @param int $chunk
     * @return int
     */
    protected function sharedRowKeys(
        string $table,
        string $shardKey,
        string $rowKey,
        array $connections,
        ?int $start,
        ?int $end,
        int $chunk,
    ): int {
        if (count($connections) < 2) {
            return 0;
        }

        $shared = 0;

        foreach ($connections as $index => $connection) {
            $others = array_slice($connections, $index + 1);

            if ($others === []) {
                continue;
            }

            $page = [];

            $this->walkRows($table, $shardKey, $rowKey, $connection, $start, $end, $chunk, function ($row) use ($table, $rowKey, $others, $chunk, &$page, &$shared): void {
                $page[] = $row->$rowKey;

                if (count($page) >= $chunk) {
                    $shared += $this->countShared($table, $rowKey, $others, $page);
                    $page = [];
                }
            });

            if ($page !== []) {
                $shared += $this->countShared($table, $rowKey, $others, $page);
            }
        }

        if ($shared > 0) {
            Log::error('Refused a rebalance: one primary key is held by more than one source connection', [
                'table' => $table,
                'keys' => $shared,
            ]);
        }

        return $shared;
    }

    /**
     * How many of these keys the other connections hold as primaries.
     *
     * @param string $table
     * @param string $rowKey
     * @param list<string> $others
     * @param list<mixed> $keys
     * @return int
     */
    protected function countShared(string $table, string $rowKey, array $others, array $keys): int
    {
        $shared = 0;

        foreach ($others as $other) {
            $shared += DB::connection($other)
                ->table($table)
                ->whereIn($rowKey, $keys)
                ->where(function ($query): void {
                    $query->where('is_replica', false)->orWhereNull('is_replica');
                })
                ->count();
        }

        return $shared;
    }

    /**
     * The connections that keep a copy of the row once it has moved.
     *
     * Without an explicit target this is the tail of the placement the routing
     * gives — the connections the key's replicas belong on.
     *
     * **With `--to` it cannot be, and deriving it from that one-element array
     * deleted a copy the metadata still advertised.** The operator names the
     * primary; the strategy decides what the replicas become, and both
     * `RedisStrategy` and `DbHashRangeStrategy` promote the old primary into
     * the replica list when the target used to be one of its replicas. So the
     * connections the key currently names are kept, minus the target, capped
     * by `replica_count` — which reproduces that promotion and, with no
     * replicas configured, keeps nothing.
     *
     * The strategy's own `rowMoved()` remains the authority on what the
     * metadata says. Where the target is a connection the key never named, the
     * two can choose different replicas; both hold the configured number, and
     * the metadata is what reads follow.
     *
     * @param ShardingManager $manager
     * @param string $table
     * @param string $shardKey
     * @param string|null $to
     * @param object $row
     * @param list<string> $targetConnections The placement this move used.
     * @return list<string>
     */
    protected function copiesToKeep(
        ShardingManager $manager,
        string $table,
        string $shardKey,
        ?string $to,
        object $row,
        array $targetConnections,
    ): array {
        if (!$to) {
            return array_slice($targetConnections, 1);
        }

        [, $config] = $manager->strategyFor($table);
        $current = $this->placementFor($manager, $table, $shardKey, null, $row);

        return array_slice(
            array_values(array_diff($current, [$to])),
            0,
            (int) ($config['replica_count'] ?? 0),
        );
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
                /*
                | A replica copy belongs on a replica connection rather than on
                | the primary its key names, so the rule this walk applies is
                | not its rule — `shards:distribute` skips them for the same
                | reason. Walking them carried each copy onto its own primary,
                | where it compared equal, was written back unchanged and
                | counted as a move: a transaction per replica and a number
                | that meant nothing.
                |
                | The consequence worth knowing: retiring a connection that
                | holds replicas leaves them behind, so those keys carry fewer
                | copies than are configured until something rebuilds them.
                */
                if (!empty($row->is_replica)) {
                    continue;
                }

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
