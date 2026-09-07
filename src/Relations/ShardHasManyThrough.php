<?php

namespace Allnetru\Sharding\Relations;

use Allnetru\Sharding\Relations\Concerns\ResolvesShard;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;

/**
 * @template TRelatedModel of \Illuminate\Database\Eloquent\Model
 * @template TIntermediateModel of \Illuminate\Database\Eloquent\Model
 * @template TDeclaringModel of \Illuminate\Database\Eloquent\Model
 *
 * @extends HasManyThrough<TRelatedModel, TIntermediateModel, TDeclaringModel>
 */
class ShardHasManyThrough extends HasManyThrough
{
    use ResolvesShard;

    /** @inheritDoc */
    public function addConstraints()
    {
        // guarded, because eager loading calls addEagerConstraints instead
        // and must keep fanning out
        if (static::$constraints) {
            // the intermediate table carries the far parent's key, not the
            // related table, so only a shared shard column can locate the
            // related rows
            $this->resolveShardConnection($this->farParent, null, null);
        }

        parent::addConstraints();
    }
}
