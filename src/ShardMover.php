<?php

namespace Allnetru\Sharding;

use Allnetru\Sharding\Exceptions\UnsupportedCrossShardQuery;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Moving everything one shard key owns to another key.
 *
 * A key decides which shard a row lives on, so changing it is not an update:
 * the new key may name a different database, and a row written there has to
 * be read from here first. `ShardBuilder::update()` refuses to change a shard
 * key for exactly that reason, and until now an application that genuinely
 * needed to — a settlement bought by the company that runs it, an account
 * merged into another — had to open both connections and copy rows by hand,
 * which is the knowledge about sharding this package exists to hold.
 *
 * What it does not do is decide whether the move is allowed. Who may claim
 * what belongs to the application; this moves the rows once it has decided.
 */
class ShardMover
{
    /**
     * How many rows are copied at a time.
     *
     * @var int
     */
    protected const CHUNK = 500;

    /**
     * What each table calls its own primary key.
     *
     * @var array<string, string>
     */
    protected array $rowKeys = [];

    /**
     * Which tables record a replica flag.
     *
     * @var array<string, bool>
     */
    protected array $replicaFlags = [];

    /**
     * @param ShardingManager $shards Resolves which connection a key names.
     */
    public function __construct(
        protected ShardingManager $shards,
    ) {
    }

    /**
     * Move the rows a key owns to another key.
     *
     * The tables are given as `table => shard key column`, because one
     * colocation group can key its tables differently: `user_data` owns
     * `users` by `id` and `user_roles` by `user_id`, and applying one
     * model's key to every table of the group would filter the children by
     * a column they do not have — or, worse, by one that means something
     * else. A plain list of tables is accepted too and takes the model's
     * own key, which is the ordinary case.
     *
     * Within one shard this is an update. Across two it is a copy of every
     * table, then a delete of every table — in that order rather than table
     * by table, so a foreign key between two of them never sees a parent
     * gone while its children are still there.
     *
     * Replicas move with the primary. A row written through `Shardable`
     * exists on every connection the key names, so a move that touched only
     * the first would leave copies behind under the old key — which is what
     * a later read of the replica would answer with.
     *
     * @param Model|string $model The model, or the table, whose group is being moved.
     * @param list<string>|array<string, string> $tables The tables; or table => shard key column, or table => model class when the table names its rows something other than `id`.
     * @param mixed $from The key the rows have now.
     * @param mixed $to The key they are moving to.
     * @param (callable(\Illuminate\Database\Query\Builder): \Illuminate\Database\Query\Builder)|null $filter Narrows what moves.
     *
     * @return int How many rows moved.
     *
     * @throws UnsupportedCrossShardQuery When either key names no connection.
     */
    public function move(
        Model|string $model,
        array $tables,
        mixed $from,
        mixed $to,
        ?callable $filter = null,
    ): int {
        if ($from === $to) {
            return 0;
        }

        $keyed = $this->keyedTables($model, $tables);
        $sources = $this->connectionsOf($model, $from);
        $targets = $this->connectionsOf($model, $to);

        /*
        | The two placements often overlap: with two shards and one replica
        | each, a key's replica is the other shard, which is where the move is
        | going. So the rows are copied to the connections that do not hold
        | them, deleted from the ones that keep nothing, and re-keyed on the
        | ones both placements name.
        |
        | Copying first and re-keying last, because the re-key is what makes
        | the source rows unfindable: doing it first left `relocate()` reading
        | a primary whose rows already carried the new key, finding none, and
        | copying nothing — a destination half filled and a source half
        | emptied.
        */
        $shared = array_values(array_intersect($sources, $targets));

        $moved = $this->relocate(
            $sources,
            array_values(array_diff($targets, $sources)),
            array_values(array_diff($sources, $targets)),
            $keyed,
            $from,
            $to,
            $targets,
            $filter,
        );

        $rekeyed = $this->rekey($shared, $keyed, $from, $to, $targets, $filter);

        return $moved > 0 ? $moved : $rekeyed;
    }

