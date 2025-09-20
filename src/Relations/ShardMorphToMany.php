<?php

namespace Allnetru\Sharding\Relations;

use Allnetru\Sharding\Relations\Concerns\ResolvesShard;
use Illuminate\Database\Eloquent\Relations\MorphToMany;

/**
 * @template TRelatedModel of \Illuminate\Database\Eloquent\Model
 * @template TDeclaringModel of \Illuminate\Database\Eloquent\Model
 * @extends MorphToMany<TRelatedModel, TDeclaringModel>
 */
class ShardMorphToMany extends MorphToMany
{
    use ResolvesShard;

    /** @inheritDoc */
    public function addConstraints()
    {
        $this->switchConnection($this->parent->{$this->parentKey});
        parent::addConstraints();
    }
}
