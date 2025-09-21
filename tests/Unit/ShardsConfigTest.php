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
        Log::shouldReceive('warning')->twice()->with(sprintf('Invalid shard DSN: %s', $dsn));

        $this->assertSame([], Shards::databaseConnections($dsn));
        $this->assertSame([], Shards::weights($dsn));
    }

    public function test_helpers_fall_back_to_env_when_config_not_loaded(): void
    {
        $originalConfig = config('sharding');

        config(['sharding' => null]);

        $previousShards = getenv('DB_SHARDS');
        $previousPort = getenv('DB_PORT');

        $dsn = 'fallback-shard:db-fallback::sharded_db';

        putenv("DB_SHARDS={$dsn}");
        $_ENV['DB_SHARDS'] = $dsn;
        $_SERVER['DB_SHARDS'] = $dsn;

        putenv('DB_PORT=3310');
        $_ENV['DB_PORT'] = '3310';
        $_SERVER['DB_PORT'] = '3310';

        try {
            $connections = Shards::databaseConnections();

            $this->assertArrayHasKey('fallback-shard', $connections);

            $this->assertSame('db-fallback', $connections['fallback-shard']['host']);
            $this->assertSame('3310', $connections['fallback-shard']['port']);
            $this->assertSame('sharded_db', $connections['fallback-shard']['database']);

            $this->assertSame([
                'fallback-shard' => ['weight' => 1],
            ], Shards::weights());

            $this->assertSame([], Shards::migrations());
        } finally {
            if ($previousShards === false) {
                putenv('DB_SHARDS');
                unset($_ENV['DB_SHARDS'], $_SERVER['DB_SHARDS']);
            } else {
                putenv("DB_SHARDS={$previousShards}");
                $_ENV['DB_SHARDS'] = $previousShards;
                $_SERVER['DB_SHARDS'] = $previousShards;
            }

            if ($previousPort === false) {
                putenv('DB_PORT');
                unset($_ENV['DB_PORT'], $_SERVER['DB_PORT']);
            } else {
                putenv("DB_PORT={$previousPort}");
                $_ENV['DB_PORT'] = $previousPort;
                $_SERVER['DB_PORT'] = $previousPort;
            }

            config(['sharding' => $originalConfig]);
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
