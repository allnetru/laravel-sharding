<?php

namespace Allnetru\Sharding\Console\Commands\Shards\Concerns;

use Illuminate\Database\Eloquent\Model;

/**
 * Turning a command argument into the model that answers for a table.
 *
 * Both repair commands need it, and both used to guess: they built
 * `App\Models\<Table>` from the table name, which fails for every application
 * that keeps its models anywhere else — a domain layout, a module tree, a
 * package. On such a project the commands could not run at all.
 *
 * So the argument is the model class, and the table comes off it. Only the
 * model knows which column its own key lives in, which is the other half of
 * the same problem: the tables of a colocation group share the value that
 * decides their shard, not the name of the column it is written in.
 *
 * The `App\Models\` prefix is still tried as a fallback, so a short name keeps
 * working where it always did.
 */
trait ResolvesShardModel
{
    /**
     * Resolve a model instance from the given class name.
     *
     * @param string $class The class, fully qualified or short.
     * @return Model|null Null when it is not a model, having said so.
     */
    protected function resolveModel(string $class): ?Model
    {
        $modelClass = ltrim($class, '\\');

        if (!class_exists($modelClass)) {
            $fallback = app()->getNamespace() . 'Models\\' . $modelClass;

            if (class_exists($fallback)) {
                $modelClass = $fallback;
            }
        }

        if (!class_exists($modelClass) || !is_subclass_of($modelClass, Model::class)) {
            $this->error("Model {$modelClass} not found.");

            return null;
        }

        return new $modelClass();
    }
}
