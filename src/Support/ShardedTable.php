<?php

namespace Allnetru\Sharding\Support;

use Illuminate\Database\Eloquent\Model;

/**
 * One table of a rebalance, with the two columns that decide everything.
 *
 * The shard key is what a slot is computed from, so it decides where a row
 * belongs and what a range bounds. The row key is what identifies one row, so
 * it decides what an insert, an update, a delete and a page of the walk act
 * on. On most tables they are the same column; on every colocated table they
 * are not, and the tables of a group share the *value* of the shard key under
 * different column names — `users.id` and `user_roles.user_id`.
 *
 * A value object rather than three positional strings because the rebalance
 * now takes a list of these, one per table of the group, and a list of
 * triples is where arguments get swapped.
 */
final class ShardedTable
{
    /**
     * @param string $table The table name.
     * @param string $shardKey The column a slot is computed from.
     * @param string $rowKey The column that identifies one row.
     */
    public function __construct(
        public readonly string $table,
        public readonly string $shardKey,
        public readonly string $rowKey,
    ) {
    }

    /**
     * The table a model answers for.
     *
     * A model without the Shardable trait has no shard key of its own, and its
     * primary key stands in — which is the right answer for a table sharded by
     * its own identifier and the wrong one for anything colocated, so callers
     * check the trait before they get here.
     *
     * @param Model $model
     * @return self
     */
    public static function of(Model $model): self
    {
        return new self(
            $model->getTable(),
            method_exists($model, 'getShardKey') ? (string) $model->getShardKey() : $model->getKeyName(),
            $model->getKeyName(),
        );
    }
}
