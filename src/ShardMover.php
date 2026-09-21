<?php

namespace Allnetru\Sharding;

use Allnetru\Sharding\Exceptions\UnsupportedCrossShardQuery;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;

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
     * Every table named is moved, and the rows are chosen by the shard key
     * plus whatever else the filter narrows them to: a tenant moving one of
     * its settlements moves the rows of that settlement, not all of them.
     *
     * Within one shard this is an update, because the row is already where
     * it belongs. Across two it is an insert followed by a delete, in that
     * order: a row present twice for an instant is recoverable, a row absent
     * from both is not.
     *
     * @param Model|string $model The model, or the table, whose group is being moved.
     * @param list<string> $tables Which tables to move, in the order they should move.
     * @param mixed $from The key the rows have now.
     * @param mixed $to The key they are moving to.
     * @param (callable(\Illuminate\Database\Query\Builder): \Illuminate\Database\Query\Builder)|null $filter Narrows what moves.
     *
     * @return int How many rows moved.
     *
     * @throws UnsupportedCrossShardQuery When either key names more than one connection.
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

        $shardKey = $this->shardKeyOf($model);
        $sameShard = $this->connectionNameOf($model, $from) === $this->connectionNameOf($model, $to);
        $source = $this->shards->connection($model, $from);
        $target = $this->shards->connection($model, $to);

        $moved = 0;

        foreach ($tables as $table) {
            $moved += $sameShard
                ? $this->moveWithin($source, $table, $shardKey, $from, $to, $filter)
                : $this->moveAcross($source, $target, $table, $shardKey, $from, $to, $filter);
        }

        return $moved;
    }

    /**
     * Move rows that are already on the right connection.
     *
     * @param ConnectionInterface $connection The shard both keys name.
     * @param string $table The table.
     * @param string $shardKey The column the key lives in.
     * @param mixed $from The key the rows have now.
     * @param mixed $to The key they are moving to.
     * @param callable|null $filter Narrows what moves.
     *
     * @return int
     */
    protected function moveWithin(
        ConnectionInterface $connection,
        string $table,
        string $shardKey,
        mixed $from,
        mixed $to,
        ?callable $filter,
    ): int {
        return $this->rowsOf($connection, $table, $shardKey, $from, $filter)
            ->update([$shardKey => $to]);
    }

    /**
     * Copy rows to the other connection, then take them off this one.
     *
     * In chunks, because a settlement's parcels and a tenant's media are
     * thousands of rows and a single insert of all of them is a statement
     * nothing can recover from half-way. Ordered by `id`, which every
     * sharded table has by the shape this package requires of them.
     *
     * @param ConnectionInterface $source Where the rows are.
     * @param ConnectionInterface $target Where they are going.
     * @param string $table The table.
     * @param string $shardKey The column the key lives in.
     * @param mixed $from The key the rows have now.
     * @param mixed $to The key they are moving to.
     * @param callable|null $filter Narrows what moves.
     *
     * @return int
     */
    protected function moveAcross(
        ConnectionInterface $source,
        ConnectionInterface $target,
        string $table,
        string $shardKey,
        mixed $from,
        mixed $to,
        ?callable $filter,
    ): int {
        $moved = 0;

        $this->rowsOf($source, $table, $shardKey, $from, $filter)
            ->orderBy('id')
            ->chunk(self::CHUNK, function (Collection $rows) use ($target, $table, $shardKey, $to, &$moved): void {
                $target->table($table)->insert(
                    $rows->map(static function (object $row) use ($shardKey, $to): array {
                        $values = (array) $row;
                        $values[$shardKey] = $to;

                        return $values;
                    })->all(),
                );

                $moved += $rows->count();
            });

        if ($moved > 0) {
            $this->rowsOf($source, $table, $shardKey, $from, $filter)->delete();
        }

        return $moved;
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
     * The name of the connection a key is written to.
     *
     * The first of them: a key names its primary and then its replicas, and
     * a move reads and writes the primary — the replicas are the package's
     * own business and follow on their own.
     *
     * @param Model|string $model The model or table.
     * @param mixed $key The key.
     *
     * @return string
     *
     * @throws UnsupportedCrossShardQuery When the key names no connection at all.
     */
    protected function connectionNameOf(Model|string $model, mixed $key): string
    {
        $connections = $this->shards->connectionFor($model, $key);

        if ($connections === []) {
            throw new UnsupportedCrossShardQuery(sprintf(
                'A move needs a connection for every key, and %s names none.',
                var_export($key, true),
            ));
        }

        return (string) $connections[0];
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
