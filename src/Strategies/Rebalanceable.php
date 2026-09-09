<?php

namespace Allnetru\Sharding\Strategies;

use Allnetru\Sharding\Contracts\MetricServiceInterface;
use Allnetru\Sharding\Exceptions\RebalanceIncomplete;
use Allnetru\Sharding\ShardingManager;
use Allnetru\Sharding\Support\RowComparison;
use Allnetru\Sharding\Support\ShardedTable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;

/**
 * Shared logic for moving rows between shard connections.
 *
 * A whole colocation group at once. The routing a rebalance hands over is
 * the group's — `rowMoved()` and `afterRebalance()` write it under the group
 * owner — so moving one table's rows and redirecting the key sends every
 * sibling table's reads to the new connection while their rows are still on
 * the old one. Nothing is lost and nothing reaches it either. So the tables
 * come as a list, every pass below runs across all of them, and the routing
 * changes once, after every table's rows have arrived.
 *
 * Two keys per table, and they are not interchangeable. The shard key is
 * what a slot is computed from, so it is what the range filter and the
 * routing use. The row key is what identifies one row, so it is what the
 * insert, the update, the delete and the paging use. On a colocated
 * one-to-many table — several `user_roles` for one `user_id` — using the shard
 * key for identity means `where('user_id', …)->delete()` after inserting a
 * single row, which deletes every other role that user had. Routing by the
 * wrong key misplaces rows; identifying by the wrong key destroys them.
 *
 * Several passes, and the first ones write nothing. A rebalance is not one
 * operation but two that have to agree: rows move, and the metadata saying
 * where a key lives moves with them. Doing the second while the first only
 * partly happened is what makes rows unreachable — and either direction does
 * it. Hand the routing over and the rows left behind are stranded; withhold it
 * and the rows already moved are stranded, because the old routing still
 * points at a connection they have left. There is no way to choose correctly
 * after the fact, so the choice is removed: everything this run can predict
 * is checked before a single row moves, and the whole run is refused if any of
 * it fails.
 *
 * What cannot be predicted is a connection dropping halfway. Then rows have
 * moved, the routing is deliberately not advanced, and `RebalanceIncomplete`
 * says so. Re-running finishes the job: every pass reads the state off the
 * data rather than off a memory of what this run did, so a rerun redirects
 * what needs redirecting and writes what is missing, whoever moved the rows
 * and whenever. A rebalance is run with `SHARDING_PIN_BY_KEY=false`, which
 * is what makes that window safe — an unpinned read finds a row wherever it
 * currently is.
 */
