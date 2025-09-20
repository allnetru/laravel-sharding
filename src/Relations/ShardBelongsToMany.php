<?php

namespace Allnetru\Sharding\Relations;

use Allnetru\Sharding\Relations\Concerns\ResolvesShard;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/**
 * @template TRelatedModel of \Illuminate\Database\Eloquent\Model
 * @template TDeclaringModel of \Illuminate\Database\Eloquent\Model
 *
 * @extends BelongsToMany<TRelatedModel, TDeclaringModel>
 */
class ShardBelongsToMany extends BelongsToMany
{
    use ResolvesShard;

    /** @inheritDoc */
    public function addConstraints()
    {
        $this->switchConnection($this->parent->{$this->parentKey});
        parent::addConstraints();
    }
}
