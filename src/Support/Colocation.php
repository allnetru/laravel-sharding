<?php

namespace Allnetru\Sharding\Support;

use Allnetru\Sharding\ShardingManager;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasOneOrMany;
use Illuminate\Database\Eloquent\Relations\HasOneOrManyThrough;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Str;

/**
 * Whether a relation's rows are on the shard its parent's row is on.
 *
 * The question exists because of one compilation detail: `whereHas` and
 * `withCount` become a correlated subquery, and a subquery runs on the
 * connection the outer query runs on. When the related rows are on that same
 * shard the answer is right — which is the whole point of colocation. When
 * they are not, the subquery sees the fraction of them that happens to share
 * the shard, and says nothing about the rest.
 *
 * The two shapes recognised here are the two shapes colocation takes, and are
 * the same ones ResolvesShard names for a single row. The difference is that
 * this has to answer for a whole table at once, so it may only use what the
 * models declare — never a value from a row.
 */
class Colocation
{
    public function __construct(protected ShardingManager $manager)
    {
    }

    /**
     * Are the related rows guaranteed to sit on the parent's shard?
     *
     * @param Relation<*, *, *> $relation
     * @return bool
     */
    public function holds(Relation $relation): bool
    {
        $parent = $relation->getParent();
        $related = $relation->getRelated();

        // a relation to a table that is not sharded is not this class's
        // problem: the table lives on one connection, and a subquery that
        // cannot reach it fails loudly rather than answering half
        if (!$this->manager->isShardable($related) || !$this->manager->isShardable($parent)) {
            return true;
        }

        if (!method_exists($parent, 'getShardKey') || !method_exists($related, 'getShardKey')) {
            return true;
        }

        // both sides have to resolve a key through the same map, or the same
        // value lands on different shards and nothing below holds. A group is
        // the declaration of exactly that. Outside one only a table relating
        // to itself qualifies: a strategy that keeps its routing in metadata
        // keeps it per table, so two tables configured alike still send the
        // same key wherever each of them happened to record it
        $group = $this->manager->groupFor($parent);

        if ($group !== $this->manager->groupFor($related)) {
            return false;
        }

        if ($group === null && $parent->getTable() !== $related->getTable()) {
            return false;
        }

        /*
        | Three tables, not two. A through relation's `getParent()` is the
        | intermediate model — Laravel constructs it with the through parent —
        | and the outer query runs on the far parent's shard, which the two
        | checks above never looked at. A pivot is a table the relation joins
        | that no model of the pair declares. Either way the third table has
        | to be on the same shard as the other two, and the only shape that
        | can promise that for three tables is all of them carrying one value
        | of one column.
        */
        if ($relation instanceof HasOneOrManyThrough || $relation instanceof BelongsToMany) {
            return $group !== null && $this->shareAValueColumn([$this->thirdTable($relation), $parent, $related], $group);
        }

        $parentKey = $parent->getShardKey();
        $relatedKey = $related->getShardKey();

        // the shared-column shape: both tables are sharded by the same column,
        // so rows carrying the same value are on the same shard. This is the
        // shape the whole tenant_data group takes. The column has to be one
        // both rows carry as a value rather than as their identity: a table
        // sharded by its own primary key shares that column's name with a
        // related row sharded by its own, and shares its value only where the
        // relation joins the two — which is the parent-key shape below
        if ($parentKey === $relatedKey && $parentKey !== $parent->getKeyName() && $relatedKey !== $related->getKeyName()) {
            return true;
        }

        // the parent-key shape: the child is sharded by the column the
        // relation constrains, and the parent by the column that constrains
        // it. Both sides then hash the same number
        return $this->joinsOnBothShardKeys($relation, $parent, $related, $parentKey, $relatedKey);
    }

    /**
     * Does the relation join the parent's shard key to the related one?
     *
     * @param Relation<*, *, *> $relation
     * @param Model $parent
     * @param Model $related
     * @param string $parentKey
     * @param string $relatedKey
     * @return bool
     */
    protected function joinsOnBothShardKeys(
        Relation $relation,
        Model $parent,
        Model $related,
        string $parentKey,
        string $relatedKey,
    ): bool {
        [$onRelated, $onParent] = match (true) {
            $relation instanceof HasOneOrMany => [
                $relation->getForeignKeyName(),
                $relation->getLocalKeyName(),
            ],
            $relation instanceof BelongsTo => [
                $relation->getOwnerKeyName(),
                $relation->getForeignKeyName(),
            ],
            // a pivot or an intermediate table means the two are joined
            // through a third, and nothing here can promise all three landed
            // together
            default => [null, null],
        };

        if ($onRelated === null || $onParent === null) {
            return false;
        }

        return $this->bare($onRelated) === $relatedKey && $this->bare($onParent) === $parentKey;
    }

    /**
     * The table a three-table relation goes through: the far parent, or the
     * pivot.
     *
     * The far parent has no accessor, so it is read through a closure bound
     * to the relation — the same field `getRelationExistenceQuery()` compiles
     * the subquery against. The pivot comes back as an instance of the class
     * `using()` named, or Laravel's plain `Pivot` when none was, with the
     * relation's table set on it.
     *
     * @param HasOneOrManyThrough<*, *, *, *>|BelongsToMany<*, *, *> $relation
     * @return Model
     */
    protected function thirdTable(HasOneOrManyThrough|BelongsToMany $relation): Model
    {
        if ($relation instanceof BelongsToMany) {
            return $relation->newPivot();
        }

        /** @var Model */
        return (fn (): Model => $this->farParent)->call($relation);
    }

    /**
     * Do these models all shard by one column none of them uses as its key?
     *
     * The shared-column shape, asked of three tables at once: every one in the
     * group, every one sharded by the same column, and that column a value
     * each row carries rather than any row's identity. A plain `Pivot` fails
     * it — it declares no shard key — and so does a global pivot, which is
     * the answer the docs give: colocate all three, or ask in two steps.
     *
     * @param list<Model> $models
     * @param string $group
     * @return bool
     */
    protected function shareAValueColumn(array $models, string $group): bool
    {
        $column = null;

        foreach ($models as $model) {
            if (!$this->manager->isShardable($model) || $this->manager->groupFor($model) !== $group) {
                return false;
            }

            if (!method_exists($model, 'getShardKey')) {
                return false;
            }

            $key = $model->getShardKey();

            if ($key === $model->getKeyName() || ($column !== null && $key !== $column)) {
                return false;
            }

            $column = $key;
        }

        return true;
    }

    /**
     * A column name without its table, since a relation may be declared with
     * a qualified one.
     *
     * @param string $column
     * @return string
     */
    protected function bare(string $column): string
    {
        return Str::afterLast($column, '.');
    }
}
