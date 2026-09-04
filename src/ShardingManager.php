<?php

namespace Allnetru\Sharding;

use Allnetru\Sharding\Models\Concerns\Shardable;
use Allnetru\Sharding\Strategies\Strategy;
use Illuminate\Database\Eloquent\Model;
use RuntimeException;

class ShardingManager
{
    /**
     * @var array<string, mixed>
     */
    protected array $config;

    /**
     * Create a new sharding manager instance.
     *
     * @param array<string, mixed>|null $config
     * @return void
     */
    public function __construct(?array $config = null)
    {
        $this->config = $config ?? config('sharding');
    }

    /**
     * Get configured connections for the given model or table.
     *
     * @param Model|string $model
     * @return array<string, mixed>
     */
    public function connectionsFor(Model|string $model): array
    {
        $table = $this->resolveTable(is_string($model) ? $model : $model->getTable());
        $tables = $this->config['tables'] ?? [];

        if (isset($tables[$table]['connections'])) {
            return $tables[$table]['connections'];
        }

        return $this->config['connections'] ?? [];
    }

    /**
     * Determine connection names for given model and key.
     *
     * @param Model|string $model
     * @param mixed $key
     * @return array<int, string>
     */
    public function connectionFor(Model|string $model, mixed $key): array
    {
        [$strategy, $tableConfig] = $this->strategyFor($model);

        $migrations = $this->config['migrations'] ?? [];

        if ($migrations) {
            $tableConfig['connections'] = array_diff_key(
                $tableConfig['connections'],
                $migrations
            );
        }

        $connections = $strategy->determine($key, $tableConfig);

        return $connections;
    }

    /**
     * Resolve strategy instance and configuration for model or table.
     *
     * @param Model|string $model
     * @return array{0: Strategy, 1: array<string, mixed>}
     */
    public function strategyFor(Model|string $model): array
    {
        $table = $this->resolveTable(is_string($model) ? $model : $model->getTable());
        $tables = $this->config['tables'] ?? [];
        $tableConfig = $tables[$table] ?? [];
        $strategyName = $tableConfig['strategy'] ?? $this->config['default'] ?? null;
        $strategyClass = $this->config['strategies'][$strategyName] ?? null;

        if (!$strategyClass) {
            throw new RuntimeException("Sharding strategy [$strategyName] not configured.");
        }

        /** @var Strategy $strategy */
        $strategy = app($strategyClass);
        $tableConfig['connections'] = $tableConfig['connections'] ?? ($this->config['connections'] ?? []);
        $tableConfig['replica_count'] = $tableConfig['replica_count'] ?? ($this->config['replica_count'] ?? 0);
        $tableConfig['table'] = $table;

        return [$strategy, $tableConfig];
    }

    /**
     * Determine whether the given model or table is sharded at all.
     *
     * Needed because a relation from a sharded model can point at a global
     * table: reference data lives on the default connection, and routing such
     * a query to a shard sends it where the table does not exist. Without this
     * check `strategyFor()` cannot tell the two apart, since it silently falls
     * back to the default strategy for unknown tables.
     *
     * The trait is the signal rather than the config: a model that uses
     * Shardable is sharded by definition, and a table can be missing from the
     * config by mistake, which is exactly the case this check must not treat
     * as global.
     *
     * @param Model|string $model
     * @return bool
     */
    public function isShardable(Model|string $model): bool
    {
        if ($model instanceof Model) {
            return in_array(Shardable::class, class_uses_recursive($model), true);
        }

        $tables = $this->config['tables'] ?? [];

        if (array_key_exists($model, $tables)) {
            return true;
        }

        foreach ($this->config['groups'] ?? [] as $group) {
            if (in_array($model, (array) $group, true)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Get the group name for the given model or table.
     *
     * @param Model|string $model
     * @return string|null
     */
    public function groupFor(Model|string $model): ?string
    {
        $table = is_string($model) ? $model : $model->getTable();
        foreach ($this->config['groups'] ?? [] as $group => $tables) {
            if (in_array($table, $tables, true)) {
                return $group;
            }
        }

        return null;
    }

    /**
     * Resolve actual table name from group configuration.
     *
     * @param string $table
     * @return string
     */
    protected function resolveTable(string $table): string
    {
        foreach ($this->config['groups'] ?? [] as $tables) {
            if (in_array($table, $tables, true)) {
                return $tables[0];
            }
        }

        return $table;
    }
}
