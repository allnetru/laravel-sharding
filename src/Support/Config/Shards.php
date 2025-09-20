<?php

namespace Allnetru\Sharding\Support\Config;

use Illuminate\Support\Facades\Log;
use PDO;

/**
 * Helpers to build sharding configuration from environment variables.
 */
class Shards
{
    /**
     * Base configuration applied to all shard connections.
     *
     * @return array<string, mixed>
     */
    protected static function baseConfig(): array
    {
        return [
            'driver' => env('DB_SHARD_DRIVER', 'mysql'),
            'username' => env('DB_USERNAME', 'forge'),
            'password' => env('DB_PASSWORD', ''),
            'charset' => env('DB_CHARSET', 'utf8mb4'),
            'collation' => env('DB_COLLATION', 'utf8mb4_unicode_ci'),
            'prefix' => '',
            'prefix_indexes' => true,
            'strict' => true,
            'engine' => null,
            'options' => extension_loaded('pdo_mysql') ? array_filter([
                PDO::MYSQL_ATTR_SSL_CA => env('MYSQL_ATTR_SSL_CA'),
            ]) : [],
        ];
    }

    /**
     * Build shard database connections from DB_SHARDS.
     *
     * @return array<string, array<string, mixed>>
     */
    public static function databaseConnections(): array
    {
        $baseConfig = self::baseConfig();

        return collect(explode(';', (string) env('DB_SHARDS')))
            ->filter()
            ->mapWithKeys(function (string $dsn) use ($baseConfig) {
                [$name, $host, $port, $database] = array_pad(explode(':', trim($dsn)), 4, null);

                if (! $name || ! $host || ! $database) {
                    Log::warning(sprintf('Invalid shard DSN: %s', $dsn));

                    return [];
                }

                return [
                    $name => array_merge($baseConfig, [
                        'host' => $host,
                        'port' => $port ?: env('DB_PORT', '3306'),
                        'database' => $database,
                    ]),
                ];
            })
            ->all();
    }

    /**
     * Build shard weight configuration from DB_SHARDS.
     *
     * @return array<string, array{weight:int}>
     */
    public static function weights(): array
    {
        return collect(explode(';', (string) env('DB_SHARDS')))
            ->filter()
            ->mapWithKeys(function (string $dsn) {
                [$name, $host, $port, $database] = array_pad(explode(':', trim($dsn)), 4, null);

                if (! $name || ! $host || ! $database) {
                    Log::warning(sprintf('Invalid shard DSN: %s', $dsn));

                    return [];
                }

                return [$name => ['weight' => 1]];
            })
            ->all();
    }

    /**
     * Build list of shards excluded during migration from DB_SHARD_MIGRATIONS.
     *
     * @return array<string, bool>
     */
    public static function migrations(): array
    {
        return collect(explode(';', (string) env('DB_SHARD_MIGRATIONS')))
            ->filter()
            ->mapWithKeys(fn (string $name) => [trim($name) => true])
            ->all();
    }
}
