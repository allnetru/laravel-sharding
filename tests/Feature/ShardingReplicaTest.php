<?php

namespace Allnetru\Sharding\Tests\Feature;

use Allnetru\Sharding\ShardingManager;
use Allnetru\Sharding\Tests\TestCase;

class ShardingReplicaTest extends TestCase
{
    public function testReplicaCountProducesExpectedConnections(): void
    {
        config()->set('sharding', [
            'default' => 'hash',
            'strategies' => [
                'hash' => \Allnetru\Sharding\Strategies\HashStrategy::class,
            ],
            'connections' => [
                'shard1' => ['weight' => 1],
                'shard2' => ['weight' => 1],
                'shard3' => ['weight' => 1],
            ],
            'replica_count' => 2,
        ]);

        $manager = new ShardingManager();
        $key = 'example';
        $connections = $manager->connectionFor('users', $key);

        $names = array_keys(config('sharding.connections'));
        sort($names);
        $hash = (int) sprintf('%u', crc32((string) $key));
        $index = $hash % count($names);
        $expected = [
            $names[$index],
            $names[($index + 1) % count($names)],
            $names[($index + 2) % count($names)],
        ];

        $this->assertSame($expected, $connections);
    }
}
