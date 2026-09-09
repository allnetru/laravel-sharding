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
        $clashes += $this->keysLeftBehind($manager, $table, $shardKey, $rowKey, $connections, $to, $start, $end, $chunk);

        if ($clashes > 0) {
            throw new RebalanceIncomplete($table, 0, $clashes);
        }

        $moved = 0;
        $failed = 0;

        foreach ($connections as $connection) {
            $this->walkRows($table, $shardKey, $rowKey, $connection, $start, $end, $chunk, function ($row) use ($manager, $table, $shardKey, $rowKey, $connection, $to, &$moved, &$failed): void {
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
            [$redirects, $split] = $this->redirectsFromWhereRowsAre(
                $manager,
                $table,
                $shardKey,
                $rowKey,
                $start,
                $end,
                $chunk,
            );

            $failed += $split;

            if ($failed === 0) {
                $failed += $this->handOverRouting($table, $redirects, $config);
            }

            if ($failed === 0 && $this instanceof SupportsAfterRebalance) {
                $this->afterRebalance($table, $shardKey, $from, $to, $start, $end, $config);
            }

            /*
            | Last, because until both metadata steps are done the routing has
            | not finished saying where the copies belong — a range strategy
            | chooses the replicas of a moved key inside `afterRebalance()`.
            |
            | And asked of a fresh manager, because the one above is a
            | singleton that copied the `sharding` array in its constructor.
            | `RangeStrategy::afterRebalance()` hands the range over by writing
            | the config, which that snapshot cannot see: the pass would place
            | copies for the old placement, or fail with «No range configured»
            | after the rows had already moved.
            */
            if ($failed === 0) {
                $failed += $this->materialisePlacements(
                    $this->freshManager(),
                    $table,
                    $shardKey,
                    $rowKey,
                    $start,
                    $end,
                    $chunk,
                );
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
     * A manager that has read the routing as it is now.
     *
     * The bound instance is a singleton that copied the `sharding` array when
     * it was built, and a range strategy hands its range over by writing that
     * config. Forgotten and re-resolved, so the application's own binding
     * still decides what a manager is.
     *
     * @return ShardingManager
     */
    protected function freshManager(): ShardingManager
    {
        app()->forgetInstance(ShardingManager::class);

        return app(ShardingManager::class);
    }

    /**
     * How many keys this run would move only part of.
     *
     * **The split the primary-key preflight cannot see.** That one asks
     * whether two connections hold the same *row*; this asks whether they hold
     * the same *key*, which on a colocated table they can do with entirely
     * different row keys. With `--from` naming one connection, the rows there
     * move to the target and the rows elsewhere stay — so the key ends up
     * spread across two connections, its routing can name only one of them,
     * and the other half stops being found. Detected afterwards, that is
     * unrecoverable: the move has happened and rerunning finds the same split.
     *
     * The condition is precise, because a looser one would break the recovery
     * this command relies on. A key whose rows sit on a connection this run
     * will walk is fine: they are all sent to the same target and arrive
     * together. A key whose rows sit on the target already is fine too — that
     * is exactly what an interrupted run leaves, and finishing it is the
     * documented recovery. What is refused is a key with rows on a connection
     * that will neither be walked nor be the destination.
     *
     * @param ShardingManager $manager
     * @param string $table
     * @param string $shardKey
     * @param string $rowKey
     * @param list<string> $walked The connections this run reads.
     * @param string|null $to
     * @param int|null $start
     * @param int|null $end
     * @param int $chunk
     * @return int
     */
    protected function keysLeftBehind(
        ShardingManager $manager,
        string $table,
        string $shardKey,
        string $rowKey,
        array $walked,
        ?string $to,
        ?int $start,
        ?int $end,
        int $chunk,
    ): int {
        $all = array_keys((array) $manager->connectionsFor($table));

        if (count($all) === count($walked)) {
            return 0;
        }

        $left = 0;

        foreach ($this->primariesByKey($manager, $table, $shardKey, $rowKey, $all, $start, $end, $chunk) as $entry) {
            $target = $to ?? ($manager->connectionFor($table, $entry['key'])[0] ?? null);

            $stranded = array_diff(array_keys($entry['connections']), $walked, [$target]);

            if ($stranded === []) {
                continue;
            }

            Log::error('Refused a rebalance: this run would move only part of a key', [
                'table' => $table,
                'shard_key' => $entry['key'],
                'left_on' => array_values($stranded),
                'walking' => $walked,
                'target' => $target,
            ]);

            $left++;
        }

        return $left;
    }

    /**
     * Where each key's primary rows are, across the given connections.
     *
     * @param ShardingManager $manager
     * @param string $table
     * @param string $shardKey
     * @param string $rowKey
     * @param list<string> $connections
     * @param int|null $start
     * @param int|null $end
     * @param int $chunk
     * @return array<string, array{key: mixed, connections: array<string, true>}>
     */
    protected function primariesByKey(
        ShardingManager $manager,
        string $table,
        string $shardKey,
        string $rowKey,
        array $connections,
        ?int $start,
        ?int $end,
        int $chunk,
    ): array {
        $where = [];

        foreach ($connections as $connection) {
            $this->walkRows($table, $shardKey, $rowKey, $connection, $start, $end, $chunk, function ($row) use ($shardKey, $connection, &$where): void {
                $key = (string) $row->$shardKey;

                $where[$key]['key'] = $row->$shardKey;
                $where[$key]['connections'][$connection] = true;
            });
        }

        return $where;
    }

    /**
     * Which keys the routing is wrong about, read off the data itself.
     *
     * **Derived rather than remembered, and that is what makes a rerun
     * finish the job.** This used to be the set of rows the run had moved,
     * which is a record of what happened rather than of what is true: a
     * transient failure partway through the move, or a `rowMoved()` that
     * threw, left rows on the new connection with the routing naming the old
     * one — and a rerun, seeing nothing left to move on the source, had an
     * empty set and reported success over keys still pointing at the wrong
     * shard.
     *
     * Read off the data there is no such gap. Every configured connection is
     * walked, not only the source: a key whose rows sit somewhere the routing
     * does not name needs redirecting, whoever moved them and whenever. So the
     * recovery for an interrupted rebalance is to run it again, and it works
     * even when the interruption was in the handoff itself.
     *
     * A key whose rows are spread over more than one connection is not
     * decided: it is counted as a failure and named in the log, because
     * choosing either connection would strand the rows on the other.
     *
     * @param ShardingManager $manager
     * @param string $table
     * @param string $shardKey
     * @param string $rowKey
     * @param int|null $start
     * @param int|null $end
     * @param int $chunk
     * @return array{0: array<string, array{0: mixed, 1: string}>, 1: int}
     */
    protected function redirectsFromWhereRowsAre(
        ShardingManager $manager,
        string $table,
        string $shardKey,
        string $rowKey,
        ?int $start,
        ?int $end,
        int $chunk,
    ): array {
        $where = $this->primariesByKey(
            $manager,
            $table,
            $shardKey,
            $rowKey,
            array_keys((array) $manager->connectionsFor($table)),
            $start,
            $end,
            $chunk,
        );

        $redirects = [];
        $split = 0;

        foreach ($where as $key => $entry) {
            /*
            | Defence in depth rather than a reachable branch: `keysLeftBehind()`
            | refuses a run that would leave a key spread, and a run that
            | failed does not get here. It stays because the alternative is
            | choosing one of the two connections silently, and choosing wrong
            | strands the rows on the other.
            */
            if (count($entry['connections']) > 1) {
                Log::error('A key has rows on more than one connection, so its routing cannot be decided', [
                    'table' => $table,
                    'shard_key' => $key,
                    'connections' => array_keys($entry['connections']),
                ]);

                $split++;

                continue;
            }

            $connection = (string) array_key_first($entry['connections']);

            if (($manager->connectionFor($table, $entry['key'])[0] ?? null) === $connection) {
                continue;
            }

            $redirects[$key] = [$entry['key'], $connection];
        }

        return [$redirects, $split];
    }

    /**
     * Hand each key's routing over, then make the placement it names real.
     *
     * **This is the one step that cannot be undone by re-running.** Everything
     * before it is idempotent: a row already on the shard its key names is
     * skipped, and a destination already holding this row is accepted. But by
     * the time the routing is handed over the source copies are gone, so a
     * `rowMoved()` that throws — a Redis or metadata-database outage during the
     * window — leaves the rows on the new connection and the routing pointing
     * at the old one, and a second run has nothing left to notice.
     *
     * There is no ordering that avoids this. Handing the routing over first
     * makes reads miss rows that have not moved yet; releasing the sources
     * afterwards leaves the row a primary on two connections at once, which a
     * fan-out returns twice. So the routing is handed over last and the
     * failure is made loud instead of silent: each key is retried once, and
     * every key still unredirected is logged with the connection it should
     * name, so the mapping can be replayed by hand. The count comes back as a
     * failure, which stops `afterRebalance()` and raises
     * `RebalanceIncomplete`.
     *
     * Making the placement the routing now names real is a pass of its own,
     * `materialisePlacements()`, and deliberately not part of this loop.
     *
     * @param string $table
     * @param array<string, array{0: mixed, 1: string}> $redirects
     * @param array<string, mixed> $config
     * @return int How many keys were left unredirected.
     */
    protected function handOverRouting(
        string $table,
        array $redirects,
        array $config,
    ): int {
        if (!$this instanceof RowMoveAware) {
            return 0;
        }

        $stranded = 0;

        foreach ($redirects as [$key, $target]) {
            try {
                $this->rowMoved($key, $target, $config);
            } catch (\Throwable $first) {
                try {
                    $this->rowMoved($key, $target, $config);
                } catch (\Throwable $e) {
                    Log::error('Rows moved but their routing was not updated; replay this mapping by hand', [
                        'table' => $table,
                        'shard_key' => $key,
                        'connection' => $target,
                        'exception' => $e,
                    ]);

                    $stranded++;

                    continue;
                }
            }
        }

        return $stranded;
    }

    /**
     * Make the copies of every key match what the routing now says.
     *
     * **A pass of its own, over the range, and not a step inside the
     * redirect loop.** Four separate review findings came out of it being
     * that, and they were all the same mistake: what has to be true afterwards
     * is a property of the data, so checking it against the list of keys this
     * run happened to redirect is checking the wrong thing.
     *
     * Being a pass fixes all four at once. It runs for a strategy that is not
     * `RowMoveAware` — a range strategy chooses the replicas of a moved key
     * inside `afterRebalance()`, and nothing had written them. It runs whether
     * or not a redirect happened, so a rerun repairs a placement that a
     * database error left half-written even though the primary mapping is
     * already correct. And it has somewhere to put a failure, which the loop
     * did not: a collision was logged and the run still reported success.
     *
     * What it makes true, for every primary row in the range: each connection
     * the placement names holds a copy of the row, marked as a replica. An
     * absent copy is written, an identical one left alone, and a **different**
     * row under that identifier left exactly where it is and counted as a
     * failure — it is somebody's data, and the routing is meanwhile
     * advertising it as this row's copy.
     *
     * Copies on connections the placement does not name are not this pass's
     * business: a primary row somewhere the routing does not name is what
     * `redirectsFromWhereRowsAre()` decides about, and it has already refused
     * the run if it could not.
     *
     * @param ShardingManager $manager
     * @param string $table
     * @param string $shardKey
     * @param string $rowKey
     * @param int|null $start
     * @param int|null $end
     * @param int $chunk
     * @return int How many copies could not be placed.
     */
    protected function materialisePlacements(
        ShardingManager $manager,
        string $table,
        string $shardKey,
        string $rowKey,
        ?int $start,
        ?int $end,
        int $chunk,
    ): int {
        $failed = 0;

        foreach (array_keys((array) $manager->connectionsFor($table)) as $connection) {
            $this->walkRows($table, $shardKey, $rowKey, $connection, $start, $end, $chunk, function ($row) use ($manager, $table, $shardKey, $rowKey, &$failed): void {
                $attributes = (array) $row;

                // a table without the column cannot say which copy is the row,
                // so it cannot hold replicas at all
                if (!array_key_exists('is_replica', $attributes)) {
                    return;
                }

                $replicas = array_slice(
                    array_values((array) $manager->connectionFor($table, $row->$shardKey)),
                    1,
                );

                foreach ($replicas as $replica) {
                    $failed += $this->placeReplica($table, $rowKey, $attributes, $row->$rowKey, $replica);
                }
            });
        }

        return $failed;
    }

    /**
     * Put one copy on one connection the placement names.
     *
     * @param string $table
     * @param string $rowKey
     * @param array<string, mixed> $attributes The row as its primary holds it.
     * @param mixed $id
     * @param string $replica
     * @return int 1 when a different row is in the way, 0 otherwise.
     */
    protected function placeReplica(
        string $table,
        string $rowKey,
        array $attributes,
        mixed $id,
        string $replica,
    ): int {
        $occupant = DB::connection($replica)->table($table)->where($rowKey, $id)->first();

        if ($occupant === null) {
            DB::connection($replica)->table($table)->insert(
                array_merge($attributes, ['is_replica' => true]),
            );

            return 0;
        }

        if (!RowComparison::same((array) $occupant, $attributes)) {
            Log::error('A different row holds the identifier on a connection this key names as a replica', [
                'table' => $table,
                'id' => $id,
                'connection' => $replica,
            ]);

            return 1;
        }

        /*
        | An identical occupant is this row's copy and is left alone. It cannot
        | be one claiming to be the primary: two primaries of one key mean two
        | connections hold it, which `redirectsFromWhereRowsAre()` calls a
        | split and refuses before this pass runs. Written down rather than
        | guarded against, because a guard for a state the run cannot be in is
        | code nobody can ever delete.
        */
        return 0;
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
                $page[(string) $row->$rowKey] = (array) $row;

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
     * How many of these rows another connection holds as a *different* row.
     *
     * The identifier alone is not the answer. An interruption between the two
     * commits leaves the same row, byte for byte, as a primary on both
     * connections — the state the move pass accepts and resolves by releasing
     * the source — so counting every shared key refused a rerun of exactly the
     * run that needs one. The payloads decide, the same way they decide
     * everywhere else here.
     *
     * @param string $table
     * @param string $rowKey
     * @param list<string> $others
     * @param array<string, array<string, mixed>> $rows The page, by row key.
     * @return int
     */
    protected function countShared(string $table, string $rowKey, array $others, array $rows): int
    {
        $shared = 0;

        foreach ($others as $other) {
            $found = DB::connection($other)
                ->table($table)
                ->whereIn($rowKey, array_keys($rows))
                ->where(function ($query): void {
                    $query->where('is_replica', false)->orWhereNull('is_replica');
                })
                ->get();

            foreach ($found as $row) {
                $mine = $rows[(string) $row->$rowKey] ?? null;

                if ($mine === null || RowComparison::same((array) $row, $mine)) {
                    continue;
                }

                $shared++;
            }
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
