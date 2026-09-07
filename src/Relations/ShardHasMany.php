<?php

namespace Allnetru\Sharding\Relations;

use Allnetru\Sharding\Relations\Concerns\ResolvesShard;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @template TRelatedModel of \Illuminate\Database\Eloquent\Model
 * @template TDeclaringModel of \Illuminate\Database\Eloquent\Model
 *
 * @extends HasMany<TRelatedModel, TDeclaringModel>
 */
class ShardHasMany extends HasMany
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
}
