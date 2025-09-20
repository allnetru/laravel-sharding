<?php

namespace Allnetru\Sharding;

use Allnetru\Sharding\IdGenerators\Strategy;
use Illuminate\Database\Eloquent\Model;
use RuntimeException;

/**
 * Resolve and execute ID generation strategies.
 */
class IdGenerator
{
    /**
     * @var array<string, mixed>
     */
    protected array $config;

    /**
     * Create a new generator instance.
     *
     * @param array<string, mixed>|null $config
     * @return void
     */
    public function __construct(?array $config = null)
    {
        $this->config = $config ?? config('sharding');
    }

    /**
     * Generate an ID for the given model.
     *
     * @param Model|string $model
     * @return int
     */
    public function generate(Model|string $model): int
    {
        $table = is_string($model) ? $model : $model->getTable();
        $tables = $this->config['tables'] ?? [];
        $tableConfig = $tables[$table] ?? [];
        $idConfig = $this->config['id_generator'] ?? [];

        $strategyName = $tableConfig['id_generator'] ?? $idConfig['default'] ?? null;
        $strategyClass = $idConfig['strategies'][$strategyName] ?? null;

        if (!$strategyClass) {
            throw new RuntimeException("ID generator strategy [$strategyName] not configured.");
        }

        /** @var Strategy $strategy */
        $strategy = app($strategyClass);

        $config = array_merge($idConfig, $tableConfig);
        unset($config['strategies'], $config['default']);
        $config['table'] = $table;

        return $strategy->generate($config);
    }
}
