<?php

namespace Allnetru\Sharding\Relations;

use Allnetru\Sharding\Relations\Concerns\ResolvesShard;
use Illuminate\Database\Eloquent\Relations\MorphToMany;

/**
 * @template TRelatedModel of \Illuminate\Database\Eloquent\Model
 * @template TDeclaringModel of \Illuminate\Database\Eloquent\Model
 *
 * @extends MorphToMany<TRelatedModel, TDeclaringModel>
 */
class ShardMorphToMany extends MorphToMany
{
    use ResolvesShard;

    /** @inheritDoc */
    public function addConstraints()
    {
        // no column of the related table is constrained here — the pivot is
        // what carries the parent's key — so the shard is knowable only when
        // parent and related share a shard column
        $this->resolveShardConnection($this->parent, null, null);

        parent::addConstraints();
    }
}
