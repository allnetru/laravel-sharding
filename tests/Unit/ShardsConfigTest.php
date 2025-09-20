<?php

namespace Allnetru\Sharding\Tests\Unit;

use Allnetru\Sharding\Support\Config\Shards;
use Allnetru\Sharding\Tests\TestCase;
use Illuminate\Support\Facades\Log;
use PHPUnit\Framework\Attributes\DataProvider;

class ShardsConfigTest extends TestCase
{
    #[DataProvider('invalidDsnProvider')]
    public function test_invalid_dsn_is_excluded(string $dsn): void
    {
        config(['sharding.env.db_shards' => $dsn]);
        Log::shouldReceive('warning')->twice()->with(sprintf('Invalid shard DSN: %s', $dsn));

        $this->assertSame([], Shards::databaseConnections());
        $this->assertSame([], Shards::weights());

        config(['sharding.env.db_shards' => '']);
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
