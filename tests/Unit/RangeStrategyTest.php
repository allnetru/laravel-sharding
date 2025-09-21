<?php

namespace Allnetru\Sharding\Tests\Unit;

use Allnetru\Sharding\Strategies\RangeStrategy;
use Allnetru\Sharding\Tests\TestCase;

class RangeStrategyTest extends TestCase
{
    public function testDetermineReturnsPrimaryAndReplicas(): void
    {
        $strategy = new RangeStrategy();
        $config = [
            'connections' => [
                'shard_a' => ['weight' => 1],
                'shard_b' => ['weight' => 1],
            ],
            'ranges' => [
                ['start' => 1, 'end' => 10, 'connection' => 'shard_a'],
            ],
            'replica_count' => 1,
        ];

        $this->assertSame(['shard_a', 'shard_b'], $strategy->determine(5, $config));
    }

    public function testDetermineThrowsWhenRangeMissing(): void
    {
        $strategy = new RangeStrategy();

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('No range configured for key [11]');

        $strategy->determine(11, [
            'ranges' => [
                ['start' => 1, 'end' => 10, 'connection' => 'shard_a'],
            ],
        ]);
    }

    public function testRecordMethodsAreNoOpsAndCanRebalance(): void
    {
        $strategy = new RangeStrategy();
        $strategy->recordMeta(1, ['shard_a'], []);
        $strategy->recordReplica(1, 'shard_a', []);

        $this->assertTrue($strategy->canRebalance());
    }

    public function testAfterRebalanceAppendsRangesWhenTargetProvided(): void
    {
        config(['sharding.tables.orders.ranges' => []]);
        $strategy = new RangeStrategy();

        $strategy->afterRebalance('orders', 'id', null, 'shard_x', 100, 200, [
            'ranges' => [],
        ]);

        $this->assertSame([
            ['start' => 100, 'end' => 200, 'connection' => 'shard_x'],
        ], config('sharding.tables.orders.ranges'));

        // Ensure no changes when target is missing
        config(['sharding.tables.orders.ranges' => []]);
        $strategy->afterRebalance('orders', 'id', null, null, 100, 200, [
            'ranges' => [],
        ]);

        $this->assertSame([], config('sharding.tables.orders.ranges'));
    }
}
