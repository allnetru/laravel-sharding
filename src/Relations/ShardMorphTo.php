<?php

namespace Allnetru\Sharding\Relations;

use Allnetru\Sharding\ShardingManager;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * @template TRelatedModel of \Illuminate\Database\Eloquent\Model
 * @template TDeclaringModel of \Illuminate\Database\Eloquent\Model
 *
 * @extends MorphTo<TRelatedModel, TDeclaringModel>
 */
class ShardMorphTo extends MorphTo
{
    /** {@inheritDoc} */
    public function addConstraints()
    {
        if (static::$constraints) {
            $type = $this->parent->{$this->morphType};
            $foreignKey = $this->getForeignKeyFrom($this->child);

            $instance = $this->createModelByType($type);
            $connection = app(ShardingManager::class)->connectionFor($instance, $foreignKey)[0];

            $instance->setConnection($connection);
            $this->related = $instance;
            $this->query = $instance->newQuery();
            $this->query->getModel()->setConnection($connection);
            $this->query->getQuery()->connection = $this->query->getModel()->getConnection();

            $this->ownerKey = $this->ownerKey ?: $instance->getKeyName();
            $this->query->where($this->getQualifiedOwnerKeyName(), '=', $foreignKey);
        }
    }
}
