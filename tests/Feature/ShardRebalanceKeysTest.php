<?php

namespace Allnetru\Sharding\Tests\Feature;

use Allnetru\Sharding\ShardingManager;
use Allnetru\Sharding\Strategies\Rebalanceable;
use Allnetru\Sharding\Strategies\Strategy;
use Allnetru\Sharding\Tests\TestCase;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Rebalancing keeps routing and identity apart.
 *
 * Two keys that are the same column on most tables and different columns on
 * every colocated one, and mixing them up costs different things. The shard
 * key is what a slot is computed from, so it decides where a row belongs;
 * routing by the primary key instead moves rows the slot change never asked
 * about. The row key identifies one row; identifying by the shard key on a
 * one-to-many table — several roles for one user — deletes every one of that
 * user's rows after moving a single one.
 *
 * Misplacing rows is recoverable. Deleting them is not, which is why this test
 * exists at all: it was very nearly shipped as a one-line «fix» that passed
 * the whole suite.
 */
class ShardRebalanceKeysTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config([
            'database.connections.shard_1' => ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => ''],
            'database.connections.shard_2' => ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => ''],
            'sharding.connections' => ['shard_1' => ['weight' => 1], 'shard_2' => ['weight' => 1]],
            'sharding.tables' => ['grants' => ['strategy' => 'hash', 'replica_count' => 0]],
        ]);

        app()->singleton(ShardingManager::class, fn () => new ShardingManager(config('sharding')));

        foreach (['shard_1', 'shard_2'] as $connection) {
            Schema::connection($connection)->create('grants', function (Blueprint $table): void {
                $table->unsignedBigInteger('id')->primary();
                $table->unsignedBigInteger('user_id');
                $table->string('role');
                $table->boolean('is_replica')->default(false);
            });
        }
    }

    /**
     * Moving one of a user's rows leaves the others alone.
     *
     * @return void
     */
    public function testMovingOneRowDoesNotDeleteTheOthers(): void
    {
        // three rows of one user, all on the connection they do not belong on
        $userId = 1;
        $target = app(ShardingManager::class)->connectionFor('grants', $userId)[0];
        $source = $target === 'shard_1' ? 'shard_2' : 'shard_1';

        foreach ([1, 2, 3] as $id) {
            DB::connection($source)->table('grants')->insert([
                'id' => $id,
                'user_id' => $userId,
                'role' => 'role-' . $id,
                'is_replica' => false,
            ]);
        }

        $strategy = new class() implements Strategy {
            use Rebalanceable;

            public function determine(mixed $key, array $config): array
            {
                return app(ShardingManager::class)->connectionFor('grants', $key);
            }

            public function canRebalance(): bool
            {
                return true;
            }

            public function recordMeta(mixed $key, array $connections, array $config): void
            {
            }

            public function recordReplica(mixed $key, string $connection, array $config): void
            {
            }
        };

        $moved = $strategy->rebalance('grants', 'user_id', 'id', $source, null, null, null, [
            'connections' => config('sharding.connections'),
            'table' => 'grants',
        ]);

        $this->assertSame(3, $moved, 'not every row was carried');

        $this->assertSame(
            3,
            DB::connection($target)->table('grants')->where('user_id', $userId)->count(),
            'rows were destroyed instead of moved',
        );

        $this->assertSame(
            ['role-1', 'role-2', 'role-3'],
            DB::connection($target)->table('grants')->orderBy('id')->pluck('role')->all(),
            'the rows arrived, but not as themselves',
        );

        $this->assertSame(0, DB::connection($source)->table('grants')->count());
    }
}