trait Rebalanceable
{
    /**
     * Move the rows of a colocation group between shard connections.
     *
     * @param list<ShardedTable> $tables The tables to move, one per table of the group.
     * @param string|null $from Read from this connection only.
     * @param string|null $to Send every row here, overriding the routing.
     * @param int|null $start The lower bound of the shard key, inclusive.
     * @param int|null $end The upper bound of the shard key, inclusive.
     * @param array $config The group owner's configuration.
     * @return int number of moved records
     */
    public function rebalance(
        array $tables,
        ?string $from,
        ?string $to,
        ?int $start,
        ?int $end,
        array $config
    ): int {
        if ($tables === []) {
            throw new InvalidArgumentException('Nothing to rebalance: no tables were given.');
        }

        $this->refuseAnUnexpressibleTarget($to, $start, $end);

        /** @var ShardingManager $manager */
        $manager = app(ShardingManager::class);

        // the scope the routing lives under: the group owner's table
        $scope = (string) ($config['table'] ?? $tables[0]->table);
        $all = $this->scannable($manager, $tables[0]->table);

        /*
        | An explicit `--from` overrides the migration exclusion rather than
        | being filtered by it: naming a connection listed in
        | DB_SHARD_MIGRATIONS is how an operator drains one that is being taken
        | back out.
        */
        $walked = $from !== null ? [$from] : $all;
        $everywhere = array_values(array_unique([...$all, ...$walked]));
        $chunk = 1000;

        $refused = 0;

        foreach ($tables as $table) {
            $refused += $this->occupiedTargets($manager, $table, $walked, $to, $start, $end, $chunk);
            $refused += $this->sharedRowKeys($table, $walked, $start, $end, $chunk);
        }

        $refused += $this->keysLeftBehind($manager, $tables, $everywhere, $walked, $to, $start, $end, $chunk);

        if ($refused > 0) {
            throw new RebalanceIncomplete($scope, 0, $refused);
        }

        $moved = 0;
        $failed = 0;

        foreach ($tables as $table) {
            [$arrived, $left] = $this->moveRows($manager, $table, $walked, $to, $start, $end, $chunk, $config);

            $moved += $arrived;
            $failed += $left;
        }

        /*
        | The metadata steps, and only when everything arrived. A key redirected
        | while one of its rows is still on the old connection, or a range
        | handed over while any row is, is what makes rows unreachable.
        */
        if ($failed === 0) {
            [$redirects, $split] = $this->redirectsFromWhereRowsAre($manager, $tables, $everywhere, $start, $end, $chunk);

            $failed += $split;

            if ($failed === 0) {
                $failed += $this->handOverRouting($scope, $redirects, $config);
            }

            if ($failed === 0 && $this instanceof SupportsAfterRebalance) {
                $this->afterRebalance($scope, $tables[0]->shardKey, $from, $to, $start, $end, $config);
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
                $fresh = $this->freshManager();

                foreach ($tables as $table) {
                    $failed += $this->materialisePlacements($fresh, $table, $everywhere, $start, $end, $chunk);
                }
            }
        }

        Log::info('Rebalance completed', [
            'scope' => $scope,
            'tables' => array_map(static fn (ShardedTable $table): string => $table->table, $tables),
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
            throw new RebalanceIncomplete($scope, $moved, $failed);
        }

        return $moved;
    }

    /**
     * Refuse an explicit target this strategy has no way to hand over.
     *
     * `--to` is a routing change, and only a strategy that can express one may
     * take it. A row-aware strategy redirects each key; a range strategy hands
     * a range over — so it needs both bounds, because a range with neither is
     * a catch-all that, put in front of the others, sends every key of the
     * table to the target, including every key that never moved. A strategy
     * that can do neither would move the rows and leave the routing pointing
     * at where they were: unreachable, and nothing this run could do about it.
     *
     * @param string|null $to
     * @param int|null $start
     * @param int|null $end
     * @return void
     */
    protected function refuseAnUnexpressibleTarget(?string $to, ?int $start, ?int $end): void
    {
        /*
        | Asked at runtime rather than with `instanceof`, because a trait is
        | analysed once per class that uses it: for a row-aware strategy the
        | first test is constant, which makes everything after it dead code in
        | that class — and the analyser rightly says so.
        */
        $contracts = class_implements($this) ?: [];

        if ($to === null || in_array(RowMoveAware::class, $contracts, true)) {
            return;
        }

        if (!in_array(SupportsAfterRebalance::class, $contracts, true)) {
            throw new InvalidArgumentException(
                static::class . ' cannot take an explicit target: it has no way to hand the routing over, so the '
                . 'rows would move and the routing would go on naming the connection they left.',
            );
        }

        if ($start === null || $end === null) {
            throw new InvalidArgumentException(
                static::class . ' hands routing over as a range, so an explicit target needs both --start and '
                . '--end. Without them the range is a catch-all and every key of the table would follow it.',
            );
        }
    }

    /**
     * How many rows of one table would land on a different row.
     *
     * The first of the checks that run before anything moves: a destination
     * already holding a different row under this identifier. That is what
     * adopting sharding over databases that counted their own identifiers
     * looks like, and both rows are real data.
     *
     * @param ShardingManager $manager
     * @param ShardedTable $table
     * @param list<string> $walked
     * @param string|null $to
     * @param int|null $start
     * @param int|null $end
     * @param int $chunk
     * @return int
     */
    protected function occupiedTargets(
        ShardingManager $manager,
        ShardedTable $table,
        array $walked,
        ?string $to,
        ?int $start,
        ?int $end,
        int $chunk,
    ): int {
        $clashes = 0;

        foreach ($walked as $connection) {
            $this->walkRows($table, $connection, $start, $end, $chunk, function (object $row) use ($manager, $table, $to, $connection, &$clashes): void {
                $target = $this->placementFor($manager, $table, $to, $row)[0];

                if ($target === $connection) {
                    return;
                }

                $existing = DB::connection($target)->table($table->table)->where($table->rowKey, $row->{$table->rowKey})->first();

                if ($existing === null || RowComparison::same((array) $existing, (array) $row)) {
                    return;
                }

                Log::error('Refused to move a row onto a different row with the same key', [
                    'table' => $table->table,
                    'id' => $row->{$table->rowKey},
                    'from' => $connection,
                    'to' => $target,
                ]);

                $clashes++;
            });
        }

        return $clashes;
    }

    /**
     * Move one table's rows, each in its own pair of transactions.
     *
     * @param ShardingManager $manager
     * @param ShardedTable $table
     * @param list<string> $walked
     * @param string|null $to
     * @param int|null $start
     * @param int|null $end
     * @param int $chunk
     * @param array<string, mixed> $config
     * @return array{0: int, 1: int} How many arrived, and how many did not.
     */
    protected function moveRows(
        ShardingManager $manager,
        ShardedTable $table,
        array $walked,
        ?string $to,
        ?int $start,
        ?int $end,
        int $chunk,
        array $config,
    ): array {
        $moved = 0;
        $failed = 0;

        foreach ($walked as $connection) {
            $this->walkRows($table, $connection, $start, $end, $chunk, function (object $row) use ($manager, $table, $connection, $to, $config, &$moved, &$failed): void {
                $placement = $this->placementFor($manager, $table, $to, $row);
                $target = $placement[0];

                if ($target === $connection) {
                    return;
                }

                $id = $row->{$table->rowKey};
                $targetConn = DB::connection($target);
                $sourceConn = DB::connection($connection);

                $targetConn->beginTransaction();
                $sourceConn->beginTransaction();

                try {
                    $existing = $targetConn->table($table->table)->where($table->rowKey, $id)->first();

                    /*
                    | The preflight looked at a target that was empty at the
                    | time, and an earlier row of this same run may have filled
                    | it since. So the comparison is repeated here, where the
                    | occupant is whatever is actually there.
                    */
                    if ($existing && !RowComparison::same((array) $existing, (array) $row)) {
                        $targetConn->rollBack();
                        $sourceConn->rollBack();

                        Log::error('Refused to move a row onto a different row with the same key', [
                            'table' => $table->table,
                            'id' => $id,
                            'from' => $connection,
                            'to' => $target,
                        ]);

                        $failed++;

                        return;
                    }

                    if ($existing) {
                        $targetConn->table($table->table)->where($table->rowKey, $id)->update(array_merge((array) $row, ['is_replica' => false]));
                    } else {
                        $targetConn->table($table->table)->insert((array) $row);
                    }

                    /*
                    | The copy left behind is kept only when this key's
                    | replicas belong on that connection. Marking it a replica
                    | anywhere else leaves a copy nothing advertises, hidden by
                    | the `is_replica = false` scope: a duplicate that nothing
                    | can see and nothing rebuilds.
                    */
                    if (in_array($connection, $this->copiesToKeep($manager, $table, $to, $row, $placement, $config), true)) {
                        $sourceConn->table($table->table)->where($table->rowKey, $id)->update(['is_replica' => true]);
                    } else {
                        $sourceConn->table($table->table)->where($table->rowKey, $id)->delete();
                    }

                    $targetConn->commit();
                    $sourceConn->commit();

                    $moved++;
                } catch (\Throwable $e) {
                    $targetConn->rollBack();
                    $sourceConn->rollBack();

                    Log::error('Failed to move row during rebalance', [
                        'table' => $table->table,
                        'id' => $id,
                        'shard_key' => $row->{$table->shardKey},
                        'from' => $connection,
                        'to' => $target,
                        'exception' => $e,
                    ]);

                    $failed++;
                }
            });
        }

        return [$moved, $failed];
    }

    /**
     * The connections worth reading rows of this group from.
     *
     * `connectionsFor()` answers with everything configured, while
     * `connectionFor()` — the one that routes — leaves out anything listed in
     * `DB_SHARD_MIGRATIONS`. The difference matters here because a shard being
     * added is in that list precisely because it is not ready: its copy of the
     * table may not exist yet, and a scan across the whole topology then died
     * on an unknown table before anything had moved.
     *
     * Only those. An active connection whose table is absent is not
     * skipped, because skipping it does not stop `connectionFor()` naming it
     * as a destination — the rows would move, the routing would be handed
     * over, and the placement pass would then die on the missing table with
     * the metadata already advertising a replica that cannot exist. That is a
     * schema the run must not be attempted against, and `shards:rebalance`
     * refuses it before starting. A caller reaching the trait directly gets
     * the driver's own error, which is loud if inelegant.
     *
     * @param ShardingManager $manager
     * @param string $table Any table of the group; the manager resolves the owner.
     * @return list<string>
     */
    protected function scannable(ShardingManager $manager, string $table): array
    {
        $migrating = (array) config('sharding.migrations', []);
        $connections = [];

        foreach (array_keys((array) $manager->connectionsFor($table)) as $connection) {
            if (array_key_exists($connection, $migrating)) {
                continue;
            }

            $connections[] = (string) $connection;
        }

        return $connections;
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
     * The split the primary-key preflight cannot see. That one asks
     * whether two connections hold the same *row*; this asks whether they hold
     * the same *key*, which on a colocated group they can do with entirely
     * different rows — a user on one connection and one of their roles on
     * another. With `--from` naming one connection, the rows there move to the
     * target and the rows elsewhere stay, so the key ends up spread across two
     * connections, its routing can name only one of them, and the other half
     * stops being found. Detected afterwards, that is unrecoverable: the move
     * has happened and rerunning finds the same split.
     *
     * The condition is precise, because a looser one refuses runs it has no
     * business refusing. Only a key this run will touch counts — one with rows
     * on a connection being walked; a key sitting misplaced somewhere else
     * entirely is not this run's problem and is left exactly as it was. Of the
     * keys touched, rows on a connection this run will walk are fine (they all
     * go to the same target and arrive together), and rows on the target
     * already are fine (that is what an interrupted run leaves, and finishing
     * it is the documented recovery). What is refused is rows on a connection
     * that will neither be walked nor be the destination.
     *
     * @param ShardingManager $manager
     * @param list<ShardedTable> $tables
     * @param list<string> $everywhere Every connection the group's rows may sit on.
     * @param list<string> $walked The connections this run reads.
     * @param string|null $to
     * @param int|null $start
     * @param int|null $end
     * @param int $chunk
     * @return int
     */
    protected function keysLeftBehind(
        ShardingManager $manager,
        array $tables,
        array $everywhere,
        array $walked,
        ?string $to,
        ?int $start,
        ?int $end,
        int $chunk,
    ): int {
        if (array_diff($everywhere, $walked) === []) {
            return 0;
        }

        $left = 0;

        foreach ($this->primariesByKey($tables, $everywhere, $start, $end, $chunk) as $entry) {
            $on = array_keys($entry['connections']);

            if (array_intersect($on, $walked) === []) {
                continue;
            }

            $target = $to ?? ($manager->connectionFor($tables[0]->table, $entry['key'])[0] ?? null);
            $stranded = array_values(array_diff($on, $walked, [$target]));

            if ($stranded === []) {
                continue;
            }

            Log::error('Refused a rebalance: this run would move only part of a key', [
                'shard_key' => $entry['key'],
                'left_on' => $stranded,
                'walking' => $walked,
                'target' => $target,
            ]);

            $left++;
        }

        return $left;
    }

    /**
     * Where each key's primary rows are, across every table of the group.
     *
     * @param list<ShardedTable> $tables
     * @param list<string> $connections
     * @param int|null $start
     * @param int|null $end
     * @param int $chunk
     * @return array<string, array{key: mixed, connections: array<string, true>}>
     */
    protected function primariesByKey(
        array $tables,
        array $connections,
        ?int $start,
        ?int $end,
        int $chunk,
    ): array {
        $where = [];

        foreach ($tables as $table) {
            foreach ($connections as $connection) {
                $this->walkRows($table, $connection, $start, $end, $chunk, function (object $row) use ($table, $connection, &$where): void {
                    $key = (string) $row->{$table->shardKey};

                    $where[$key]['key'] = $row->{$table->shardKey};
                    $where[$key]['connections'][$connection] = true;
                });
            }
        }

        return $where;
    }

    /**
     * Which keys the routing is wrong about, read off the data itself.
     *
     * Derived rather than remembered, and that is what makes a rerun finish
     * the job. A record of what this run moved is a record of what happened
     * rather than of what is true: a transient failure partway through the
     * move, or a `rowMoved()` that threw, leaves rows on the new connection
     * with the routing naming the old one — and a rerun, seeing nothing left
     * to move on the source, would have an empty set and report success over
     * keys still pointing at the wrong shard.
     *
     * Read off the data there is no such gap. Every connection is walked, not
     * only the source: a key whose rows sit somewhere the routing does not
     * name needs redirecting, whoever moved them and whenever. The data cannot
     * be wrong about where it is, so pointing the routing at it is always an
     * improvement — a side effect an operator may not have asked for, and one
     * that only ever makes rows reachable.
     *
     * A key whose rows are spread over more than one connection is not
     * decided: it is counted as a failure and named in the log, because
     * choosing either connection would strand the rows on the other.
     * `keysLeftBehind()` refuses a run that would create that state, so
     * reaching this branch means something outside this run did; it stays
     * because the alternative is choosing silently.
     *
     * @param ShardingManager $manager
     * @param list<ShardedTable> $tables
     * @param list<string> $connections
     * @param int|null $start
     * @param int|null $end
     * @param int $chunk
     * @return array{0: array<string, array{0: mixed, 1: string}>, 1: int}
     */
    protected function redirectsFromWhereRowsAre(
        ShardingManager $manager,
        array $tables,
        array $connections,
        ?int $start,
        ?int $end,
        int $chunk,
    ): array {
        $redirects = [];
        $split = 0;

        foreach ($this->primariesByKey($tables, $connections, $start, $end, $chunk) as $key => $entry) {
            if (count($entry['connections']) > 1) {
                Log::error('A key has rows on more than one connection, so its routing cannot be decided', [
                    'shard_key' => $key,
                    'connections' => array_keys($entry['connections']),
                ]);

                $split++;

                continue;
            }

            $connection = (string) array_key_first($entry['connections']);

            if (($manager->connectionFor($tables[0]->table, $entry['key'])[0] ?? null) === $connection) {
                continue;
            }

            $redirects[$key] = [$entry['key'], $connection];
        }

        return [$redirects, $split];
    }

    /**
     * Hand each key's routing over.
     *
     * This is the one step that cannot be undone by re-running, only
     * finished. By the time it runs the source copies are gone, so a
     * `rowMoved()` that throws — a Redis or metadata-database outage in the
     * window — leaves the rows on the new connection and the routing pointing
     * at the old one. There is no ordering that avoids it: handing the routing
     * over first makes reads miss rows that have not moved yet, releasing the
     * sources afterwards leaves the row a primary on two connections at once
     * and a fan-out returns it twice. So the routing is handed over last, each
     * key is retried once, every key still unredirected is logged with the
     * connection it should name, and the count comes back as a failure — which
     * stops `afterRebalance()` and raises `RebalanceIncomplete`. The rerun then
     * finds the same redirects again, because they are read off the data.
     *
     * @param string $scope The group owner's table, for the log.
     * @param array<string, array{0: mixed, 1: string}> $redirects
     * @param array<string, mixed> $config
     * @return int How many keys were left unredirected.
     */
    protected function handOverRouting(string $scope, array $redirects, array $config): int
    {
        if (!$this instanceof RowMoveAware) {
            return 0;
        }

        $stranded = 0;

        foreach ($redirects as [$key, $target]) {
            try {
                $this->rowMoved($key, $target, $config);
            } catch (\Throwable) {
                try {
                    $this->rowMoved($key, $target, $config);
                } catch (\Throwable $e) {
                    Log::error('Rows moved but their routing was not updated; run the rebalance again', [
                        'scope' => $scope,
                        'shard_key' => $key,
                        'connection' => $target,
                        'exception' => $e,
                    ]);

                    $stranded++;
                }
            }
        }

        return $stranded;
    }

    /**
     * Make the copies of every key of one table match what the routing says.
     *
     * A pass over the range, not a step tied to what this run moved. What
     * has to be true afterwards is a property of the data, so it is checked
     * against the data: for every primary row in the range, each connection
     * the placement names holds a copy marked as a replica. An absent copy is
     * written, an identical one left alone, and a different row under that
     * identifier left exactly where it is and counted as a failure — it is
     * somebody's data, and the routing is meanwhile advertising it as this
     * row's copy.
     *
     * Being a pass is what makes it work for a range strategy, which chooses
     * its replicas inside `afterRebalance()` and is not row-aware, and what
     * makes a rerun repair a placement half-written by a database error even
     * though the primary mapping is already correct.
     *
     * The placement is asked once per key and remembered for the rows behind
     * it: on a colocated table one key covers many rows, and a strategy that
     * keeps its routing in a table answered the same question once per row.
     *
     * @param ShardingManager $manager A manager that has read the routing as it is now.
     * @param ShardedTable $table
     * @param list<string> $connections
     * @param int|null $start
     * @param int|null $end
     * @param int $chunk
     * @return int How many copies could not be placed.
     */
    protected function materialisePlacements(
        ShardingManager $manager,
        ShardedTable $table,
        array $connections,
        ?int $start,
        ?int $end,
        int $chunk,
    ): int {
        $failed = 0;
        $placements = [];

        foreach ($connections as $connection) {
            $this->walkRows($table, $connection, $start, $end, $chunk, function (object $row) use ($manager, $table, &$failed, &$placements): void {
                $attributes = (array) $row;

                // a table without the column cannot say which copy is the row,
                // so it cannot hold replicas at all
                if (!array_key_exists('is_replica', $attributes)) {
                    return;
                }

                $key = (string) $row->{$table->shardKey};
                $placements[$key] ??= array_values((array) $manager->connectionFor($table->table, $row->{$table->shardKey}));

                foreach (array_slice($placements[$key], 1) as $replica) {
                    $failed += $this->placeReplica($table, $attributes, $row->{$table->rowKey}, $replica);
                }
            });
        }

        return $failed;
    }

    /**
     * Put one copy on one connection the placement names.
     *
     * An identical occupant is this row's copy and is left alone. It cannot be
     * one claiming to be the primary: two primaries of one key mean two
     * connections hold it, which `redirectsFromWhereRowsAre()` calls a split
     * and refuses before this pass runs.
     *
     * @param ShardedTable $table
     * @param array<string, mixed> $attributes The row as its primary holds it.
     * @param mixed $id
     * @param string $replica
     * @return int 1 when a different row is in the way, 0 otherwise.
     */
    protected function placeReplica(ShardedTable $table, array $attributes, mixed $id, string $replica): int
    {
        $occupant = DB::connection($replica)->table($table->table)->where($table->rowKey, $id)->first();

        if ($occupant === null) {
            DB::connection($replica)->table($table->table)->insert(array_merge($attributes, ['is_replica' => true]));

            return 0;
        }

        if (RowComparison::same((array) $occupant, $attributes)) {
            return 0;
        }

        Log::error('A different row holds the identifier on a connection this key names as a replica', [
            'table' => $table->table,
            'id' => $id,
            'connection' => $replica,
        ]);

        return 1;
    }

    /**
     * How many rows of one table two source connections both hold as different rows.
     *
     * The occupancy preflight asks whether a destination is occupied, which
     * cannot see a clash created *during* the run: with several source
     * connections being swept, two of them can hold different rows under one
     * identifier that both route to a target empty when it was looked at. The
     * first row lands, the second finds it, and without a comparison the first
     * is lost.
     *
     * The identifier alone is not the answer. An interruption between the two
     * commits leaves the same row, byte for byte, as a primary on both
     * connections — the state the move pass accepts and resolves by releasing
     * the source — so counting every shared key refused a rerun of exactly the
     * run that needs one. The payloads decide. Replicas are out of it on both
     * sides, because a replica sharing its primary's key on another connection
     * is the normal state of a replicated row.
     *
     * Checked by intersecting pages rather than by holding every planned key,
     * so this costs a query per page and a page of memory instead of growing
     * with the size of the run.
     *
     * @param ShardedTable $table
     * @param list<string> $connections
     * @param int|null $start
     * @param int|null $end
     * @param int $chunk
     * @return int
     */
    protected function sharedRowKeys(ShardedTable $table, array $connections, ?int $start, ?int $end, int $chunk): int
    {
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

            $this->walkRows($table, $connection, $start, $end, $chunk, function (object $row) use ($table, $others, $chunk, &$page, &$shared): void {
                $page[(string) $row->{$table->rowKey}] = (array) $row;

                if (count($page) >= $chunk) {
                    $shared += $this->countShared($table, $others, $page);
                    $page = [];
                }
            });

            if ($page !== []) {
                $shared += $this->countShared($table, $others, $page);
            }
        }

        if ($shared > 0) {
            Log::error('Refused a rebalance: one primary key is held by more than one source connection', [
                'table' => $table->table,
                'keys' => $shared,
            ]);
        }

        return $shared;
    }

    /**
     * How many of these rows another connection holds as a *different* row.
     *
     * @param ShardedTable $table
     * @param list<string> $others
     * @param array<string, array<string, mixed>> $rows The page, by row key.
     * @return int
     */
    protected function countShared(ShardedTable $table, array $others, array $rows): int
    {
        $shared = 0;

        foreach ($others as $other) {
            $found = DB::connection($other)
                ->table($table->table)
                ->whereIn($table->rowKey, array_keys($rows))
                ->where(function ($query): void {
                    $query->where('is_replica', false)->orWhereNull('is_replica');
                })
                ->get();

            foreach ($found as $row) {
                $mine = $rows[(string) $row->{$table->rowKey}] ?? null;

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
     * With `--to` it cannot be, and deriving it from that one-element array
     * deleted a copy the metadata still advertised. The operator names the
     * primary; the strategy decides what the replicas become, and both
     * `RedisStrategy` and `DbHashRangeStrategy` promote the old primary into
     * the replica list when the target used to be one of its replicas. So the
     * connections the key currently names are kept, minus the target, capped
     * by `replica_count` — which reproduces that promotion and, with no
     * replicas configured, keeps nothing. Whatever the strategy then decides
     * the replicas are, `materialisePlacements()` writes.
     *
     * @param ShardingManager $manager
     * @param ShardedTable $table
     * @param string|null $to
     * @param object $row
     * @param list<string> $placement The placement this move used.
     * @param array<string, mixed> $config
     * @return list<string>
     */
    protected function copiesToKeep(
        ShardingManager $manager,
        ShardedTable $table,
        ?string $to,
        object $row,
        array $placement,
        array $config,
    ): array {
        if ($to === null) {
            return array_slice($placement, 1);
        }

        $current = $this->placementFor($manager, $table, null, $row);

        return array_slice(
            array_values(array_diff($current, [$to])),
            0,
            (int) ($config['replica_count'] ?? 0),
        );
    }

    /**
     * Walk one connection's primary rows of one table in the range.
     *
     * Paged by the row key rather than by offset, because the rows being
     * deleted underneath the walk are the ones it is walking: an offset would
     * skip as many rows as it moved.
     *
     * Replica copies are not visited. A replica belongs on a replica
     * connection rather than on the primary its key names, so the rule this
     * walk applies is not its rule — `shards:distribute` skips them for the
     * same reason. The consequence worth knowing: retiring a connection that
     * holds replicas leaves them behind, so those keys carry fewer copies than
     * are configured until `materialisePlacements()` on a later run puts them
     * back.
     *
     * @param ShardedTable $table
     * @param string $connection
     * @param int|null $start
     * @param int|null $end
     * @param int $chunk
     * @param callable(object): void $each
     * @return void
     */
    protected function walkRows(
        ShardedTable $table,
        string $connection,
        ?int $start,
        ?int $end,
        int $chunk,
        callable $each,
    ): void {
        $query = DB::connection($connection)->table($table->table);

        // the range is over the shard key: a slot is a range of it
        if ($start !== null) {
            $query->where($table->shardKey, '>=', $start);
        }

        if ($end !== null) {
            $query->where($table->shardKey, '<=', $end);
        }

        $query->chunkById($chunk, function ($rows) use ($each): void {
            foreach ($rows as $row) {
                if (!empty($row->is_replica)) {
                    continue;
                }

                $each($row);
            }
        }, $table->rowKey);
    }

    /**
     * The connections a row belongs on, the primary first.
     *
     * @param ShardingManager $manager
     * @param ShardedTable $table
     * @param string|null $to An explicit destination, which overrides the routing.
     * @param object $row
     * @return list<string>
     */
    protected function placementFor(ShardingManager $manager, ShardedTable $table, ?string $to, object $row): array
    {
        return $to !== null
            ? [$to]
            : array_values((array) $manager->connectionFor($table->table, $row->{$table->shardKey}));
    }
}