    /**
     * Give every row a new key, where it already is.
     *
     * @param list<string> $connections Every connection the key names, replicas included.
     * @param array<string, string> $tables The tables, by their shard key column.
     * @param mixed $from The key the rows have now.
     * @param mixed $to The key they are moving to.
     * @param callable|null $filter Narrows what moves.
     *
     * @return int
     */
    protected function rekey(
        array $connections,
        array $tables,
        mixed $from,
        mixed $to,
        array $targets,
        ?callable $filter,
    ): int {
        $moved = 0;

        foreach ($tables as $table => $shardKey) {
            foreach ($connections as $index => $connection) {
                $values = [$shardKey => $to];

                /*
                | A connection that was the key's primary can be a replica of
                | the new one, and the other way round: the flag says which,
                | and a stale one would present a replica as a second primary.
                | Only where the table keeps the flag — a table replicated by
                | nothing does not carry it.
                */
                if ($this->keepsReplicaFlag($connection, $table)) {
                    $values['is_replica'] = $this->isReplicaOn($connection, $targets);
                }

                $updated = $this->rowsOf(DB::connection($connection), $table, $shardKey, $from, $filter)
                    ->update($values);

                // counted once, on the primary: the replicas hold the same
                // rows and counting them again would report a multiple
                if ($index === 0) {
                    $moved += $updated;
                }
            }
        }

        return $moved;
    }

    /**
     * Whether a table records which of its copies is the replica.
     *
     * Not every sharded table does: one that nothing replicates has no such
     * column, and writing the flag to it fails.
     *
     * @param string $connection Where the table is.
     * @param string $table The table.
     *
     * @return bool
     */
    protected function keepsReplicaFlag(string $connection, string $table): bool
    {
        return $this->replicaFlags[$table] ??= Schema::connection($connection)
            ->hasColumn($table, 'is_replica');
    }

    /**
     * Whether a connection holds a replica under the new placement.
     *
     * @param string $connection The connection.
     * @param list<string> $targets The new placement, primary first.
     *
     * @return bool
     */
    protected function isReplicaOn(string $connection, array $targets): bool
    {
        return ($targets[0] ?? null) !== $connection;
    }

    /**
     * Copy every table to the connections that do not have it, then take the
     * originals off the ones that keep nothing.
     *
     * Every table is copied before anything is deleted, so a foreign key
     * between two of them never sees a parent gone while its children are
     * still there — whatever order the tables are given in.
     *
     * What is deleted is what was copied, by primary key, rather than
     * whatever the filter matches at the end: a row written while the copy
     * was running has not been copied, and deleting it would lose it.
     *
     * @param list<string> $sources Where the rows are, primary first.
     * @param list<string> $arriving The connections that do not hold them yet.
     * @param list<string> $leaving The connections that keep nothing.
     * @param array<string, string> $tables The tables, by their shard key column.
     * @param mixed $from The key the rows have now.
     * @param mixed $to The key they are moving to.
     * @param list<string> $targets The new placement, primary first.
     * @param callable|null $filter Narrows what moves.
     *
     * @return int
     */
    protected function relocate(
        array $sources,
        array $arriving,
        array $leaving,
        array $tables,
        mixed $from,
        mixed $to,
        array $targets,
        ?callable $filter,
    ): int {
        if ($arriving === [] && $leaving === []) {
            return 0;
        }

        $primary = DB::connection($sources[0]);
        $copied = [];
        $moved = 0;

        foreach ($tables as $table => $shardKey) {
            $rowKey = $this->rowKeyOf($table);
            $keys = [];

            $this->rowsOf($primary, $table, $shardKey, $from, $filter)
                ->orderBy($rowKey)
                ->chunk(self::CHUNK, function (Collection $rows) use (
                    $arriving,
                    $table,
                    $shardKey,
                    $to,
                    $targets,
                    $rowKey,
                    &$keys,
                ): void {
                    foreach ($arriving as $target) {
                        DB::connection($target)->table($table)->insert(
                            $rows->map(function (object $row) use ($shardKey, $to, $target, $targets): array {
                                $columns = (array) $row;
                                $columns[$shardKey] = $to;

                                // the copy's place in the new placement, not
                                // the one it had in the old
                                if (array_key_exists('is_replica', $columns)) {
                                    $columns['is_replica'] = $this->isReplicaOn($target, $targets);
                                }

                                return $columns;
                            })->all(),
                        );
                    }

                    foreach ($rows as $row) {
                        $keys[] = $row->{$rowKey};
                    }
                });

            $copied[$table] = [$rowKey, $keys];
            $moved += count($keys);
        }

        /*
        | Backwards, so a child is gone before the parent it points at: the
        | tables are given parent first, which is what the copy needs to
        | satisfy the constraints on the other side, and deleting in that
        | same order would trip a RESTRICT on this one.
        */
        foreach (array_reverse($copied, preserve_keys: true) as $table => [$rowKey, $keys]) {
            foreach (array_chunk($keys, self::CHUNK) as $chunk) {
                foreach ($leaving as $source) {
                    DB::connection($source)->table($table)->whereIn($rowKey, $chunk)->delete();
                }
            }
        }

        return $moved;
    }

