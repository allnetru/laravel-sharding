<?php

namespace Allnetru\Sharding\Relations;

use Allnetru\Sharding\Relations\Concerns\ResolvesShard;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * @template TRelatedModel of \Illuminate\Database\Eloquent\Model
 * @template TDeclaringModel of \Illuminate\Database\Eloquent\Model
 *
 * @extends HasOne<TRelatedModel, TDeclaringModel>
 */
class ShardHasOne extends HasOne
{
    use ResolvesShard;

    /** @inheritDoc */
    public function addConstraints()
    {
        if (static::$constraints) {
            $parentKey = $this->getParentKey();

            if ($parentKey !== null) {
                // the children are pinned by their foreign key, so when that
                // column is also their shard key the shard is known exactly
                $this->resolveShardConnection($this->parent, $this->getForeignKeyName(), $parentKey);
            }

            parent::addConstraints();
        }
    }

    /** @inheritDoc */
    public function addEagerConstraints(array $models)
    {
        $this->pinEagerLoadToParentsShard($models);

        parent::addEagerConstraints($models);
    }
}
