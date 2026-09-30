<?php

namespace Allnetru\Sharding\Tests\Feature;

use Allnetru\Sharding\Support\RoutingCache;
use Allnetru\Sharding\Tests\TestCase;
use Illuminate\Support\Facades\Cache;

class RoutingMemoTest extends TestCase
{
    /**
     * A request asks the shared cache for a slot once: every later query of it is answered from memory.
     */
    public function testARequestAsksTheStoreForASlotOnce(): void
    {
        RoutingCache::put('users@x', 'slot:1', ['shard_1'], true);

        // another process moves the slot: this request keeps the routing it started with
        Cache::store('array')->put('sharding:routing:users@x:slot:1', ['placement' => ['shard_2'], 'recorded' => true], 60);

        $this->assertSame(['shard_1'], RoutingCache::get('users@x', 'slot:1')['placement']);
    }

    /**
     * The next request, or the next queued job, reads the store again and sees the move.
     */
    public function testTheNextRequestSeesWhatAnotherProcessMoved(): void
    {
        RoutingCache::put('users@x', 'slot:1', ['shard_1'], true);
        Cache::store('array')->put('sharding:routing:users@x:slot:1', ['placement' => ['shard_2'], 'recorded' => true], 60);

        $this->app->forgetScopedInstances();

        $this->assertSame(['shard_2'], RoutingCache::get('users@x', 'slot:1')['placement']);
    }

    /**
     * A slot this process forgets — having moved it — is not answered from memory afterwards.
     */
    public function testAForgottenSlotIsNotRemembered(): void
    {
        RoutingCache::put('users@x', 'slot:1', ['shard_1'], true);
        RoutingCache::forget('users@x', 'slot:1');

        $this->assertNull(RoutingCache::get('users@x', 'slot:1'));
    }
}
