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
        config(['sharding.env.db_shards' => $dsn]);
        Log::shouldReceive('warning')->twice()->with(sprintf('Invalid shard DSN: %s', $dsn));

        $this->assertSame([], Shards::databaseConnections());
        $this->assertSame([], Shards::weights());

        config(['sharding.env.db_shards' => '']);
    }

    public function testDatabaseConnectionsBuildsConfigurationFromEnvironment(): void
    {
        config()->set('sharding.env', [
            'db_shards' => 'app:db.example.com::shards;analytics:analytics.example.com:3307:analytics',
            'driver' => 'mysql',
            'username' => 'forge',
            'password' => 'secret',
            'charset' => 'utf8mb4',
            'collation' => 'utf8mb4_unicode_ci',
            'port' => '4400',
            'mysql_attr_ssl_ca' => 'ca.pem',
        ]);
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

        config()->set('sharding.env', []);
    }

    public function testWeightsDeriveEvenDistribution(): void
    {
        config(['sharding.env.db_shards' => 'api:api.example.com:3306:api;billing:billing.example.com:3306:billing']);
        Log::shouldReceive('warning')->never();

        $weights = Shards::weights();

        $this->assertSame(['weight' => 1], $weights['api']);
        $this->assertSame(['weight' => 1], $weights['billing']);

        config(['sharding.env.db_shards' => '']);
    }

    public function testMigrationsParseExclusionList(): void
    {
        config(['sharding.env.db_shard_migrations' => ' shard-archive ; shard-temp ']);

        $migrations = Shards::migrations();

        $this->assertSame(['shard-archive' => true, 'shard-temp' => true], $migrations);

        config(['sharding.env.db_shard_migrations' => '']);
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
