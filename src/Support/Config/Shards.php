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
     * The shape depends on the driver. PostgreSQL rejects a `charset` of
     * `utf8mb4` outright with `invalid value for parameter "client_encoding"`,
     * and MySQL specific keys such as `collation`, `strict` and `engine` mean
     * nothing to it, so they are not emitted at all.
     *
     * @return array<string, mixed>
     */
    protected static function baseConfig(): array
    {
        $driver = (string) self::env('DB_SHARD_DRIVER', 'mysql');

        $config = [
            'driver' => $driver,
            'username' => (string) self::env('DB_USERNAME', 'forge'),
            'password' => (string) self::env('DB_PASSWORD', ''),
            'prefix' => '',
            'prefix_indexes' => true,
        ];

        if ($driver === 'pgsql') {
            return $config + [
                'charset' => (string) self::env('DB_CHARSET', 'utf8'),
                'search_path' => (string) self::env('DB_SEARCH_PATH', 'public'),
                'sslmode' => (string) self::env('DB_SSLMODE', 'prefer'),
            ];
        }

        $sslCa = self::env('MYSQL_ATTR_SSL_CA');
        $options = [];

        if (extension_loaded('pdo_mysql')) {
            $options = array_filter([
                PDO::MYSQL_ATTR_SSL_CA => $sslCa ?: null,
            ]);
        }

        return $config + [
            'charset' => (string) self::env('DB_CHARSET', 'utf8mb4'),
            'collation' => (string) self::env('DB_COLLATION', 'utf8mb4_unicode_ci'),
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
        // Порт по умолчанию зависит от драйвера: 3306 у MySQL, 5432 у
        // PostgreSQL. Без этого запись shard-1:host::db молча уходит на
        // чужой порт.
        $driver = (string) self::env('DB_SHARD_DRIVER', 'mysql');
        $defaultPort = (string) self::env('DB_PORT', $driver === 'pgsql' ? '5432' : '3306');

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
