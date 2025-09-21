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
     * Access environment variables while silencing Larastan's config-only check.
     */
    protected static function env(string $key, mixed $default = null): mixed
    {
        // @phpstan-ignore-next-line larastan.noEnvCallsOutsideOfConfig
        return env($key, $default);
    }

    /**
     * Base configuration applied to all shard connections.
     *
     * @return array<string, mixed>
     */
    protected static function baseConfig(): array
    {
        $sslCa = self::env('MYSQL_ATTR_SSL_CA');
        $options = [];

        if (extension_loaded('pdo_mysql')) {
            $options = array_filter([
                PDO::MYSQL_ATTR_SSL_CA => $sslCa ?: null,
            ]);
        }

        return [
            'driver' => (string) self::env('DB_SHARD_DRIVER', 'mysql'),
            'username' => (string) self::env('DB_USERNAME', 'forge'),
            'password' => (string) self::env('DB_PASSWORD', ''),
            'charset' => (string) self::env('DB_CHARSET', 'utf8mb4'),
            'collation' => (string) self::env('DB_COLLATION', 'utf8mb4_unicode_ci'),
            'prefix' => '',
            'prefix_indexes' => true,
            'strict' => true,
            'engine' => null,
            'options' => $options,
        ];
    }

    /**
     * Build shard database connections from DB_SHARDS.
     *
     * @param  string|null  $definitions
     * @return array<string, array{
     *     driver: string,
     *     username: string,
     *     password: string,
     *     charset: string,
     *     collation: string,
     *     prefix: string,
     *     prefix_indexes: bool,
     *     strict: bool,
     *     engine: string|null,
     *     options: array<int|string, mixed>,
     *     host: string,
     *     port: string,
     *     database: string,
     * }>
     */
    public static function databaseConnections(?string $definitions = null): array
    {
        $baseConfig = self::baseConfig();
        $definitions ??= self::env('DB_SHARDS');
        $definitions = (string) $definitions;
        $defaultPort = (string) self::env('DB_PORT', '3306');

        return collect(explode(';', $definitions))
            ->filter()
            ->mapWithKeys(function (string $dsn) use ($baseConfig, $defaultPort) {
                [$name, $host, $port, $database] = array_pad(explode(':', trim($dsn)), 4, null);

                if (!$name || !$host || !$database) {
                    Log::warning(sprintf('Invalid shard DSN: %s', $dsn));

                    return [];
                }

                return [
                    $name => array_merge($baseConfig, [
                        'host' => $host,
                        'port' => $port ?: $defaultPort,
                        'database' => $database,
                    ]),
                ];
            })
            ->all();
    }

    /**
     * Build shard weight configuration from DB_SHARDS.
     *
     * @param  string|null  $definitions
     * @return array<string, array{weight: int}>
     */
    public static function weights(?string $definitions = null): array
    {
        $definitions ??= self::env('DB_SHARDS');
        $definitions = (string) $definitions;

        return collect(explode(';', $definitions))
            ->filter()
            ->mapWithKeys(function (string $dsn) {
                [$name, $host, $port, $database] = array_pad(explode(':', trim($dsn)), 4, null);

                if (!$name || !$host || !$database) {
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
     * @param  string|null  $definitions
     * @return array<string, true>
     */
    public static function migrations(?string $definitions = null): array
    {
        $definitions ??= self::env('DB_SHARD_MIGRATIONS');
        $definitions = (string) $definitions;

        /** @var array<string, true> $migrations */
        $migrations = collect(explode(';', $definitions))
            ->filter()
            ->mapWithKeys(fn (string $name) => [trim($name) => true])
            ->all();

        return $migrations;
    }
}
