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
            $this->switchConnection($this->getParentKey());
            parent::addConstraints();
        }
    }
}
