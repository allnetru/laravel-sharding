<?php

namespace Allnetru\Sharding\Tests\Unit;

use Allnetru\Sharding\Support\Config\Shards;
use Allnetru\Sharding\Tests\TestCase;
use Illuminate\Support\Facades\Log;
use PDO;
use PHPUnit\Framework\Attributes\DataProvider;

class ShardsConfigTest extends TestCase
{
    #[DataProvider('invalidDsnProvider')]
    public function testInvalidDsnIsExcluded(string $dsn): void
    {
        Log::shouldReceive('warning')->twice()->with(sprintf('Invalid shard DSN: %s', $dsn));

        $this->assertSame([], Shards::databaseConnections($dsn));
        $this->assertSame([], Shards::weights($dsn));
    }

    public function testHelpersFallBackToEnvWhenConfigNotLoaded(): void
    {
        $originalConfig = config('sharding');

        config(['sharding' => null]);

        $dsn = 'fallback-shard:db-fallback::sharded_db';

        $this->withEnv([
            'DB_SHARDS' => $dsn,
            'DB_PORT' => '3310',
        ], function () {
            $connections = Shards::databaseConnections();

            $this->assertArrayHasKey('fallback-shard', $connections);

            $this->assertSame('db-fallback', $connections['fallback-shard']['host']);
            $this->assertSame('3310', $connections['fallback-shard']['port']);
            $this->assertSame('sharded_db', $connections['fallback-shard']['database']);

            $this->assertSame([
                'fallback-shard' => ['weight' => 1],
            ], Shards::weights());

            $this->assertSame([], Shards::migrations());
        });

        config(['sharding' => $originalConfig]);
    }

    public function testDatabaseConnectionsBuildsConfigurationFromEnvironment(): void
    {
        $this->withEnv([
            'DB_SHARDS' => 'app:db.example.com::shards;analytics:analytics.example.com:3307:analytics',
            'DB_SHARD_DRIVER' => 'mysql',
            'DB_USERNAME' => 'forge',
            'DB_PASSWORD' => 'secret',
            'DB_CHARSET' => 'utf8mb4',
            'DB_COLLATION' => 'utf8mb4_unicode_ci',
            'DB_PORT' => '4400',
            'MYSQL_ATTR_SSL_CA' => 'ca.pem',
        ], function () {
            Log::shouldReceive('warning')->never();

            $connections = Shards::databaseConnections();

            $this->assertArrayHasKey('app', $connections);
            $this->assertArrayHasKey('analytics', $connections);
            $this->assertSame('db.example.com', $connections['app']['host']);
            $this->assertSame('4400', $connections['app']['port']);
            $this->assertSame('shards', $connections['app']['database']);
            $this->assertSame('3307', $connections['analytics']['port']);
            $this->assertSame('analytics', $connections['analytics']['database']);
            $this->assertSame('ca.pem', $connections['app']['options'][PDO::MYSQL_ATTR_SSL_CA]);
        });
    }

    public function testWeightsDeriveEvenDistribution(): void
    {
        $this->withEnv([
            'DB_SHARDS' => 'api:api.example.com:3306:api;billing:billing.example.com:3306:billing',
        ], function () {
            Log::shouldReceive('warning')->never();

            $weights = Shards::weights();

            $this->assertSame(['weight' => 1], $weights['api']);
            $this->assertSame(['weight' => 1], $weights['billing']);
        });
    }

    public function testMigrationsParseExclusionList(): void
    {
        $this->withEnv([
            'DB_SHARD_MIGRATIONS' => ' shard-archive ; shard-temp ',
        ], function () {
            $migrations = Shards::migrations();

            $this->assertSame(['shard-archive' => true, 'shard-temp' => true], $migrations);
        });
    }

    /**
     * @param  array<string, string|null>  $values
     */
    private function withEnv(array $values, callable $callback): void
    {
        $previous = [];

        foreach ($values as $key => $value) {
            $previous[$key] = getenv($key);

            if ($value === null) {
                putenv($key);
                unset($_ENV[$key], $_SERVER[$key]);

                continue;
            }

            $stringValue = (string) $value;

            putenv(sprintf('%s=%s', $key, $stringValue));
            $_ENV[$key] = $stringValue;
            $_SERVER[$key] = $stringValue;
        }

        try {
            $callback();
        } finally {
            foreach ($previous as $key => $value) {
                if ($value === false) {
                    putenv($key);
                    unset($_ENV[$key], $_SERVER[$key]);

                    continue;
                }

                $stringValue = (string) $value;

                putenv(sprintf('%s=%s', $key, $stringValue));
                $_ENV[$key] = $stringValue;
                $_SERVER[$key] = $stringValue;
            }
        }
    }

    public static function invalidDsnProvider(): array
    {
        return [
            'missing name' => [':host:3306:db'],
            'missing host' => ['shard::3306:db'],
            'missing database' => ['shard:host:3306:'],
        ];
    }
}
