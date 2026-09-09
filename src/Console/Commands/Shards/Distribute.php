<?php

namespace Allnetru\Sharding\Console\Commands\Shards;

use Allnetru\Sharding\ShardingManager;
use Allnetru\Sharding\Support\Database\ForeignKeyConstraintDetector;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Put existing rows on the shard their own key names.
 *
 * The repair tool, and the one an upgrade needs: a row is placed by its shard
 * key when it is written, and anything written before that was true — or moved
 * by hand, or restored from a dump taken on another topology — can be sitting
 * on a shard its key does not point at. A fan-out finds such a row anyway,
 * which is why it can go unnoticed for a long time; a query that names its key
 * does not, and neither does a rebalance that trusts the slot table.
 *
 * `--dry-run` answers the question without touching anything: how many rows
 * are not where they belong. That is worth running before an upgrade rather
 * than after.
 *
 * **The target is the shard key, not the primary key.** For a colocated table
 * those are different columns and the difference is the whole point: a row of
 * `user_roles` belongs on the shard its `user_id` names, and resolving it by
 * its own identifier would scatter the very rows colocation exists to keep
 * together. This command used to do exactly that.
 *
 * Nothing here needs a model per table either. The shard key of a colocation
 * group is the group's — that is what colocation means — so one model resolves
 * it for every table in the group.
 *
 * **One model per table, and that is not verbosity.** The tables of a
 * colocation group share a key in the sense that matters — the same value
 * decides their shard — but not the column it is written in: `users.id` and
 * `user_roles.user_id` are the same key under two names. Only the model knows
 * which column is its own, so the command takes as many models as there are
 * tables to sweep, and names the ones left over rather than guessing. The
 * previous version looked for `App\Models\<Table>` and failed on any
 * application that keeps its models anywhere else.
 */
