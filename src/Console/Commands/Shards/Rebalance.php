<?php

namespace Allnetru\Sharding\Console\Commands\Shards;

use Allnetru\Sharding\Console\Commands\Shards\Concerns\ResolvesShardModel;
use Allnetru\Sharding\Exceptions\RebalanceIncomplete;
use Allnetru\Sharding\ShardingManager;
use Allnetru\Sharding\Support\ShardedTable;
use Illuminate\Console\Command;
use InvalidArgumentException;

/**
 * Move records between shard connections.
 *
 * Takes model classes, one per table of the colocation group. A model
 * rather than a table name, for the two reasons `shards:distribute` takes it:
 * the table name alone cannot find the model on any application that keeps
 * its models outside `App\Models`, and only the model knows which column its
 * own shard key lives in — which for a colocated table is not the primary key.
 *
 * Every populated table of the group, because the routing a rebalance hands
 * over belongs to the group: moving one table's rows and redirecting the key
 * sends every sibling's reads to the new connection while their rows are
 * still on the old one. The tables move together and the routing changes
 * once, after all of them have arrived. A sibling with nothing in it may be
 * left out — it has nothing to strand — so a group whose later tables are
 * configured before they exist is still workable.
 *
 * `--start` and `--end` bound the shard key, because that is what a slot
 * is a range of. On a table keyed by `user_id` a range picks users and moves
 * every row each of them has, in every table of the group.
 */
class Rebalance extends Command
{
    use ResolvesShardModel;

    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'shards:rebalance {model* : The model classes of the tables to move, one per table of the group}
        {--from=}
        {--to=}
        {--start=}
        {--end=}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Move the rows of a colocation group between shards';

    /**
     * Execute the console command.
     *
     * @return int
     */
    public function handle(): int
    {
        $manager = app(ShardingManager::class);
        $tables = $this->tables($manager);

        if ($tables === null) {
            return self::FAILURE;
        }

        $from = $this->option('from');
        $to = $this->option('to');
        $start = $this->option('start');
        $end = $this->option('end');

        /*
        | Asked of the manager rather than read out of the config, because a
        | colocated child table only declares its group: its own entry has no
        | strategy and no slot size, so reading it directly fell back to the
        | default strategy and wrote the slot metadata under the child's name.
        | `strategyFor()` resolves the group's owner, which is where both the
        | strategy and the routing live — and it answers the same for every
        | table of the group, so the first one asks.
        */
        [$strategy, $config] = $manager->strategyFor($tables[0]->table);

        if (!$strategy->canRebalance()) {
            $this->error('Rebalancing is not supported for this strategy.');

            return self::FAILURE;
        }

        /*
        | Refused rather than cast. A slot is a numeric range, and `--start`
        | and `--end` bound the shard key — so a model whose shard key is a
        | string has no range this command can express. Casting was the old
        | behaviour and it was silent: `--start=tenant-a` became 0, and the run
        | selected an unrelated set of rows, quite possibly all of them.
        */
        foreach (['start' => $start, 'end' => $end] as $option => $value) {
            if ($value !== null && !is_numeric($value)) {
                $this->error(
                    "--{$option} has to be a number: it bounds {$tables[0]->table}.{$tables[0]->shardKey}, and a "
                    . 'slot is a numeric range. A string shard key has no range this command can express — '
                    . 'move the rows with --from and --to instead.',
                );

                return self::FAILURE;
            }
        }

        try {
            $moved = $strategy->rebalance(
                $tables,
                $from,
                $to,
                $start !== null ? (int) $start : null,
                $end !== null ? (int) $end : null,
                $config,
            );
        } catch (RebalanceIncomplete|InvalidArgumentException $e) {
            /*
            | Caught rather than left to bubble, so the operator gets the
            | sentence instead of a stack trace — but the status is a failure:
            | either rows are still on the connection this run was meant to
            | empty and the routing was deliberately not advanced, or the run
            | was refused before it started.
            */
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $this->info("Moved {$moved} records.");

        return self::SUCCESS;
    }

    /**
     * The tables to move, checked as a group before anything is read.
     *
     * Everything that can refuse the run is decided here, because a rebalance
     * that fails halfway is the state it exists to prevent: every model
     * resolves and is shardable, they all belong to one colocation group, no
     * populated table of that group has been left out, and every connection
     * that routes the group has every one of its tables.
     *
     * @param ShardingManager $manager
     * @return list<ShardedTable>|null The tables, or null when something refused the run.
     */
    protected function tables(ShardingManager $manager): ?array
    {
        $tables = [];

        foreach ((array) $this->argument('model') as $class) {
            $model = $this->resolveModel((string) $class);

            if (!$model) {
                return null;
            }

            /*
            | Asked of the manager and not only of the class: `method_exists`
            | alone accepts any model that happens to have a domain method by
            | that name, and this command rewrites where rows live.
            */
            if (!$manager->isShardable($model) || !method_exists($model, 'getShardKey')) {
                $this->error($model::class . ' is not shardable: it does not use the Shardable trait.');

                return null;
            }

            $tables[] = ShardedTable::of($model);
        }

        /*
        | One group, because one routing. A table outside any group is a group
        | of one and is named by its own table.
        */
        $groups = array_unique(array_map(
            static fn (ShardedTable $table): string => $manager->groupFor($table->table) ?? $table->table,
            $tables,
        ));

        if (count($groups) > 1) {
            $this->error(
                'These tables are not one colocation group: ' . implode(', ', $groups)
                . '. A rebalance hands one routing over, so it moves one group at a time.',
            );

            return null;
        }

        $named = array_map(static fn (ShardedTable $table): string => $table->table, $tables);
        $left = array_diff($this->populatedGroupSiblings($manager, $tables[0]->table), $named);

        if ($left !== []) {
            $this->error(
                'Holding rows and part of the same colocation, but not named: ' . implode(', ', $left)
                . '. The routing this would hand over belongs to the whole group, so their reads would '
                . 'follow it to the new connection while their rows stayed behind. Pass their models too.',
            );

            return null;
        }

        /*
        | Every connection that routes has to have every table. Leaving one
        | out of the scans does not leave it out of `connectionFor()`: the rows
        | would move, the routing would be handed over, and the pass that makes
        | the placement real would die on the missing table with the metadata
        | already advertising a replica that cannot exist. A connection listed
        | in DB_SHARD_MIGRATIONS is the exception, because nothing routes there
        | while it is on that list.
        */
        foreach ($tables as $table) {
            $missing = $this->connectionsWithoutTable($manager, $table->table);

            if ($missing !== []) {
                $this->error(
                    "These connections route {$table->table} and have no such table: " . implode(', ', $missing)
                    . '. Rows would be sent to them and the run would die partway. Migrate them first, or '
                    . 'list them in DB_SHARD_MIGRATIONS while they are being prepared.',
                );

                return null;
            }
        }

        return $tables;
    }
}