    /**
     * The column a table's own rows are identified by.
     *
     * `id` unless a model says otherwise: a shardable model may declare its
     * own key, and ordering a chunked read by a column that is not there
     * fails after earlier tables have already been copied.
     *
     * @param string $table The table.
     *
     * @return string
     */
    protected function rowKeyOf(string $table): string
    {
        return $this->rowKeys[$table] ?? 'id';
    }

    /**
     * The rows of one table that are moving.
     *
     * @param ConnectionInterface $connection Where to look.
     * @param string $table The table.
     * @param string $shardKey The column the key lives in.
     * @param mixed $from The key the rows have now.
     * @param callable|null $filter Narrows what moves.
     *
     * @return \Illuminate\Database\Query\Builder
     */
    protected function rowsOf(
        ConnectionInterface $connection,
        string $table,
        string $shardKey,
        mixed $from,
        ?callable $filter,
    ) {
        $query = $connection->table($table)->where($shardKey, $from);

        return $filter === null ? $query : $filter($query);
    }

    /**
     * The tables to move, each with the column its shard key lives in.
     *
     * @param Model|string $model The model whose key the plain entries take.
     * @param list<string>|array<string, string> $tables As given.
     *
     * @return array<string, string>
     */
    protected function keyedTables(Model|string $model, array $tables): array
    {
        $ownKey = $this->shardKeyOf($model);
        $ownRowKey = $model instanceof Model ? $model->getKeyName() : 'id';
        $keyed = [];

        foreach ($tables as $table => $entry) {
            // a plain list: the model's own columns
            if (is_int($table)) {
                $keyed[$entry] = $ownKey;
                $this->rowKeys[$entry] = $ownRowKey;

                continue;
            }

            /*
            | `table => shard key`, or a model class, which is how a table
            | whose primary key is not `id` says so: one colocation group can
            | key its tables differently, and so can name their rows
            | differently.
            */
            if (is_subclass_of($entry, Model::class)) {
                $instance = new $entry();

                $keyed[$table] = $this->shardKeyOf($instance);
                $this->rowKeys[$table] = $instance->getKeyName();

                continue;
            }

            $keyed[$table] = $entry;
            $this->rowKeys[$table] ??= 'id';
        }

        return $keyed;
    }

    /**
     * Every connection a key names, its primary first.
     *
     * @param Model|string $model The model or table.
     * @param mixed $key The key.
     *
     * @return list<string>
     *
     * @throws UnsupportedCrossShardQuery When the key names none.
     */
    protected function connectionsOf(Model|string $model, mixed $key): array
    {
        $connections = array_values(array_map(
            static fn ($name): string => (string) $name,
            $this->shards->connectionFor($model, $key),
        ));

        if ($connections === []) {
            throw new UnsupportedCrossShardQuery(sprintf(
                'A move needs a connection for every key, and %s names none.',
                var_export($key, true),
            ));
        }

        return $connections;
    }

    /**
     * The column a model is sharded by.
     *
     * @param Model|string $model The model or table.
     *
     * @return string
     */
    protected function shardKeyOf(Model|string $model): string
    {
        if ($model instanceof Model && method_exists($model, 'getShardKey')) {
            return $model->getShardKey();
        }

        return 'tenant_id';
    }
}
