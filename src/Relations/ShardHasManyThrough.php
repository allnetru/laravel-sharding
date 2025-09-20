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
        $key = $this->farParent->{$this->localKey} ?? null;

        if ($key !== null) {
            $this->switchConnection($key);
        }
        parent::addConstraints();
    }
}
