<?php

namespace Allnetru\Sharding;

use Allnetru\Sharding\Exceptions\UnsupportedCrossShardQuery;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

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
     * @param list<string>|array<string, string> $tables The tables, or table => shard key column.
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
        | The two keys often share a connection: with two shards and one
        | replica each, a key's replica is the other shard, which is where
        | the move is going. A row already sitting there is re-keyed where it
        | is; only the connections that do not have it are written to, and
        | only the ones that keep nothing are deleted from.
        */
        $shared = array_values(array_intersect($sources, $targets));

        return $this->rekey($shared, $keyed, $from, $to, $filter)
            + $this->relocate(
                $sources,
                array_values(array_diff($targets, $sources)),
                array_values(array_diff($sources, $targets)),
                $keyed,
                $from,
                $to,
                $filter,
                counted: $shared === [],
            );
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
        ?callable $filter,
    ): int {
        $moved = 0;

        foreach ($tables as $table => $shardKey) {
            foreach ($connections as $index => $connection) {
                $updated = $this->rowsOf(DB::connection($connection), $table, $shardKey, $from, $filter)
                    ->update([$shardKey => $to]);

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
     * @param callable|null $filter Narrows what moves.
     * @param bool $counted Whether these rows are this move's own count.
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
        ?callable $filter,
        bool $counted,
    ): int {
        if ($arriving === [] && $leaving === []) {
            return 0;
        }

        $primary = DB::connection($sources[0]);
        $copied = [];
        $moved = 0;

        foreach ($tables as $table => $shardKey) {
            $ids = [];

            $this->rowsOf($primary, $table, $shardKey, $from, $filter)
                ->orderBy('id')
                ->chunk(self::CHUNK, function (Collection $rows) use ($arriving, $table, $shardKey, $to, &$ids): void {
                    $values = $rows->map(static function (object $row) use ($shardKey, $to): array {
                        $columns = (array) $row;
                        $columns[$shardKey] = $to;

                        return $columns;
                    })->all();

                    foreach ($arriving as $target) {
                        DB::connection($target)->table($table)->insert($values);
                    }

                    foreach ($rows as $row) {
                        $ids[] = $row->id;
                    }
                });

            $copied[$table] = $ids;
            $moved += count($ids);
        }

        foreach ($copied as $table => $ids) {
            foreach (array_chunk($ids, self::CHUNK) as $chunk) {
                foreach ($leaving as $source) {
                    DB::connection($source)->table($table)->whereIn('id', $chunk)->delete();
                }
            }
        }

        return $counted ? $moved : 0;
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
        $keyed = [];

        foreach ($tables as $table => $shardKey) {
            if (is_int($table)) {
                $keyed[$shardKey] = $ownKey;

                continue;
            }

            $keyed[$table] = $shardKey;
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
