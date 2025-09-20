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

        if (! $strategy->canRebalance()) {
            $this->error('Rebalancing is not supported for this strategy.');

            return self::FAILURE;
        }

        $model = $this->resolveModelByTable($table);
        if (! $model) {
            return self::FAILURE;
        }

        $key = $model->getKeyName();
        $moved = $strategy->rebalance($table, $key, $from, $to, $start !== null ? (int) $start : null, $end !== null ? (int) $end : null, $config);

        $this->info("Moved {$moved} records.");

        return self::SUCCESS;
    }

    /**
     * Resolve a model instance by table name.
     */
    protected function resolveModelByTable(string $table): ?Model
    {
        $modelClass = app()->getNamespace().'Models\\'.Str::studly(Str::singular($table));

        if (! class_exists($modelClass) || ! is_subclass_of($modelClass, Model::class)) {
            $this->error("Model for table {$table} not found.");

            return null;
        }

        return new $modelClass;
    }
}