class Distribute extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'shards:distribute {model* : One or more model classes, one per table to sweep}
        {--chunk=1000}
        {--dry-run}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Put existing rows on the shard their own key names.';

    /**
     * Execute the console command.
     *
     * @param ShardingManager $manager
     * @param ForeignKeyConstraintDetector $foreignKeyDetector
     * @return int
     */
    public function handle(ShardingManager $manager, ForeignKeyConstraintDetector $foreignKeyDetector): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $chunk = max(1, (int) $this->option('chunk'));

        $moved = 0;
        $misplaced = 0;
        $swept = [];
        $groups = [];

        foreach ((array) $this->argument('model') as $class) {
            $model = $this->resolveModel((string) $class);

            if (!$model) {
                return self::FAILURE;
            }

            if (!method_exists($model, 'getShardKey')) {
                $this->error($model::class . ' is not shardable: it does not use the Shardable trait.');

                return self::FAILURE;
            }

            $table = $model->getTable();
            $key = $model->getShardKey();
            // identity is the model's own primary key, whatever it is called:
            // paging and deleting by a hardcoded `id` breaks every model that
            // names its key something else
            $rowKey = $model->getKeyName();
            $connections = array_keys((array) $manager->connectionsFor($model));

            if ($connections === []) {
                $this->error('No shard connections are configured.');

                return self::FAILURE;
            }

            $group = $manager->groupFor($model);

            if ($group !== null) {
                $groups[$group] = true;
            }

            $swept[$table] = true;
            $this->info("Sweeping {$table} by {$key}...");

            foreach ($connections as $source) {
                if (!Schema::connection($source)->hasTable($table)) {
                    continue;
                }

                if ($foreignKeyDetector->hasForeignKeys($source, $table)) {
                    $this->error("Foreign key constraints detected on {$source}.{$table}. Drop them before sharding.");

                    return self::FAILURE;
                }

                if (!Schema::connection($source)->hasColumn($table, $key)) {
                    $this->warn("Skipping {$table} on {$source}: it has no {$key} column.");

                    continue;
                }

                [$found, $carried] = $this->sweep($manager, $table, $key, $rowKey, $source, $chunk, $dryRun);

                $misplaced += $found;
                $moved += $carried;
            }
        }

        $this->reportTablesLeft($groups, $swept);

        if ($dryRun) {
            $this->info("Rows not on the shard their key names: {$misplaced}.");

            return self::SUCCESS;
        }

        $this->info("Moved {$moved} row(s) to the shard their key names.");

        return self::SUCCESS;
    }

    /**
     * Name the tables of the groups touched that nobody asked to sweep.
     *
     * A group swept in part is the state this command exists to leave behind
     * nowhere: colocation only pays if every table of the group agrees about
     * where a key lives.
     *
     * @param array<string, bool> $groups The groups the given models belong to.
     * @param array<string, bool> $swept The tables actually swept.
     * @return void
     */
    protected function reportTablesLeft(array $groups, array $swept): void
    {
        $left = [];

        foreach (array_keys($groups) as $group) {
            foreach ((array) config("sharding.groups.{$group}") as $table) {
                if (!isset($swept[$table])) {
                    $left[$table] = true;
                }
            }
        }

        if ($left === []) {
            return;
        }

        $this->warn(
            'Not swept, and part of the same colocation: ' . implode(', ', array_keys($left))
            . '. Pass their models too — the column their key lives in is theirs to name.',
        );
    }

    /**
     * Walk one table on one connection, moving what does not belong there.
     *
     * Paged by the primary key rather than by offset, because the rows being
     * deleted underneath the walk are exactly the ones it is walking: an
     * offset would skip as many rows as it moved.
     *
     * @param ShardingManager $manager
     * @param string $table
     * @param string $key The shard key, which decides where a row belongs.
     * @param string $rowKey The primary key, which identifies one row.
     * @param string $source The connection being swept.
     * @param int $chunk
     * @param bool $dryRun
     * @return array{0: int, 1: int} How many were misplaced, and how many moved.
     */
    protected function sweep(
        ShardingManager $manager,
        string $table,
        string $key,
        string $rowKey,
        string $source,
        int $chunk,
        bool $dryRun,
    ): array {
        $misplaced = 0;
        $moved = 0;
        $after = null;

        do {
            $query = DB::connection($source)->table($table)->orderBy($rowKey)->limit($chunk);

            if ($after !== null) {
                $query->where($rowKey, '>', $after);
            }

            $rows = $query->get();

            foreach ($rows as $row) {
                $attributes = (array) $row;
                $after = $attributes[$rowKey] ?? $after;

                /*
                | A replica copy belongs on a replica connection rather than on
                | the primary the key names, so the rule this command applies
                | is not its rule. They are left where they are: a replica is
                | rebuilt from its primary, and moving one by the wrong rule
                | would break the pair.
                */
                if (!empty($attributes['is_replica'])) {
                    continue;
                }

                $value = $attributes[$key] ?? null;

                if ($value === null) {
                    continue;
                }

                $target = $manager->connectionFor($table, $value)[0] ?? null;

                if ($target === null || $target === $source) {
                    continue;
                }

                $misplaced++;

                if ($dryRun) {
                    continue;
                }

                $this->carry($table, $rowKey, $attributes, $source, $target);

                $moved++;
            }
        } while ($rows->count() === $chunk);

        return [$misplaced, $moved];
    }

    /**
     * Carry one row from where it is to where its key says it belongs.
     *
     * The target can already hold that primary key, and for two reasons that
     * both have to be handled rather than crashed on.
     *
     * **A replica copy of the same row.** With replication on — and the
     * default is one replica — the copy of a row frequently sits on exactly
     * the connection the key names, which on two shards is every time. An
     * unconditional insert then violates the primary key and takes the whole
     * repair down with it. The copy is promoted instead: it is already the
     * right bytes on the right shard, and all it lacks is being the primary.
     *
     * **A previous run interrupted between the write and the delete.** The
     * documented recovery is to run the command again, and that only works if
     * finding the row already there is a state rather than an error. The
     * source copy is removed and the count moves on.
     *
     * @param string $table
     * @param string $rowKey The primary key.
     * @param array<string, mixed> $attributes The row as it is on the source.
     * @param string $source
     * @param string $target
     * @return void
     */
    protected function carry(string $table, string $rowKey, array $attributes, string $source, string $target): void
    {
        $id = $attributes[$rowKey] ?? null;
        $existing = DB::connection($target)->table($table)->where($rowKey, $id)->first();

        if ($existing === null) {
            /*
            | Written before it is removed, and deliberately in that order.
            | Interrupted between the two this leaves the row on both shards,
            | which the branch above corrects on a re-run. The other order
            | loses the row.
            */
            DB::connection($target)->table($table)->insert($attributes);
            DB::connection($source)->table($table)->where($rowKey, $id)->delete();

            return;
        }

        if (!empty(((array) $existing)['is_replica'])) {
            DB::connection($target)->table($table)->where($rowKey, $id)->update(
                array_merge($attributes, ['is_replica' => false]),
            );
        }

        DB::connection($source)->table($table)->where($rowKey, $id)->delete();
    }

    /**
     * Resolve a model instance from the given class name.
     *
     * @param string $class
     * @return Model|null
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
