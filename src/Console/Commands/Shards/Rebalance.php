<?php

namespace Allnetru\Sharding\Console\Commands\Shards;

use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/**
 * Move records between shard connections.
 */
class Rebalance extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'shards:rebalance {table} {--from=} {--to=} {--start=} {--end=}';

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
        $table = $this->argument('table');
        $from = $this->option('from');
        $to = $this->option('to');
        $start = $this->option('start');
        $end = $this->option('end');

        $config = config("sharding.tables.{$table}", []);
        $strategyName = $config['strategy'] ?? config('sharding.default');
        $strategyClass = config("sharding.strategies.{$strategyName}");

        /** @var \Allnetru\Sharding\Strategies\Strategy $strategy */
        $strategy = app($strategyClass);
        $config['connections'] = $config['connections'] ?? (config('sharding.connections') ?? []);
        $config['table'] = $table;

        if (!$strategy->canRebalance()) {
            $this->error('Rebalancing is not supported for this strategy.');

            return self::FAILURE;
        }

        $model = $this->resolveModelByTable($table);
        if (!$model) {
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

        $this->info("Moved {$moved} records.");

        return self::SUCCESS;
    }

    /**
     * Resolve a model instance by table name.
     *
     * @param string $table
     * @return Model|null
     */
    protected function resolveModelByTable(string $table): ?Model
    {
        $modelClass = app()->getNamespace() . 'Models\\' . Str::studly(Str::singular($table));

        if (!class_exists($modelClass) || !is_subclass_of($modelClass, Model::class)) {
            $this->error("Model for table {$table} not found.");

            return null;
        }

        return new $modelClass();
    }
}
