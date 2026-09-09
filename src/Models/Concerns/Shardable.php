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
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * @method static Builder withoutReplicas()
 */
trait Shardable
{
    /**
     * @var array<int, string>
     */
    public array $replicaConnections = [];

    /**
     * @param Builder $q
     * @return Builder
     *
     * @throws InvalidArgumentException
     */
    public function scopeWithoutReplicas(Builder $q): Builder
    {
        return $q->where($q->qualifyColumn('is_replica'), false);
    }

    /**
     * Insert the row on the connection its own shard key names.
     *
     * This is what makes a row land where its key says it should, and
     * until it existed no row reliably did. `Model::save()` builds the query
     * and only then fires `creating`, so the hook that chose the connection
     * was always too late: the statement had already been aimed. Two paths
     * made that visible.
     *
     * `Model::create()` goes through `Builder::newModelInstance()`, which
     * copies the connection of the blank model the builder was made from. That
     * model had no key, so it had been routed by a generated throwaway one,
     * and the row was inserted there. And a model whose key is generated —
     * every snowflake — had no key at query-build time either, so the query
     * was built on whatever connection the instance happened to carry.
     *
     * The row then reported one connection and lived on another. Nothing ever
     * failed, because every read fans out across all of them and finds it
     * anyway: the fan-out was covering for it. It surfaces the moment anything
     * trusts the key — pinning a read to one shard, `shards:distribute`
     * deciding a row is already in the right place, a rebalance moving it.
     *
     * So the placement is decided here, before the query exists, and the
     * `creating` hook keeps calling the same method: it is idempotent, and
     * anything that saves through another path still gets routed.
     *
     * @param Builder<static> $query
     * @return bool
     */
    protected function performInsert(Builder $query)
    {
        $this->resolveShardPlacement();

        // rebuilt on purpose: the query handed to us was aimed before the
        // line above knew where this row belongs
        /** @var Builder<static> $aimed */
        $aimed = $this->newModelQuery();

        return parent::performInsert($aimed);
    }

    /**
     * Choose this row's key and the connection it lives on.
     *
     * Idempotent, and called from two places for that reason: from
     * `performInsert()` before the query is built, and from the `creating`
     * hook, which is where it used to live and which other save paths still
     * reach.
     *
     * A connection already chosen for a replica is left alone. That is the one
     * case where a connection is a deliberate decision about a row that does
     * not exist yet, and the `creating` hook has always tested the same flag.
     *
     * @return void
     */
    protected function resolveShardPlacement(): void
    {
        if ($this->exists || $this->getAttribute('is_replica')) {
            return;
        }

        $manager = app(ShardingManager::class);

        if (!$manager->isShardable($this)) {
            return;
        }

        $keyName = $this->getShardKey();
        $key = $this->getAttribute($keyName);

        if (!$key) {
            $key = app(IdGenerator::class)->generate($this);
            $this->setAttribute($keyName, $key);
        }

        [$strategy, $config] = $manager->strategyFor($this);
        $connections = $strategy->determine($key, $config);

        if ($connections === []) {
            return;
        }

        $this->setConnection($connections[0]);
        $this->replicaConnections = array_slice($connections, 1);
        $this->setAttribute('is_replica', false);

        /*
        | When the shard key is a column other than the primary key, which is
        | how colocation is set up, only the shard key has been filled. The
        | primary key would stay null and the insert would fail, so it is
        | generated here. Auto-incrementing keys are left to the database.
        */
        $primaryKey = $this->getKeyName();

        if ($primaryKey !== $keyName && !$this->getIncrementing() && !$this->getKey()) {
            $this->setAttribute($primaryKey, app(IdGenerator::class)->generate($this));
        }
    }

