<?php

namespace Allnetru\Sharding\Tests\Feature;

use Allnetru\Sharding\ShardingManager;
use Allnetru\Sharding\Strategies\RangeStrategy;
use Allnetru\Sharding\Tests\TestCase;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * A rebalance under the real range strategy, whose routing lives in the config.
 *
 * Two things only this strategy exposes, both found in review.
 *
 * `ShardingManager` copies the `sharding` array in its constructor and is bound
 * as a singleton, while `RangeStrategy::afterRebalance()` hands the range over
 * by writing that config. So the manager the rebalance was holding could not
 * see the handoff it had just performed, and the pass that makes the placement
 * real then worked from the routing as it had been.
 *
 * And `determine()` takes the first range containing the key, while the handoff
 * appended — so the range being replaced went on matching first and the handoff
 * changed nothing. Disjoint ranges are unaffected by the order, which is why
 * this went unnoticed.
 */
class ShardRebalanceRangeTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config([
            'database.connections.shard_1' => ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => ''],
            'database.connections.shard_2' => ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => ''],
            'database.connections.shard_3' => ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => ''],
            'sharding.connections' => [
                'shard_1' => ['weight' => 1],
                'shard_2' => ['weight' => 1],
                'shard_3' => ['weight' => 1],
            ],
            'sharding.tables' => [
                'grants' => [
                    'strategy' => 'range',
                    'replica_count' => 1,
                    'ranges' => [['start' => 1, 'end' => 100, 'connection' => 'shard_1']],
                ],
            ],
        ]);

        app()->singleton(ShardingManager::class, fn () => new ShardingManager(config('sharding')));

        foreach (['shard_1', 'shard_2', 'shard_3'] as $connection) {
            Schema::connection($connection)->create('grants', function (Blueprint $table): void {
                $table->unsignedBigInteger('id')->primary();
                $table->unsignedBigInteger('user_id');
                $table->string('role');
                $table->boolean('is_replica')->default(false);
            });
        }
    }

    /**
     * The handoff takes effect, and the placement is read from it afterwards.
     *
     * @return void
     */
    public function testTheNewRangeTakesEffectAndDecidesThePlacement(): void
    {
        DB::connection('shard_1')->table('grants')->insert([
            'id' => 1,
            'user_id' => 7,
            'role' => 'one',
            'is_replica' => false,
        ]);

        app(RangeStrategy::class)->rebalance('grants', 'user_id', 'id', 'shard_1', 'shard_3', 1, 100, [
            'connections' => config('sharding.connections'),
            'table' => 'grants',
            'replica_count' => 1,
            'ranges' => config('sharding.tables.grants.ranges'),
        ]);

        $placement = app(ShardingManager::class)->connectionFor('grants', 7);

        $this->assertSame(
            ['shard_3', 'shard_1'],
            $placement,
            'the new range did not win over the one it replaces',
        );

        $this->assertSame(
            1,
            DB::connection('shard_3')->table('grants')->where('id', 1)->count(),
            'the row did not move',
        );

        $left = DB::connection('shard_1')->table('grants')->where('id', 1)->first();

        $this->assertNotNull($left, 'the copy the new placement names as its replica was deleted');
        $this->assertNotEmpty($left->is_replica);

        /*
        | The connection the *old* range would have named as the replica. A
        | manager still holding the pre-handoff config resolves the row through
        | shard_1 as primary and shard_2 as its replica, and writes a copy
        | there that nothing advertises.
        */
        $this->assertSame(
            0,
            DB::connection('shard_2')->table('grants')->count(),
            'a copy was placed for the routing as it was before the handoff',
        );
    }
}
