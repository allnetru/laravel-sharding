<?php

namespace Allnetru\Sharding\Console\Commands\Shards;

use Allnetru\Sharding\Console\Commands\Shards\Concerns\ResolvesShardModel;
use Allnetru\Sharding\Exceptions\RebalanceIncomplete;
use Allnetru\Sharding\ShardingManager;
use Illuminate\Console\Command;

/**
 * Move records between shard connections.
 *
 * **Takes the model class rather than the table name**, for the two reasons
 * `shards:distribute` takes it: the table name alone cannot find the model on
 * any application that keeps its models outside `App\Models` — this command
 * simply could not run there — and only the model knows which column its own
 * shard key lives in, which for a colocated table is not the primary key.
 *
 * `--start` and `--end` bound the **shard key**, because that is what a slot
 * is a range of. On a table keyed by `user_id` a range picks users and moves
 * every row each of them has.
 */
class Rebalance extends Command
{
    use ResolvesShardModel;

    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'shards:rebalance {model : The model class of the table to rebalance}
        {--from=}
        {--to=}
        {--start=}
        {--end=}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Move data between shards';

    /**
     * Execute the console command.
     *
     * @return int
     */
    public function handle(): int
    {
        $model = $this->resolveModel((string) $this->argument('model'));

        if (!$model) {
            return self::FAILURE;
        }

        $table = $model->getTable();
        $from = $this->option('from');
        $to = $this->option('to');
        $start = $this->option('start');
        $end = $this->option('end');

        /*
        | Asked of the manager rather than read out of the config, because a
        | colocated child table only declares its group: its own entry has no
        | strategy and no slot size, so reading it directly fell back to the
        | default strategy and wrote the slot metadata under the child's name.
        | The rows moved and keyed reads went on being routed to the shard the
        | metadata still named. `strategyFor()` resolves the group's owner,
        | which is where both the strategy and the slots live.
        */
        [$strategy, $config] = app(ShardingManager::class)->strategyFor($table);

        if (!$strategy->canRebalance()) {
            $this->error('Rebalancing is not supported for this strategy.');

            return self::FAILURE;
        }

        /*
        | Two keys, and mixing them up costs different things. The shard key is
        | what a slot is computed from, so it decides routing and the range;
        | routing by the primary key instead moves rows the slot change never
        | asked about and leaves the ones it did, after which the slot table
        | says something untrue about where the data is.
        |
        | The row key is what identifies one row. On a colocated one-to-many
        | table — several roles for one user — identifying by the shard key
        | means deleting every one of that user's rows after moving one of
        | them. Routing by the wrong key misplaces rows; identifying by the
        | wrong key destroys them.
        */
        $shardKey = method_exists($model, 'getShardKey') ? $model->getShardKey() : $model->getKeyName();
        $rowKey = $model->getKeyName();

        try {
            $moved = $strategy->rebalance(
                $table,
                $shardKey,
                $rowKey,
                $from,
                $to,
                $start !== null ? (int) $start : null,
                $end !== null ? (int) $end : null,
                $config,
            );
        } catch (RebalanceIncomplete $e) {
            /*
            | Caught rather than left to bubble, so the operator gets the
            | sentence instead of a stack trace — but the status is a failure,
            | because rows are still on the connection this run was meant to
            | empty and the routing was deliberately not advanced.
            */
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $this->info("Moved {$moved} records.");

        return self::SUCCESS;
    }
}