    /**
     * Boot the shardable trait to assign connections and IDs on model creation.
     *
     * @return void
     */
    public static function bootShardable(): void
    {
        static::addGlobalScope('without_replicas', function (Builder $builder): void {
            /** @var Builder<static>&ShardBuilder $builder */
            $builder->withoutReplicas();
        });

        static::creating(function ($model): void {
            if ($model->getAttribute('is_replica')) {
                $model->replicaConnections = [];

                return;
            }

            /*
            | The same routing `performInsert()` has already done, kept here
            | because it is idempotent and because a save that reaches the
            | insert by another path still has to be routed. What it can no
            | longer do on its own is aim the statement: by the time this hook
            | runs the query exists, which is the whole reason the placement
            | moved earlier.
            */
            $model->resolveShardPlacement();
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
     * The related model is templated so a model can narrow the return type in
     * its own docblock. Without it every relation resolved to
     * `BelongsTo<Model, $this>`, and a model declaring `BelongsTo<Tenant,
     * $this>` failed static analysis, which forced consumers either to widen
     * their docblocks and lose the type or to lower the analysis level.
     *
     * Only `@return` is declared here on purpose. A `@phpstan-return` next to
     * it wins over `@return` in PHPStan, so a hardcoded `Model` there silently
     * cancels the template and brings the original problem back.
     *
     * @template TRelatedModel of Model
     *
     * @param class-string<TRelatedModel> $related
     * @param string|null $foreignKey
     * @param string|null $ownerKey
     * @param string|null $relation
     * @return BelongsTo<TRelatedModel, $this>
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

        // a global related model gets the plain relation: ShardBelongsTo would
        // resolve a shard for a table that lives on the default connection
        if (!app(ShardingManager::class)->isShardable($instance)) {
            /** @var BelongsTo<TRelatedModel, $this> $belongsTo */
            $belongsTo = new BelongsTo(
                $instance->newQuery(),
                $this,
                $foreignKey,
                $ownerKey,
                $relation
            );

            return $belongsTo;
        }

        // newRelatedInstance() is declared as returning plain Model, so type
        // inference does not carry TRelatedModel through newQuery() and the
        // relation collapses to ShardBelongsTo<Model, $this>. the relation
        // class is picked from $related, so the type is known for certain here
        /** @var ShardBelongsTo<TRelatedModel, $this> $shardBelongsTo */
        $shardBelongsTo = new ShardBelongsTo(
            $instance->newQuery(),
            $this,
            $foreignKey,
            $ownerKey,
            $relation
        );

        return $shardBelongsTo;
    }

    /**
     * @inheritDoc
     *
     * Eloquent copies the parent connection into a related model that has none
     * of its own. For a sharded parent that means every relation to a global
     * table is queried on a shard, where reference tables do not exist. The
     * connection is therefore propagated only to related models that are
     * sharded themselves.
     */
    protected function newRelatedInstance($class)
    {
        $instance = new $class();

        if (!$instance->getConnectionName() && app(ShardingManager::class)->isShardable($instance)) {
            $instance->setConnection($this->getConnectionName());
        }

        return $instance;
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
     * A model without a key is not routed, it is given a grammar. Eloquent
     * asks for the connection whenever it builds a query — `Model::query()`
     * goes through `newBaseQueryBuilder()`, which needs one to pick a grammar
     * from — and this used to answer a keyless model by generating a key,
     * writing it onto the model and resolving the shard for it. That was a
     * metadata round trip per query builder, spent on a connection
     * `ShardBuilder` then decided for itself, and it left every fresh instance
     * carrying a random key that some caller would eventually read.
     *
     * The first configured connection answers instead: all shards share a
     * grammar, and nothing about a keyless model can say which of them it
     * belongs on. Not remembered on the model, so a key set afterwards is
     * routed the moment it is asked about. Placement — the connection a row
     * is actually written to — is decided by `resolveShardPlacement()` and
     * nowhere else.
     *
     * @return string
     */
    public function getConnectionName()
    {
        if ($this->connection) {
            return $this->connection;
        }

        $manager = app(ShardingManager::class);
        $keyName = $this->getShardKey();
        $key = $this->getAttribute($keyName);

        if (!$key) {
            $names = array_keys((array) $manager->connectionsFor($this));

            return $names[0] ?? parent::getConnectionName();
        }

        $connections = $manager->connectionFor($this, $key);
        $this->connection = $connections[0];
        $this->replicaConnections = array_slice($connections, 1);

        return $this->connection;
    }

    /**
     * Get the attribute name used for sharding.
     *
     * @return string
     */
    public function getShardKey(): string
    {
        return property_exists($this, 'shardKey') ? $this->shardKey : $this->getKeyName();
    }

    /**
     * Create a new Eloquent query builder for the model.
     *
     * @param \Illuminate\Database\Query\Builder $query
     * @return Builder
     */
    public function newEloquentBuilder($query): Builder
    {
        return (new ShardBuilder($query))->setModel($this);
    }
}
