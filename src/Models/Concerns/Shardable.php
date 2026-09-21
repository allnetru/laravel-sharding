<?php

namespace Allnetru\Sharding\Models\Concerns;

use Allnetru\Sharding\Exceptions\UnsupportedCrossShardQuery;
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
use Closure;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * The builder a shardable model answers with is the package's own, so the
 * methods it adds — `onShardConnection()`, and a transaction on the shard the
 * key names — are known to whatever reads the model's query.
 *
 * `query()` is deliberately absent: Laravel declares it, and redeclaring it
 * here replaces a model-aware builder everywhere at once.
 *
 * @method static ShardBuilder<static> withoutReplicas()
 * @method static ShardBuilder<static> onShardConnection(string $connection)
 */
trait Shardable
{
    /**
     * Clause keywords that can follow a source without being its name.
     *
     * A derived table need not be aliased at all on every database, and what
     * comes after it is then the next clause rather than a name.
     *
     * @var list<string>
     */
    protected const NOT_AN_ALIAS = [
        'join', 'inner', 'left', 'right', 'full', 'cross', 'natural', 'lateral',
        'on', 'using', 'where', 'group', 'order', 'limit', 'offset', 'having',
        'union', 'intersect', 'except', 'for', 'window', 'fetch',
    ];

    /**
     * @var array<int, string>
     */
    public array $replicaConnections = [];

    /**
     * How to name `is_replica` in this query.
     *
     * The model's table while the query still reads it. A query given a
     * derived table by `fromRaw()` — a CTE, a VALUES list — reads something
     * else, and naming the model's table there asks for a column of a table
     * the statement never mentions. The derived table's own alias is used
     * when it has one, because a join then has two `is_replica` to choose
     * between and a bare name is ambiguous.
     *
     * @param Builder $q The query.
     *
     * @return string
     */
    protected function replicaColumn(Builder $q): string
    {
        $from = $q->getQuery()->from;

        if (is_string($from)) {
            return $q->qualifyColumn('is_replica');
        }

        /*
        | The alias of the FIRST source, which is the derived table itself.
        | A raw FROM can name several — `(…) as parcels, lateral … as d` —
        | and the last one belongs to a lateral join that carries none of the
        | model's columns. So the scan stops at the parenthesis that closes
        | the first source rather than reading to the end of the string.
        */
        $expression = (string) $from->getValue($q->getQuery()->getGrammar());
        $alias = $this->firstSourceAlias($expression);

        return $alias === null ? 'is_replica' : $alias . '.is_replica';
    }

    /**
     * The alias the first source of a raw FROM declares, if any.
     *
     * Quoted text is skipped rather than scanned: a parenthesis inside a
     * string literal is data, and counting it would end the source early.
     *
     * @param string $expression The raw FROM, as written.
     *
     * @return string|null
     */
    protected function firstSourceAlias(string $expression): ?string
    {
        $depth = 0;
        $quote = null;
        $length = strlen($expression);

        for ($position = 0; $position < $length; $position++) {
            $character = $expression[$position];

            if ($quote !== null) {
                // a doubled quote is an escaped one and the literal goes on
                if ($character === $quote && ($expression[$position + 1] ?? '') === $quote) {
                    $position++;

                    continue;
                }

                if ($character === $quote) {
                    $quote = null;
                }

                continue;
            }

            if ($character === "'" || $character === '"') {
                $quote = $character;

                continue;
            }

            if ($character === '(') {
                $depth++;

                continue;
            }

            if ($character !== ')') {
                continue;
            }

            if (--$depth !== 0) {
                continue;
            }

            return $this->aliasAfter(substr($expression, $position + 1));
        }

        return null;
    }

    /**
     * The name a source gives itself, out of what follows it.
     *
     * @param string $tail Everything after the source.
     *
     * @return string|null Null when what follows is a clause rather than a name.
     */
    protected function aliasAfter(string $tail): ?string
    {
        if (preg_match('/^\s*as\s+"?([A-Za-z_][A-Za-z0-9_]*)"?/i', $tail, $found) === 1) {
            return $found[1];
        }

        if (preg_match('/^\s*"?([A-Za-z_][A-Za-z0-9_]*)"?/', $tail, $found) !== 1) {
            return null;
        }

        return in_array(strtolower($found[1]), self::NOT_AN_ALIAS, true) ? null : $found[1];
    }

