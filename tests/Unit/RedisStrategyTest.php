<?php

namespace Allnetru\Sharding\Tests\Unit;

use Allnetru\Sharding\Strategies\RedisStrategy;
use Allnetru\Sharding\Tests\TestCase;
use Illuminate\Redis\Connections\PhpRedisConnection;
use Illuminate\Support\Facades\Redis;
use Mockery;

class RedisStrategyTest extends TestCase
{
    protected function tearDown(): void
    {
        Redis::clearResolvedInstances();
        Mockery::close();

        parent::tearDown();
    }

    public function testDetermineReturnsStoredArray(): void
    {
        $connection = Mockery::mock(PhpRedisConnection::class);
        $connection->shouldReceive('get')->once()->with('prefix42')->andReturn(json_encode(['primary', 'replica']));
        Redis::shouldReceive('connection')->once()->with('cache')->andReturn($connection);

        $strategy = new RedisStrategy();
        $config = [
            'redis_connection' => 'cache',
            'redis_prefix' => 'prefix',
        ];

        $this->assertSame(['primary', 'replica'], $strategy->determine(42, $config));
    }

    public function testDetermineFallsBackToHashStrategyWhenMissing(): void
    {
        $connection = Mockery::mock(PhpRedisConnection::class);
        $connection->shouldReceive('get')->once()->with('shard:users99')->andReturn(null);
        Redis::shouldReceive('connection')->once()->with('default')->andReturn($connection);

        $strategy = new RedisStrategy();
        $config = [
            'table' => 'users',
            'connections' => [
                'a' => ['weight' => 1],
                'b' => ['weight' => 1],
            ],
            'replica_count' => 1,
        ];

        $connections = $strategy->determine(99, $config);
        $this->assertCount(2, $connections);
        $this->assertContains($connections[0], ['a', 'b']);
    }

    public function testDetermineThrowsWhenNoConnectionsAndMissingKey(): void
    {
        $connection = Mockery::mock(PhpRedisConnection::class);
        $connection->shouldReceive('get')->once()->andReturn(null);
        Redis::shouldReceive('connection')->once()->andReturn($connection);

        $strategy = new RedisStrategy();

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Shard for key [7] not found in Redis');

        $strategy->determine(7, []);
    }

    public function testDetermineHandlesScalarValues(): void
    {
        $connection = Mockery::mock(PhpRedisConnection::class);
        $connection->shouldReceive('get')->once()->with('shard:widgets55')->andReturn('b');
        Redis::shouldReceive('connection')->once()->andReturn($connection);

        $strategy = new RedisStrategy();
        $config = [
            'table' => 'widgets',
            'connections' => [
                'a' => ['weight' => 1],
                'b' => ['weight' => 1],
                'c' => ['weight' => 1],
            ],
            'replica_count' => 2,
        ];

        $this->assertSame(['b', 'c', 'a'], $strategy->determine(55, $config));
    }

    public function testRowMovedSwapsReplicaWhenPresent(): void
    {
        $connection = Mockery::mock(PhpRedisConnection::class);
        $connection->shouldReceive('get')->once()->with('prefix5')->andReturn(json_encode(['a', 'b']));
        $connection->shouldReceive('set')->once()->with('prefix5', json_encode(['b', 'a']));
        Redis::shouldReceive('connection')->once()->with('cache')->andReturn($connection);

        $strategy = new RedisStrategy();
        $strategy->rowMoved(5, 'b', [
            'redis_connection' => 'cache',
            'redis_prefix' => 'prefix',
            'connections' => [
                'a' => ['weight' => 1],
                'b' => ['weight' => 1],
            ],
            'replica_count' => 1,
        ]);

        $this->addToAssertionCount(1);
    }

    public function testRowMovedBuildsReplicasWhenMappingMissing(): void
    {
        $connection = Mockery::mock(PhpRedisConnection::class);
        $connection->shouldReceive('get')->once()->andReturn(null);
        $connection->shouldReceive('set')->once()->with('shard:orders8', json_encode(['b', 'c']));
        Redis::shouldReceive('connection')->once()->andReturn($connection);

        $strategy = new RedisStrategy();
        $strategy->rowMoved(8, 'b', [
            'table' => 'orders',
            'connections' => [
                'a' => ['weight' => 1],
                'b' => ['weight' => 1],
                'c' => ['weight' => 1],
            ],
            'replica_count' => 1,
        ]);

        $this->addToAssertionCount(1);
    }

    public function testRecordMetaAndReplicaPersistConnections(): void
    {
        $connection = Mockery::mock(PhpRedisConnection::class);
        $connection->shouldReceive('set')->once()->with('shard:users1', json_encode(['a', 'b']));
        $connection->shouldReceive('get')->once()->with('shard:users1')->andReturn(json_encode(['a', 'b']));
        $connection->shouldReceive('set')->once()->with('shard:users1', json_encode(['a', 'b', 'c']));
        Redis::shouldReceive('connection')->times(2)->andReturn($connection);

        $strategy = new RedisStrategy();
        $config = [
            'table' => 'users',
        ];

        $strategy->recordMeta(1, ['a', 'b'], $config);
        $strategy->recordReplica(1, 'c', $config);

        $this->addToAssertionCount(1);
    }
}
