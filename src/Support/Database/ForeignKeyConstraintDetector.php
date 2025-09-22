<?php

namespace Allnetru\Sharding\Support\Database;

use Illuminate\Database\Connection;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Facades\DB;

/**
 * Detects whether a table participates in foreign key relationships on a connection.
 */
final class ForeignKeyConstraintDetector
{
    /**
     * Determine if the given table has foreign key constraints.
     *
     * @param string $connection
     * @param string $table
     * @return bool
     */
    public function hasForeignKeys(string $connection, string $table): bool
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
     *
     * @param Connection $connection
     * @param string $table
     * @return bool
     */
    private function hasMySqlForeignKeys(Connection $connection, string $table): bool
    {
        $database = $connection->getDatabaseName();

        return $connection->table('information_schema.KEY_COLUMN_USAGE')
            ->where('TABLE_SCHEMA', $database)
            ->where(function (QueryBuilder $query) use ($table): void {
                $query->where('TABLE_NAME', $table)
                    ->orWhere('REFERENCED_TABLE_NAME', $table);
            })
            ->whereNotNull('REFERENCED_TABLE_NAME')
            ->exists();
    }

    /**
     * Determine whether a PostgreSQL connection has foreign keys for the given table.
     *
     * @param Connection $connection
     * @param string $table
     * @return bool
     */
    private function hasPostgresForeignKeys(Connection $connection, string $table): bool
    {
        [$schema, $tableName] = $this->splitQualifiedTable($table);

        if ($schema === null) {
            $schema = $this->resolvePostgresDefaultSchema($connection);
        }

        return $connection->table('pg_catalog.pg_constraint as c')
            ->join('pg_catalog.pg_class as tc', 'c.conrelid', '=', 'tc.oid')
            ->join('pg_catalog.pg_namespace as tn', 'tn.oid', '=', 'tc.relnamespace')
            ->leftJoin('pg_catalog.pg_class as rc', 'c.confrelid', '=', 'rc.oid')
            ->leftJoin('pg_catalog.pg_namespace as rn', 'rn.oid', '=', 'rc.relnamespace')
            ->where('c.contype', 'f')
            ->where(function (QueryBuilder $query) use ($schema, $tableName): void {
                $query->where(function (QueryBuilder $query) use ($schema, $tableName): void {
                    $query->where('tn.nspname', $schema)
                        ->where('tc.relname', $tableName);
                })->orWhere(function (QueryBuilder $query) use ($schema, $tableName): void {
                    $query->where('rn.nspname', $schema)
                        ->where('rc.relname', $tableName);
                });
            })
            ->exists();
    }

    /**
     * Determine whether a SQL Server connection has foreign keys for the given table.
     *
     * @param Connection $connection
     * @param string $table
     * @return bool
     */
    private function hasSqlServerForeignKeys(Connection $connection, string $table): bool
    {
        [$schema, $tableName] = $this->splitQualifiedTable($table);

        if ($schema === null) {
            $schema = $this->resolveSqlServerDefaultSchema($connection);
        }

        return $connection->table('sys.foreign_keys as fk')
            ->join('sys.tables as parent', 'fk.parent_object_id', '=', 'parent.object_id')
            ->join('sys.schemas as parent_schema', 'parent.schema_id', '=', 'parent_schema.schema_id')
            ->leftJoin('sys.tables as referenced', 'fk.referenced_object_id', '=', 'referenced.object_id')
            ->leftJoin('sys.schemas as referenced_schema', 'referenced.schema_id', '=', 'referenced_schema.schema_id')
            ->where(function (QueryBuilder $query) use ($schema, $tableName): void {
                $query->where(function (QueryBuilder $query) use ($schema, $tableName): void {
                    $query->where('parent_schema.name', $schema)
                        ->where('parent.name', $tableName);
                })->orWhere(function (QueryBuilder $query) use ($schema, $tableName): void {
                    $query->where('referenced_schema.name', $schema)
                        ->where('referenced.name', $tableName);
                });
            })
            ->exists();
    }

    /**
     * Determine whether a SQLite connection has foreign keys for the given table.
     *
     * @param Connection $connection
     * @param string $table
     * @return bool
     */
    private function hasSqliteForeignKeys(Connection $connection, string $table): bool
    {
        $tableName = $this->normalizeSqliteTableName($table);

        if (!empty($this->fetchSqliteForeignKeys($connection, $tableName))) {
            return true;
        }

        $tables = $connection->table('sqlite_master')
            ->select('name')
            ->where('type', 'table')
            ->where('name', 'not like', 'sqlite_%')
            ->get();

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
     * @param string $table
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

    /**
     * @param Connection $connection
     * @return string
     */
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

    /**
     * @param Connection $connection
     * @return string
     */
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
     * @param Connection $connection
     * @param string $table
     * @return array<int, object>
     */
    private function fetchSqliteForeignKeys(Connection $connection, string $table): array
    {
        $quoted = str_replace("'", "''", $table);

        return $connection->select("PRAGMA foreign_key_list('{$quoted}')");
    }

    /**
     * @param string $table
     * @return string
     */
    private function normalizeSqliteTableName(string $table): string
    {
        return str_replace(['"', "'", '`'], '', $table);
    }
}