    /**
     * Run a callback inside a transaction on this row's shard.
     *
     * A transaction lives on one connection, and the connection is the shard
     * the row's key names — not the default one `DB::transaction()` opens,
     * which on a sharded schema wraps nothing the callback touches. The
     * application used to spell the connection itself, which is exactly the
     * knowledge it should not have.
     *
     * @param Closure $callback The work.
     * @param int $attempts How many times to retry on a deadlock.
     *
     * @return mixed What the callback returned.
     *
     * @throws UnsupportedCrossShardQuery When the row carries no shard key yet.
     */
    public function transaction(Closure $callback, int $attempts = 1): mixed
    {
        $key = $this->getAttribute($this->getShardKey());

        // null and '' only: 0 is a key the builder pins on like any other,
        // and a truthiness check would refuse the row that carries it
        if ($key === null || $key === '') {
            throw new UnsupportedCrossShardQuery(sprintf(
                '%s::transaction() needs the shard key set: a row without one names no shard to open a transaction on.',
                static::class,
            ));
        }

        return $this->getConnection()->transaction($callback, $attempts);
    }

    /**
     * @param Builder $q
     * @return Builder
     *
     * @throws InvalidArgumentException
     */
    public function scopeWithoutReplicas(Builder $q): Builder
    {
        return $q->where($this->replicaColumn($q), false);
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

        /*
        | Null is a missing key; zero is not.
        |
        | `!$key` treated both the same, so a table keyed by a column where
        | zero is a real value — a platform-wide row beside per-tenant ones —
        | had an identifier invented for it on every insert. That does not
        | fail: the row lands on a shard nothing will look for it on, and the
        | next insert of the same logical row gets a different one again.
        */
        if ($key === null || $key === '') {
            $key = app(IdGenerator::class)->generate($this);
            $this->setAttribute($keyName, $key);
        }

        /*
        | Written back to the attribute, not just used locally.
        |
        | Everything downstream reads the attribute again — the `created` hook
        | hands it to `recordMeta()`, and `shards:distribute` reads it off the
        | stored row. Normalising only the local copy routed the insert by one
        | value and recorded its placement under another, which is worse than
        | the bug this fix started on: the slot of an unrelated key gets
        | overwritten while the row's own slot stays unrecorded.
        */
        $normalised = self::normaliseShardKey($key);

        if ($normalised !== $key) {
            $this->setAttribute($keyName, $normalised);
            $key = $normalised;
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

        // null is a missing key and zero is not, the same distinction
        // resolveShardPlacement() makes above
        if ($key === null || $key === '') {
            $names = array_keys((array) $manager->connectionsFor($this));

            return $names[0] ?? parent::getConnectionName();
        }

        $connections = $manager->connectionFor($this, self::normaliseShardKey($key));
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
     * Documented as what it returns, so a shardable model's query is known
     * to carry the builder's own methods — `onShardConnection()`, and a
     * transaction on the shard the key names. Declared only as the base
     * builder, static analysis saw none of them and called every one
     * undefined.
     *
     * The native type stays the base builder: this is a Laravel extension
     * point, and narrowing it would stop a model overriding it with a
     * ShardBuilder subclass of its own.
     *
     * @param \Illuminate\Database\Query\Builder $query
     * @return ShardBuilder<$this>
     */
    public function newEloquentBuilder($query): Builder
    {
        return (new ShardBuilder($query))->setModel($this);
    }

    /**
     * The key as the strategies will see it once it has been stored.
     *
     * A boolean is the case this exists for. PDO persists `false` as integer
     * zero, so a row written with `false` would be hashed as `(string) false`
     * — the empty string — and read back hashed as `'0'`: written to one shard
     * and looked for on another, with nothing anywhere saying so.
     *
     * Everything else passes through unchanged, zero included: it is a key
     * like any other, which is the whole point of the checks above.
     *
     * @param mixed $key The raw attribute.
     *
     * @return mixed The value to route by.
     */
    protected static function normaliseShardKey(mixed $key): mixed
    {
        return is_bool($key) ? (int) $key : $key;
    }
}
