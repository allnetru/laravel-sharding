<?php

namespace Allnetru\Sharding\Tests\Unit\Support;

use Allnetru\Sharding\Support\RoutingCache;
use PHPUnit\Framework\TestCase;

class RoutingCacheTest extends TestCase
{
    /**
     * The hashed fallback depends on the weights as much as on the names, so a
     * reweighted topology has to be a new namespace too. Found in review.
     */
    public function testAChangedWeightIsAChangeOfNamespace(): void
    {
        $light = RoutingCache::namespaceFor([
            'table' => 'users',
            'connections' => ['shard_1' => ['weight' => 1], 'shard_2' => ['weight' => 1]],
        ]);
        $heavy = RoutingCache::namespaceFor([
            'table' => 'users',
            'connections' => ['shard_1' => ['weight' => 2], 'shard_2' => ['weight' => 1]],
        ]);

        $this->assertNotSame($light, $heavy);
    }

    public function testTheOrderConnectionsAreListedInIsNot(): void
    {
        $this->assertSame(
            RoutingCache::namespaceFor([
                'table' => 'users',
                'connections' => ['shard_1' => ['weight' => 1], 'shard_2' => ['weight' => 1]],
            ]),
            RoutingCache::namespaceFor([
                'table' => 'users',
                'connections' => ['shard_2' => ['weight' => 1], 'shard_1' => ['weight' => 1]],
            ]),
        );
    }

    public function testTheScopeAndTheReplicaCountAreBothPartOfIt(): void
    {
        $connections = ['shard_1' => ['weight' => 1]];

        $this->assertNotSame(
            RoutingCache::namespaceFor(['table' => 'users', 'connections' => $connections]),
            RoutingCache::namespaceFor(['table' => 'roles', 'connections' => $connections]),
        );
        $this->assertNotSame(
            RoutingCache::namespaceFor(['table' => 'users', 'connections' => $connections, 'replica_count' => 0]),
            RoutingCache::namespaceFor(['table' => 'users', 'connections' => $connections, 'replica_count' => 1]),
        );
        $this->assertStringStartsWith('users@', RoutingCache::namespaceFor(['table' => 'users', 'connections' => $connections]));
    }
}
