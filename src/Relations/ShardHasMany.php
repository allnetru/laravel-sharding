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

    /** {@inheritDoc} */
    public function addConstraints()
    {
        if (static::$constraints) {
            $this->switchConnection($this->getParentKey());
            parent::addConstraints();
        }
    }
}
