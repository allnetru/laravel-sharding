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

    /**
     * @inheritDoc
     *
     * The shard is chosen here, which is earlier than Eloquent chooses the
     * related model: stock `MorphTo` does not override this at all and waits
     * until the results are fetched. Choosing early is what lets a colocated
     * morph read one shard instead of every one — but it means this method has
     * to survive the states stock code never resolves a model in.
     */
    public function addConstraints()
    {
        if (static::$constraints) {
            $type = $this->parent->{$this->morphType};

            /*
            | No type yet, so there is no related table, no key to constrain on
            | and no shard to choose. It happens on an unsaved row whose morph
            | is about to be assigned — `spatie/laravel-activitylog` builds an
            | entry and reads `$activity->subject` before setting
            | `subject_type`, which is enough to make every logged save fatal
            | with «Class name must be a valid object or a string».
            |
            | Falling through to BelongsTo is exactly what stock MorphTo does,
            | and its getResults() answers null while the type is missing.
            */
            if ($type === null) {
                parent::addConstraints();

                return;
            }

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
