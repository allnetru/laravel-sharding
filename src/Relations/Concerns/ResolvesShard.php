<?php

namespace Allnetru\Sharding\Relations\Concerns;

use Allnetru\Sharding\ShardBuilder;
use Allnetru\Sharding\ShardingManager;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasOneOrManyThrough;
use Illuminate\Support\Str;

/**
 * @internal
 */
trait ResolvesShard
{
    /**
     * Pin the relation's query to the shard the related rows live on.
     *
     * The shard of a table is decided by that table's own shard key, and this
     * is the one place that gets to say what its value is. Relations used to
     * pass whichever key joined the two rows, which is the same thing only
     * when a table is sharded by its own primary key — under colocation it is
     * a different column, and the query went to a shard chosen by an unrelated
     * number.
     *
     * When the value cannot be known the connection is left alone on purpose,
     * and ShardBuilder fans the query out across every shard. That is slower
     * and always right; guessing is faster and sometimes wrong.
     *
     * @param Model $source the row the relation is being resolved from
     * @param string|null $relatedColumn the column of the related table this
     *                                   relation constrains, or null when it
     *                                   constrains none directly
     * @param mixed $relatedValue the value that column is constrained to
     * @return void
     */
    protected function resolveShardConnection(Model $source, ?string $relatedColumn, mixed $relatedValue): void
    {
        $manager = app(ShardingManager::class);

        // a relation from a sharded model can point at a global table:
        // reference data lives on the default connection. Routing such a query
        // to a shard sends it where the table does not exist, and the failure
        // is confusing rather than obvious, because the strategy silently
        // treats the unknown table as shardable and looks for its slots.
        if (!$manager->isShardable($this->related)) {
            return;
        }

        $key = $this->shardKeyValue($source, $relatedColumn, $relatedValue);

        if ($key === null) {
            return;
        }

        $connection = $manager->connectionFor($this->related, $key)[0];

        if ($this instanceof HasOneOrManyThrough) {
            $this->throughParent->setConnection($connection);
        }

        $query = $this->query;

        if ($query instanceof ShardBuilder) {
            // setting the model's connection is not enough on its own:
            // ShardBuilder fans out unless it is told it has one shard, so
            // without this the connection is chosen and then ignored
            $query->onShardConnection($connection);

            return;
        }

        $query->getModel()->setConnection($connection);
        $query->getQuery()->connection = $query->getModel()->getConnection();
    }

    /**
     * The value of the related table's shard key, when it can be known.
     *
     * Two shapes of colocation reach this, and both are in the README:
     *
     * - the child declares the parent's key as its shard key, `user_profiles`
     *   sharded by `user_id`. The relation constrains that very column, so the
     *   value it is constrained to is the shard key's value;
     * - parent and child share a column, both sharded by `tenant_id`. The
     *   relation constrains neither, but the source row carries the column.
     *
     * Anything else is unknowable from here. A table sharded by its own
     * primary key cannot be located from a parent that only knows a foreign
     * key pointing the other way — and the source's own `id` is its identity,
     * not the related row's, which is exactly the substitution that made this
     * wrong before.
     *
     * **The second shape carries a precondition, and it is the precondition
     * colocation already is:** the two rows must share the shard key's *value*,
     * not merely the column's name. A foreign key from one tenant's row to
     * another tenant's row is outside the design — the rows are on different
     * shards by construction — and this pins the query to the source's shard,
     * so the target is not found. Before v0.3.6 the fan-out masked that and
     * answered anyway; it no longer does, and there is no way to tell the two
     * cases apart from here, because both models are in one group and both
     * name the same shard key. `ShardColocatedRelationsTest` pins the
     * behaviour so it reads as decided rather than as an accident. A relation
     * that has to cross shard-key values cannot be colocated, and belongs on a
     * table that is not.
     *
     * @param Model $source the row the relation is being resolved from
     * @param string|null $relatedColumn the column of the related table this relation constrains
     * @param mixed $relatedValue the value that column is constrained to
     * @return mixed null when the shard cannot be determined
     */
    protected function shardKeyValue(Model $source, ?string $relatedColumn, mixed $relatedValue): mixed
    {
        $related = $this->related;

        // isShardable() reads the trait, which static analysis cannot see, so
        // the method is checked rather than the type asserted
        if (!method_exists($related, 'getShardKey')) {
            return null;
        }

        $shardKey = $related->getShardKey();

        // the last segment, because a relation may be declared with a
        // qualified key — belongsTo(X::class, 'x_id', 'xs.id') — and comparing
        // 'xs.id' against 'id' would fail silently and fall through to the
        // shared-column branch, which is the one with a precondition. The
        // has-relations already pass an unqualified name; normalising here
        // covers the ones that do not
        $relatedColumn = $relatedColumn === null
            ? null
            : Str::afterLast($relatedColumn, '.');

        if ($relatedColumn === $shardKey) {
            return $relatedValue;
        }

        if ($shardKey !== $related->getKeyName()) {
            return $source->getAttribute($shardKey);
        }

        return null;
    }
}
