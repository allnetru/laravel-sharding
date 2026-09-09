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

        $plan = $this->plan($manager, $foreignKeyDetector);

        if ($plan === null) {
            return self::FAILURE;
        }

        $moved = 0;
        $misplaced = 0;
        $collisions = 0;
        $swept = [];
        $groups = [];

        foreach ($plan as $step) {
            $swept[$step['table']] = true;

            if ($step['group'] !== null) {
                $groups[$step['group']] = true;
            }

            $this->info("Sweeping {$step['table']} by {$step['key']}...");

            foreach ($step['sources'] as $source) {
                [$found, $carried, $clashed] = $this->sweep(
                    $manager,
                    $step['table'],
                    $step['key'],
                    $step['rowKey'],
                    $source,
                    $chunk,
                    $dryRun,
                );

                $misplaced += $found;
                $moved += $carried;
                $collisions += $clashed;
            }
        }

        $this->reportTablesLeft($groups, $swept);

        if ($dryRun) {
            $this->info("Rows not on the shard their key names: {$misplaced}.");

            return self::SUCCESS;
        }

        $this->info("Moved {$moved} row(s) to the shard their key names.");

        if ($collisions > 0) {
            $this->error(
                "Left in place: {$collisions} row(s) whose primary key is already taken on the target by a "
                . 'different row. Both copies are kept; reconcile them by hand and run the command again.',
            );

            return self::FAILURE;
        }

        return self::SUCCESS;
    }

    /**
     * Work out every sweep before the first row moves.
     *
     * Resolving the models as the sweeps run means a name misspelled in the
     * fourth argument is discovered after the first three tables have already
     * been rewritten, which for a colocation group leaves exactly the state
     * this command exists to prevent: part of the group agreeing about where a
     * key lives and part of it not. The early return also skipped
     * reportTablesLeft(), so nothing said which tables were left behind.
     *
     * Everything that can refuse the run is therefore checked here — the class
     * exists, it is shardable, it has connections, and none of the tables it
     * lives in carries a foreign key — and the sweeps start only once all of
     * it has passed.
     *
     * @param ShardingManager $manager
     * @param ForeignKeyConstraintDetector $foreignKeyDetector
     * @return list<array{table: string, key: string, rowKey: string, group: string|null, sources: list<string>}>|null
     *         The sweeps to run, or null when something refused the run.
     */
    protected function plan(ShardingManager $manager, ForeignKeyConstraintDetector $foreignKeyDetector): ?array
    {
        $plan = [];

        foreach ((array) $this->argument('model') as $class) {
            $model = $this->resolveModel((string) $class);

            if (!$model) {
                return null;
            }

            /*
            | Asked of the manager and not only of the class. `method_exists`
            | alone accepts any model that happens to have a domain method by
            | that name, and this command rewrites where rows live; the
            | manager checks for the trait itself. The second half of the
            | condition is what is about to be called, and it is also what
            | tells the analyser this model has it.
            */
            if (!$manager->isShardable($model) || !method_exists($model, 'getShardKey')) {
                $this->error($model::class . ' is not shardable: it does not use the Shardable trait.');

                return null;
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

                return null;
            }

            $sources = [];

            foreach ($connections as $source) {
                if (!Schema::connection($source)->hasTable($table)) {
                    continue;
                }

                if ($foreignKeyDetector->hasForeignKeys($source, $table)) {
                    $this->error("Foreign key constraints detected on {$source}.{$table}. Drop them before sharding.");

                    return null;
                }

                if (!Schema::connection($source)->hasColumn($table, $key)) {
                    $this->warn("Skipping {$table} on {$source}: it has no {$key} column.");

                    continue;
                }

                $sources[] = $source;
            }

            $plan[] = [
                'table' => $table,
                'key' => $key,
                'rowKey' => $rowKey,
                'group' => $manager->groupFor($model),
                'sources' => $sources,
            ];
        }

        return $plan;
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
     * @return array{0: int, 1: int, 2: int} How many were misplaced, how many moved,
     *         and how many were left alone because the key was already taken there.
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
        $collisions = 0;
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

                /*
                | The whole placement and not only its head: the tail names the
                | connections this key's replicas belong on, and the source may
                | be one of them.
                */
                $placement = array_values((array) $manager->connectionFor($table, $value));
                $target = $placement[0] ?? null;

                if ($target === null || $target === $source) {
                    continue;
                }

                $misplaced++;

                if ($dryRun) {
                    continue;
                }

                $clash = $this->carry($table, $rowKey, $attributes, $source, $placement);

                if ($clash === null) {
                    $moved++;

                    continue;
                }

                $collisions++;
                $this->warn(
                    "{$table}.{$rowKey} {$attributes[$rowKey]} is held on {$clash} by a different row; "
                    . "the copy on {$source} is left where it is.",
                );
            }
        } while ($rows->count() === $chunk);

        return [$misplaced, $moved, $collisions];
    }

    /**
     * Carry one row from where it is to where its key says it belongs.
     *
     * **Where it belongs is the whole placement and not only its head.** The
     * head is the primary, the tail names the connections this key's replicas
     * belong on, and a run that creates the primary and stops reports success
     * while the metadata goes on advertising replicas that hold nothing. These
     * are raw table writes, so no `created` hook builds them afterwards. With
     * three shards and one replica the source is frequently not one of the
     * replica connections, so there is nothing left behind to demote either —
     * the row has to be written there.
     *
     * **Nothing is overwritten before the rows are compared.** A connection
     * can already hold that primary key for three reasons, and only the third
     * is a refusal: a replica of this same row, this same row left by a run
     * interrupted between the write and the delete, or a different row that
     * happens to share the identifier. That last one is what adopting sharding
     * over databases which counted their own identifiers looks like, and both
     * rows are real data. What the occupant claims to be does not settle it —
     * a replica marked as such can be the replica of that other row, and
     * promoting it destroys a copy the metadata still promises. So the
     * comparison comes first and applies to every occupant.
     *
     * @param string $table
     * @param string $rowKey The primary key.
     * @param array<string, mixed> $attributes The row as it is on the source.
     * @param string $source
     * @param list<string> $placement The connections this key belongs on, the primary first.
     * @return string|null The connection whose occupant is a different row, or null when the row arrived.
     */
    protected function carry(string $table, string $rowKey, array $attributes, string $source, array $placement): ?string
    {
        $target = $placement[0];
        $replicas = array_slice($placement, 1);
        $id = $attributes[$rowKey] ?? null;

        /*
        | Written before it is removed, and deliberately in that order.
        | Interrupted between the two this leaves the row on both connections,
        | which the comparison corrects on a re-run. The other order loses the
        | row.
        */
        if (!$this->place($table, $rowKey, $id, $attributes, $target, false)) {
            return $target;
        }

        foreach ($replicas as $replica) {
            /*
            | The copy on the source becomes this replica rather than being
            | written a second time — it is already the right bytes on the
            | right connection.
            */
            if ($replica === $source) {
                continue;
            }

            if (!$this->place($table, $rowKey, $id, $attributes, $replica, true)) {
                return $replica;
            }
        }

        $this->releaseSource($table, $rowKey, $id, $attributes, $source, $replicas);

        return null;
    }

    /**
     * Put the row on one connection, as the primary or as a replica.
     *
     * @param string $table
     * @param string $rowKey
     * @param mixed $id
     * @param array<string, mixed> $attributes The row as it is on the source.
     * @param string $connection
     * @param bool $asReplica Whether this connection holds a copy rather than the row.
     * @return bool Whether the connection now holds it; false when a different row is in the way.
     */
    protected function place(
        string $table,
        string $rowKey,
        mixed $id,
        array $attributes,
        string $connection,
        bool $asReplica,
    ): bool {
        $marks = array_key_exists('is_replica', $attributes);

        // a table without the column cannot say which copy is the row, so it
        // cannot hold replicas at all and there is nothing to write there
        if ($asReplica && !$marks) {
            return true;
        }

        $wanted = $marks ? array_merge($attributes, ['is_replica' => $asReplica]) : $attributes;
        $existing = DB::connection($connection)->table($table)->where($rowKey, $id)->first();

        if ($existing === null) {
            DB::connection($connection)->table($table)->insert($wanted);

            return true;
        }

        if (!$this->sameRow((array) $existing, $attributes)) {
            return false;
        }

        // already this row: the write settles which copy it is, and repeating
        // it is what makes an interrupted run recoverable
        DB::connection($connection)->table($table)->where($rowKey, $id)->update($wanted);

        return true;
    }

    /**
     * Let go of the copy on the connection the row is leaving.
     *
     * @param string $table
     * @param string $rowKey
     * @param mixed $id
     * @param array<string, mixed> $attributes The row as it is on the source.
     * @param string $source
     * @param list<string> $replicas The connections this key's replicas belong on.
     * @return void
     */
    protected function releaseSource(
        string $table,
        string $rowKey,
        mixed $id,
        array $attributes,
        string $source,
        array $replicas,
    ): void {
        $row = DB::connection($source)->table($table)->where($rowKey, $id);

        // a table with no such column cannot hold a replica at all, so there
        // is nothing to demote it to
        if (in_array($source, $replicas, true) && array_key_exists('is_replica', $attributes)) {
            $row->update(['is_replica' => true]);

            return;
        }

        $row->delete();
    }

    /**
     * Whether two copies of a primary key hold the same row.
     *
     * Compared as strings because the two connections are two drivers'
     * opinions about what a column reads back as, and leniently in exactly
     * that respect only: a missing column, a differing null, or any differing
     * value answers no. The comparison decides whether a row may be deleted,
     * so it errs towards keeping both.
     *
     * `is_replica` is left out of it — which copy is primary is what is being
     * decided, not evidence about which row this is.
     *
     * @param array<string, mixed> $target
     * @param array<string, mixed> $source
     * @return bool
     */
    protected function sameRow(array $target, array $source): bool
    {
        unset($target['is_replica'], $source['is_replica']);

        if (array_keys($target) !== array_keys($source)) {
            return false;
        }

        foreach ($source as $column => $value) {
            $other = $target[$column];

            if ($value === null || $other === null) {
                if ($value !== $other) {
                    return false;
                }

                continue;
            }

            if ((string) $other !== (string) $value) {
                return false;
            }
        }

        return true;
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
