<?php

namespace Allnetru\Sharding\Console\Commands\Shards;

use Allnetru\Sharding\ShardingManager;
use Illuminate\Console\Command;
use Illuminate\Database\Connection;
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
     * @return int
     */
    public function handle(ShardingManager $manager): int
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

            if ($this->hasForeignKeys($sourceConnection, $table)) {
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
     * Determine if the given table has foreign key constraints.
     *
     * @param string $connection
     * @param string $table
     * @return bool
     */
    protected function hasForeignKeys(string $connection, string $table): bool
    {
        $dbConnection = DB::connection($connection);

        return match ($dbConnection->getDriverName()) {
            'mysql', 'mariadb' => $this->hasMySqlForeignKeys($dbConnection, $table),
            'pgsql' => $this->hasPostgresForeignKeys($dbConnection, $table),
            'sqlsrv' => $this->hasSqlServerForeignKeys($dbConnection, $table),
            'sqlite' => $this->hasSqliteForeignKeys($dbConnection, $table),
            default => false,
        };
    }

    /**
     * Determine whether a MySQL-compatible connection has foreign keys for the given table.
     */
    private function hasMySqlForeignKeys(Connection $connection, string $table): bool
    {
        $database = $connection->getDatabaseName();

        $foreign = $connection->select(
            'SELECT 1 FROM information_schema.KEY_COLUMN_USAGE WHERE TABLE_SCHEMA = ? AND (TABLE_NAME = ? OR REFERENCED_TABLE_NAME = ?) AND REFERENCED_TABLE_NAME IS NOT NULL LIMIT 1',
            [$database, $table, $table]
        );

        return !empty($foreign);
    }

    /**
     * Determine whether a PostgreSQL connection has foreign keys for the given table.
     */
    private function hasPostgresForeignKeys(Connection $connection, string $table): bool
    {
        [$schema, $tableName] = $this->splitQualifiedTable($table);

        if ($schema === null) {
            $schema = $this->resolvePostgresDefaultSchema($connection);
        }

        $foreign = $connection->select(
            <<<'SQL'
SELECT 1
FROM pg_catalog.pg_constraint c
JOIN pg_catalog.pg_class tc ON c.conrelid = tc.oid
JOIN pg_catalog.pg_namespace tn ON tn.oid = tc.relnamespace
LEFT JOIN pg_catalog.pg_class rc ON c.confrelid = rc.oid
LEFT JOIN pg_catalog.pg_namespace rn ON rn.oid = rc.relnamespace
WHERE c.contype = 'f'
  AND (
        (tn.nspname = ? AND tc.relname = ?)
        OR (rn.nspname = ? AND rc.relname = ?)
      )
LIMIT 1
SQL,
            [$schema, $tableName, $schema, $tableName]
        );

        return !empty($foreign);
    }

    /**
     * Determine whether a SQL Server connection has foreign keys for the given table.
     */
    private function hasSqlServerForeignKeys(Connection $connection, string $table): bool
    {
        [$schema, $tableName] = $this->splitQualifiedTable($table);

        if ($schema === null) {
            $schema = $this->resolveSqlServerDefaultSchema($connection);
        }

        $foreign = $connection->select(
            <<<'SQL'
SELECT TOP 1 1
FROM sys.foreign_keys fk
JOIN sys.tables parent ON fk.parent_object_id = parent.object_id
JOIN sys.schemas parent_schema ON parent.schema_id = parent_schema.schema_id
LEFT JOIN sys.tables referenced ON fk.referenced_object_id = referenced.object_id
LEFT JOIN sys.schemas referenced_schema ON referenced.schema_id = referenced_schema.schema_id
WHERE (parent_schema.name = ? AND parent.name = ?)
   OR (referenced_schema.name = ? AND referenced.name = ?)
SQL,
            [$schema, $tableName, $schema, $tableName]
        );

        return !empty($foreign);
    }

    /**
     * Determine whether a SQLite connection has foreign keys for the given table.
     */
    private function hasSqliteForeignKeys(Connection $connection, string $table): bool
    {
        $tableName = $this->normalizeSqliteTableName($table);

        if (!empty($this->fetchSqliteForeignKeys($connection, $tableName))) {
            return true;
        }

        $tables = $connection->select("SELECT name FROM sqlite_master WHERE type = 'table' AND name NOT LIKE 'sqlite_%'");

        foreach ($tables as $candidate) {
            $candidateName = $candidate->name ?? null;

            if ($candidateName === null || $candidateName === $tableName) {
                continue;
            }

            foreach ($this->fetchSqliteForeignKeys($connection, $candidateName) as $foreignKey) {
                if (($foreignKey->table ?? null) === $tableName) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * Extract the schema-qualified parts from the provided table name.
     *
     * @return array{0:string|null,1:string}
     */
    private function splitQualifiedTable(string $table): array
    {
        if (str_contains($table, '.')) {
            [$schema, $tableName] = explode('.', $table, 2);

            return [$schema, $tableName];
        }

        return [null, $table];
    }

    private function resolvePostgresDefaultSchema(Connection $connection): string
    {
        $schema = $connection->getConfig('schema');

        if (is_array($schema)) {
            $schema = $schema[0] ?? null;
        }

        if (is_string($schema) && $schema !== '') {
            return $schema;
        }

        $result = $connection->selectOne('select current_schema as schema');

        if ($result !== null && isset($result->schema) && is_string($result->schema) && $result->schema !== '') {
            return $result->schema;
        }

        return 'public';
    }

    private function resolveSqlServerDefaultSchema(Connection $connection): string
    {
        $schema = $connection->getConfig('schema');

        if (is_array($schema)) {
            $schema = $schema[0] ?? null;
        }

        if (is_string($schema) && $schema !== '') {
            return $schema;
        }

        $result = $connection->selectOne('SELECT SCHEMA_NAME() AS schema_name');

        if ($result !== null && isset($result->schema_name) && is_string($result->schema_name) && $result->schema_name !== '') {
            return $result->schema_name;
        }

        return 'dbo';
    }

    /**
     * @return array<int, object>
     */
    private function fetchSqliteForeignKeys(Connection $connection, string $table): array
    {
        $quoted = str_replace("'", "''", $table);

        return $connection->select("PRAGMA foreign_key_list('{$quoted}')");
    }

    private function normalizeSqliteTableName(string $table): string
    {
        return str_replace(['"', "'", '`'], '', $table);
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
