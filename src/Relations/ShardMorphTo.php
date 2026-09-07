<?php

namespace Allnetru\Sharding\Relations;

use Allnetru\Sharding\Relations\Concerns\ResolvesShard;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * @template TRelatedModel of \Illuminate\Database\Eloquent\Model
 * @template TDeclaringModel of \Illuminate\Database\Eloquent\Model
 *
 * @extends MorphTo<TRelatedModel, TDeclaringModel>
 */
class ShardMorphTo extends MorphTo
{
    use ResolvesShard;

    /** @inheritDoc */
    public function addConstraints()
    {
        if (static::$constraints) {
            $type = $this->parent->{$this->morphType};
            $foreignKey = $this->getForeignKeyFrom($this->child);

            /** @var TRelatedModel $instance */
            $instance = $this->createModelByType($type);

            // the related model and the query are replaced before the shard is
            // resolved, because resolving it reads both
            $this->related = $instance;
            /** @var \Illuminate\Database\Eloquent\Builder<TRelatedModel> $query */
            $query = $instance->newQuery();
            $this->query = $query;

            $this->ownerKey = $this->ownerKey ?: $instance->getKeyName();

            $this->resolveShardConnection($this->child, $this->ownerKey, $foreignKey);

            $this->query->where($this->getQualifiedOwnerKeyName(), '=', $foreignKey);
        }
    }
}
