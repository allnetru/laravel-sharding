<?php

namespace Allnetru\Sharding\Relations;

use Allnetru\Sharding\Relations\Concerns\ResolvesShard;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * BelongsTo relation that resolves the parent model's shard connection.
 *
 * @template TRelatedModel of \Illuminate\Database\Eloquent\Model
 * @template TDeclaringModel of \Illuminate\Database\Eloquent\Model
 *
 * @extends BelongsTo<TRelatedModel, TDeclaringModel>
 */
class ShardBelongsTo extends BelongsTo
{
    use ResolvesShard;

    /** @inheritDoc */
    public function addConstraints()
    {
        if (static::$constraints) {
            $foreignKey = $this->getForeignKeyFrom($this->child);
            $key = $this->getQualifiedOwnerKeyName();

            if ($foreignKey === null) {
                $this->query->where($key, '=', $foreignKey);

                return;
            }

            // the owner is pinned by its owner key, which is its shard key
            // whenever the table shards by its own identity. Under colocation
            // it is not, and then the child's copy of the shard column is what
            // locates it
            $this->resolveShardConnection($this->child, $this->ownerKey, $foreignKey);

            $this->query->where($key, '=', $foreignKey);
        }
    }

    /** @inheritDoc */
    public function addEagerConstraints(array $models)
    {
        // the batch here is the children, and under colocation their owners
        // are on the connection the children came from
        $this->pinEagerLoadToParentsShard($models);

        parent::addEagerConstraints($models);
    }
}
