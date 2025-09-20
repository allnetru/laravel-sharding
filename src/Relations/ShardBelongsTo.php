<?php

namespace Allnetru\Sharding\Relations;

use Allnetru\Sharding\ShardingManager;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * BelongsTo relation that resolves the parent model's shard connection.
 *
 * @template TRelatedModel of \Illuminate\Database\Eloquent\Model
 * @template TDeclaringModel of \Illuminate\Database\Eloquent\Model
 *
 * @extends BelongsTo<TRelatedModel, TDeclaringModel>
 */
class ShardBelongsTo extends BelongsTo
{
    /** @inheritDoc */
    public function addConstraints()
    {
        if (static::$constraints) {
            $foreignKey = $this->getForeignKeyFrom($this->child);
            $key = $this->getQualifiedOwnerKeyName();

            if ($foreignKey === null) {
                $this->query->where($key, '=', $foreignKey);

                return;
            }

            $connection = app(ShardingManager::class)
                ->connectionFor($this->related, $foreignKey)[0];

            $this->query->getModel()->setConnection($connection);
            $this->query->getQuery()->connection = $this->query->getModel()->getConnection();

            $this->query->where($key, '=', $foreignKey);
        }
    }
}
