<?php

namespace Allnetru\Sharding\Models\Concerns;

use Allnetru\Sharding\IdGenerator;
use Allnetru\Sharding\Relations\ShardBelongsTo;
use Allnetru\Sharding\Relations\ShardBelongsToMany;
use Allnetru\Sharding\Relations\ShardHasMany;
use Allnetru\Sharding\Relations\ShardHasManyThrough;
use Allnetru\Sharding\Relations\ShardHasOne;
use Allnetru\Sharding\Relations\ShardHasOneThrough;
use Allnetru\Sharding\Relations\ShardMorphMany;
use Allnetru\Sharding\Relations\ShardMorphOne;
use Allnetru\Sharding\Relations\ShardMorphTo;
use Allnetru\Sharding\Relations\ShardMorphToMany;
use Allnetru\Sharding\ShardBuilder;
use Allnetru\Sharding\ShardingManager;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;
use InvalidArgumentException;

trait Shardable
{
    /**
     * @var array<int, string>
     */
    public array $replicaConnections = [];

    /**
     * @return Builder
     *
     * @throws InvalidArgumentException
     */
    public function scopeWithoutReplicas(Builder $q): Builder
    {
        return $q->where($q->qualifyColumn('is_replica'), false);
    }

    /**
     * Boot the shardable trait to assign connections and IDs on model creation.
     */
    public static function bootShardable(): void
    {
        static::addGlobalScope('without_replicas', function (Builder $builder): void {
            $builder->where($builder->qualifyColumn('is_replica'), false);
        });

        static::creating(function ($model): void {
            if ($model->getAttribute('is_replica')) {
                $model->replicaConnections = [];

                return;
            }

            $keyName = $model->getShardKey();
            $key = $model->getAttribute($keyName);

            if (!$key) {
                $key = app(IdGenerator::class)->generate($model);
                $model->setAttribute($keyName, $key);
            }

            $manager = app(ShardingManager::class);
            [$strategy, $config] = $manager->strategyFor($model);
            $connections = $strategy->determine($key, $config);
            $model->setConnection($connections[0]);
            $model->replicaConnections = array_slice($connections, 1);
            $model->setAttribute('is_replica', false);
        });

        static::created(function ($model): void {
            $manager = app(ShardingManager::class);
            [$strategy, $config] = $manager->strategyFor($model);
            $key = $model->getAttribute($model->getShardKey());

            $connections = array_merge([$model->getConnectionName()], $model->replicaConnections);
            $strategy->recordMeta($key, $connections, $config);

            foreach ($model->replicaConnections as $connection) {
                $replica = $model->replicate();
                $replica->setAttribute($model->getKeyName(), $model->getKey());
                $replica->setConnection($connection);
                $replica->replicaConnections = [];
                $replica->is_replica = true;
                $replica->saveQuietly();
            }
        });
    }

    /**
     * Define an inverse one-to-one or many relation that resolves the parent's shard.
     *
     * @param  class-string<Model>  $related
     * @param  string|null  $foreignKey
     * @param  string|null  $ownerKey
     * @param  string|null  $relation
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo<\Illuminate\Database\Eloquent\Model, $this>
     * @phpstan-return ShardBelongsTo<\Illuminate\Database\Eloquent\Model, $this>
     */
    public function belongsTo($related, $foreignKey = null, $ownerKey = null, $relation = null)
    {
        if (is_null($relation)) {
            $relation = $this->guessBelongsToRelation();
        }

        $instance = $this->newRelatedInstance($related);

        if (is_null($foreignKey)) {
            $foreignKey = Str::snake($relation) . '_' . $instance->getKeyName();
        }

        $ownerKey = $ownerKey ?: $instance->getKeyName();

        return new ShardBelongsTo(
            $instance->newQuery(),
            $this,
            $foreignKey,
            $ownerKey,
            $relation
        );
    }

    /**
     * @inheritDoc
     */
    protected function newHasOne(Builder $query, Model $parent, $foreignKey, $localKey)
    {
        return new ShardHasOne($query, $parent, $foreignKey, $localKey);
    }

    /**
     * @inheritDoc
     */
    protected function newHasMany(Builder $query, Model $parent, $foreignKey, $localKey)
    {
        return new ShardHasMany($query, $parent, $foreignKey, $localKey);
    }

    /**
     * @inheritDoc
     */
    protected function newHasOneThrough(Builder $query, Model $farParent, Model $throughParent, $firstKey, $secondKey, $localKey, $secondLocalKey)
    {
        return new ShardHasOneThrough($query, $farParent, $throughParent, $firstKey, $secondKey, $localKey, $secondLocalKey);
    }

    /**
     * @inheritDoc
     */
    protected function newHasManyThrough(Builder $query, Model $farParent, Model $throughParent, $firstKey, $secondKey, $localKey, $secondLocalKey)
    {
        return new ShardHasManyThrough($query, $farParent, $throughParent, $firstKey, $secondKey, $localKey, $secondLocalKey);
    }

    /**
     * @inheritDoc
     */
    protected function newMorphOne(Builder $query, Model $parent, $type, $id, $localKey)
    {
        return new ShardMorphOne($query, $parent, $type, $id, $localKey);
    }

    /**
     * @inheritDoc
     */
    protected function newMorphMany(Builder $query, Model $parent, $type, $id, $localKey)
    {
        return new ShardMorphMany($query, $parent, $type, $id, $localKey);
    }

    /**
     * @inheritDoc
     */
    protected function newBelongsToMany(Builder $query, Model $parent, $table, $foreignPivotKey, $relatedPivotKey, $parentKey, $relatedKey, $relationName = null)
    {
        return new ShardBelongsToMany($query, $parent, $table, $foreignPivotKey, $relatedPivotKey, $parentKey, $relatedKey, $relationName);
    }

    /**
     * @inheritDoc
     */
    protected function newMorphToMany(Builder $query, Model $parent, $name, $table, $foreignPivotKey, $relatedPivotKey, $parentKey, $relatedKey, $relationName = null, $inverse = false)
    {
        return new ShardMorphToMany($query, $parent, $name, $table, $foreignPivotKey, $relatedPivotKey, $parentKey, $relatedKey, $relationName, $inverse);
    }

    /**
     * @inheritDoc
     */
    protected function newMorphTo(Builder $query, Model $parent, $foreignKey, $ownerKey, $type, $relation)
    {
        return new ShardMorphTo($query, $parent, $foreignKey, $ownerKey, $type, $relation);
    }

    /**
     * Get the primary connection name for the model.
     *
     * @return string
     */
    public function getConnectionName()
    {
        if ($this->connection) {
            return $this->connection;
        }

        $keyName = $this->getShardKey();
        $key = $this->getAttribute($keyName);

        if (!$key) {
            $key = app(IdGenerator::class)->generate($this);
            $this->setAttribute($keyName, $key);
        }

        $connections = app(ShardingManager::class)->connectionFor($this, $key);
        $this->connection = $connections[0];
        $this->replicaConnections = array_slice($connections, 1);

        return $this->connection;
    }

    /**
     * Get the attribute name used for sharding.
     */
    public function getShardKey(): string
    {
        return property_exists($this, 'shardKey') ? $this->shardKey : $this->getKeyName();
    }

    /**
     * Create a new Eloquent query builder for the model.
     *
     * @param  \Illuminate\Database\Query\Builder  $query
     */
    public function newEloquentBuilder($query): Builder
    {
        return (new ShardBuilder($query))->setModel($this);
    }
}
