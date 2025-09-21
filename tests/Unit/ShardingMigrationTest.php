<?php

namespace Allnetru\Sharding\Tests\Unit;

use Allnetru\Sharding\ShardingManager;
use Allnetru\Sharding\Strategies\HashStrategy;
use Allnetru\Sharding\Tests\TestCase;

class ShardingMigrationTest extends TestCase
{
    public function testShardMarkedForMigrationIsSkipped(): void
    {
        $config = [
            'default' => 'hash',
            'strategies' => ['hash' => HashStrategy::class],
            'connections' => [
                'shard-1' => ['weight' => 1],
                'shard-2' => ['weight' => 1],
            ],
            'migrations' => ['shard-1' => true],
        ];
        $manager = new ShardingManager($config);

        $this->assertSame(['shard-2'], $manager->connectionFor('items', 4));
    }
}
