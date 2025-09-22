<?php

namespace Allnetru\Sharding\Console\Commands\Shards;

use Allnetru\Sharding\ShardingManager;
use Allnetru\Sharding\Support\Database\ForeignKeyConstraintDetector;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Redistribute existing data across configured shard connections.
 */
class Distribute extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'shards:distribute {model} {--chunk=1000}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Distribute existing table data across shard connections.';

    /**
     * Execute the console command.
     *
     * @param ShardingManager $manager
     * @param ForeignKeyConstraintDetector $foreignKeyDetector
     * @return int
     */
    public function handle(ShardingManager $manager, ForeignKeyConstraintDetector $foreignKeyDetector): int
    {
        $model = $this->resolveModel($this->argument('model'));

        if (!$model) {
            return self::FAILURE;
        }

        $chunk = (int) $this->option('chunk');
        $group = $manager->groupFor($model);
        $tables = $group ? config("sharding.groups.{$group}") : [$model->getTable()];

        foreach ($tables as $table) {
            $tableModel = $table === $model->getTable() ? $model : $this->resolveModelByTable($table);

            if (!$tableModel) {
                return self::FAILURE;
            }

            $sourceConnection = $tableModel->getConnectionName() ?: config('database.default');

            if ($foreignKeyDetector->hasForeignKeys($sourceConnection, $table)) {
                $this->error("Foreign key constraints detected for table {$table}. Drop them before sharding.");

                return self::FAILURE;
            }
        }

        $moved = 0;

        foreach ($tables as $table) {
            $this->info("Processing {$table}...");
            $tableModel = $table === $model->getTable() ? $model : $this->resolveModelByTable($table);

            if (!$tableModel) {
                return self::FAILURE;
            }

            $key = $tableModel->getKeyName();
            $sourceConnection = $tableModel->getConnectionName() ?: config('database.default');
            $total = $tableModel->newQuery()->count();
            $bar = $this->output->createProgressBar($total);
            $bar->start();

            $tableModel->newQuery()->chunkById($chunk, function ($rows) use ($table, $key, $manager, $sourceConnection, &$moved, $bar): void {
                foreach ($rows as $row) {
                    $targetConnections = $manager->connectionFor($table, $row->$key);
                    $target = $targetConnections[0];

                    if ($target !== $sourceConnection) {
                        DB::connection($target)->table($table)->insert($row->getAttributes());
                        $row->delete();
                        $moved++;
                    }

                    $bar->advance();
                }
            });

            $bar->finish();
            $this->newLine();
        }

        $this->info("Moved {$moved} records to shards.");

        return self::SUCCESS;
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
