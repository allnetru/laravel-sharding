<?php

namespace Allnetru\Sharding\Console\Commands\Shards\Concerns;

use Allnetru\Sharding\ShardingManager;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

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
     * The tables of this table's colocation group that hold rows.
     *
     * Both commands need it, and for the same reason: the tables of a group
     * share the value that decides their shard, so a routing change made for
     * one of them applies to all of them. A table with nothing in it has
     * nothing to strand, which is what lets a group whose later tables are
     * configured before they exist be worked on at all.
     *
     * @param ShardingManager $manager
     * @param string $table The table being worked on.
     * @return list<string> The others, in configuration order.
     */
    protected function populatedGroupSiblings(ShardingManager $manager, string $table): array
    {
        $group = $manager->groupFor($table);

        if ($group === null) {
            return [];
        }

        $siblings = [];

        foreach ((array) config("sharding.groups.{$group}") as $sibling) {
            if ($sibling === $table) {
                continue;
            }

            if ($this->holdsRows($manager, (string) $sibling)) {
                $siblings[] = (string) $sibling;
            }
        }

        return $siblings;
    }

    /**
     * Whether a table exists and holds anything, anywhere.
     *
     * Asked of the manager rather than of `sharding.connections`, because a
     * group owner may name its own connection list — and a table whose rows
     * live only there reads as empty against the global one.
     *
     * @param ShardingManager $manager
     * @param string $table
     * @return bool
     */
    protected function holdsRows(ShardingManager $manager, string $table): bool
    {
        foreach (array_keys((array) $manager->connectionsFor($table)) as $connection) {
            if (!Schema::connection($connection)->hasTable($table)) {
                continue;
            }

            if (DB::connection($connection)->table($table)->limit(1)->exists()) {
                return true;
            }
        }

        return false;
    }

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
