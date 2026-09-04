<?php

namespace Allnetru\Sharding\Tests\Unit;

use Allnetru\Sharding\Support\Config\Shards;
use Allnetru\Sharding\Tests\TestCase;

/**
 * Driver awareness of the generated shard connections.
 *
 * PostgreSQL rejects a charset of `utf8mb4` outright with
 * `invalid value for parameter "client_encoding"`, so a shard connection built
 * with the MySQL defaults cannot connect at all.
 */
class ShardsConfigDriverTest extends TestCase
{
    protected function tearDown(): void
    {
        foreach (['DB_SHARD_DRIVER', 'DB_PORT', 'DB_CHARSET', 'DB_SEARCH_PATH'] as $key) {
            putenv($key);
            unset($_ENV[$key], $_SERVER[$key]);
        }

        parent::tearDown();
    }

    public function testPostgresConnectionsDoNotCarryMysqlCharset(): void
    {
        putenv('DB_SHARD_DRIVER=pgsql');

        $connections = Shards::databaseConnections('shard-1:127.0.0.1:5432:app_shard_1');

        $this->assertSame('pgsql', $connections['shard-1']['driver']);
        $this->assertSame('utf8', $connections['shard-1']['charset']);
        $this->assertArrayNotHasKey('collation', $connections['shard-1']);
        $this->assertArrayNotHasKey('engine', $connections['shard-1']);
        $this->assertArrayNotHasKey('strict', $connections['shard-1']);
    }

    public function testPostgresConnectionsCarrySearchPathAndSslMode(): void
    {
        putenv('DB_SHARD_DRIVER=pgsql');

        $connections = Shards::databaseConnections('shard-1:127.0.0.1:5432:app_shard_1');

        $this->assertSame('public', $connections['shard-1']['search_path']);
        $this->assertSame('prefer', $connections['shard-1']['sslmode']);
    }

    public function testPostgresDefaultPortIsFiveFourThreeTwo(): void
    {
        putenv('DB_SHARD_DRIVER=pgsql');

        // the port is left out of the entry on purpose
        $connections = Shards::databaseConnections('shard-1:127.0.0.1::app_shard_1');

        $this->assertSame('5432', $connections['shard-1']['port']);
    }

    public function testMysqlRemainsTheDefaultAndKeepsItsKeys(): void
    {
        $connections = Shards::databaseConnections('shard-1:127.0.0.1::app_shard_1');

        $this->assertSame('mysql', $connections['shard-1']['driver']);
        $this->assertSame('utf8mb4', $connections['shard-1']['charset']);
        $this->assertSame('3306', $connections['shard-1']['port']);
        $this->assertArrayHasKey('collation', $connections['shard-1']);
        $this->assertArrayHasKey('strict', $connections['shard-1']);
    }
}
