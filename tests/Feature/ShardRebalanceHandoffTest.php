<?php

namespace Allnetru\Sharding\Tests\Feature;

use Allnetru\Sharding\Exceptions\RebalanceIncomplete;
use Allnetru\Sharding\ShardingManager;
use Allnetru\Sharding\Strategies\Rebalanceable;
use Allnetru\Sharding\Strategies\RowMoveAware;
use Allnetru\Sharding\Strategies\Strategy;
use Allnetru\Sharding\Strategies\SupportsAfterRebalance;
use Allnetru\Sharding\Tests\TestCase;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Handing the routing over, and what the routing then says exists.
 *
 * The last step of a rebalance and the only one a re-run cannot repeat.
 * Everything before it is idempotent — a row already where its key names is
 * skipped, a destination already holding the row is accepted — but once the
 * routing is handed over the source copies are gone, so a `rowMoved()` that
 * throws leaves the rows on the new connection and the routing pointing at the
 * old one, with nothing left for a second run to notice.
 *
 * And what the routing says has to be true: the strategy picks the replicas of
 * a moved key, and where it picks a connection the move never wrote to, the
 * metadata advertised a replica holding nothing.
 *
 * Both found in review.
 */
class ShardRebalanceHandoffTest extends TestCase
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
            'sharding.strategies.mapped' => MappedStrategy::class,
            'sharding.tables' => [
                'grants' => ['strategy' => 'mapped', 'replica_count' => 1],
            ],
        ]);

        MappedStrategy::$map = [];
        MappedStrategy::$fallback = ['shard_1', 'shard_2'];

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
     * The replicas the routing names after the move are actually written.
     *
     * With `--to` naming a connection the key never named, the strategy picks
     * a fresh replica list — and nothing in the move had written the row
     * there, so the metadata advertised a replica holding nothing while the
     * copies that did exist were advertised by no one.
     *
     * @return void
     */
    public function testTheReplicasTheRoutingNamesAfterTheMoveExist(): void
    {
        DB::connection('shard_1')->table('grants')->insert([
            'id' => 1,
            'user_id' => 7,
            'role' => 'one',
            'is_replica' => false,
        ]);

        // moved onto a connection this key names neither as primary nor as
        // replica, so the strategy has to choose a new replica for it
        $this->strategy()->rebalance('grants', 'user_id', 'id', 'shard_1', 'shard_3', null, null, [
            'connections' => config('sharding.connections'),
            'table' => 'grants',
            'replica_count' => 1,
        ]);

        $placement = app(ShardingManager::class)->connectionFor('grants', 7);

        $this->assertSame('shard_3', $placement[0], 'the routing was not handed over');
        $this->assertCount(2, $placement, 'the strategy chose no replica');

        $this->assertSame('shard_2', $placement[1], 'the replica chosen is one the move touched');

        $replica = DB::connection($placement[1])->table('grants')->where('id', 1)->first();

        $this->assertNotNull($replica, 'the routing advertises a replica that was never written');
        $this->assertNotEmpty($replica->is_replica, 'the copy arrived claiming to be the row');
    }

    /**
     * A different row already on the new replica connection is not overwritten.
     *
     * Neither earlier pass looks there: the preflight inspects the primary
     * destination, and the shared-key check leaves replicas out on purpose. So
     * an existence-only test accepted somebody else's row as this one's copy.
     * Found in review.
     *
     * @return void
     */
    public function testADifferentRowOnTheNewReplicaIsNotOverwritten(): void
    {
        DB::connection('shard_1')->table('grants')->insert([
            'id' => 1,
            'user_id' => 7,
            'role' => 'mine',
            'is_replica' => false,
        ]);

        // the connection the strategy will choose as the replica, already
        // holding a copy of some other row under the same identifier
        DB::connection('shard_2')->table('grants')->insert([
            'id' => 1,
            'user_id' => 99,
            'role' => 'somebody else',
            'is_replica' => true,
        ]);

        $this->strategy()->rebalance('grants', 'user_id', 'id', 'shard_1', 'shard_3', null, null, [
            'connections' => config('sharding.connections'),
            'table' => 'grants',
            'replica_count' => 1,
        ]);

        $this->assertSame(
            'somebody else',
            DB::connection('shard_2')->table('grants')->where('id', 1)->value('role'),
            'a different row was overwritten to make a replica',
        );
    }

    /**
     * A routing update that throws is reported, not swallowed.
     *
     * @return void
     */
    public function testARoutingUpdateThatThrowsFailsTheRun(): void
    {
        DB::connection('shard_1')->table('grants')->insert([
            'id' => 1,
            'user_id' => 7,
            'role' => 'one',
            'is_replica' => false,
        ]);

        $strategy = $this->strategy();
        $strategy->refuseToRedirect = true;

        try {
            $strategy->rebalance('grants', 'user_id', 'id', 'shard_1', 'shard_3', null, null, [
                'connections' => config('sharding.connections'),
                'table' => 'grants',
                'replica_count' => 1,
            ]);

            $this->fail('a routing update that threw was reported as a successful rebalance');
        } catch (RebalanceIncomplete $e) {
            $this->assertSame(1, $e->failed);
        }

        $this->assertFalse($strategy->handedOver, 'the range was handed over anyway');
        $this->assertSame(2, $strategy->attempts, 'the update was not retried');
    }

    /**
     * A key is redirected once, after every row behind it has arrived.
     *
     * `rowMoved()` was called per row, and one key covers several rows on a
     * colocated one-to-many table: redirecting it when the first lands points
     * the routing away from the siblings still on the source. Found in review.
     *
     * @return void
     */
    public function testAKeyIsRedirectedOnceAndOnlyAfterEveryRowArrives(): void
    {
        DB::connection('shard_1')->table('grants')->insert([
            ['id' => 1, 'user_id' => 7, 'role' => 'first', 'is_replica' => false],
            ['id' => 2, 'user_id' => 7, 'role' => 'second', 'is_replica' => false],
            ['id' => 3, 'user_id' => 7, 'role' => 'third', 'is_replica' => false],
        ]);

        $strategy = $this->strategy();

        $strategy->rebalance('grants', 'user_id', 'id', 'shard_1', 'shard_3', null, null, [
            'connections' => config('sharding.connections'),
            'table' => 'grants',
            'replica_count' => 1,
        ]);

        $this->assertSame(1, $strategy->attempts, 'the key was redirected per row rather than once');
        $this->assertSame(['7' => ['shard_3', 'shard_2']], MappedStrategy::$map);
    }

    /**
     * Running it again finishes a handoff that failed the first time.
     *
     * The redirect set used to be a record of what this run moved, so a rerun
     * that found nothing left on the source had nothing to redirect and
     * reported success over keys still naming the old shard. Read off where
     * the rows actually are, there is no such gap. Found in review.
     *
     * @return void
     */
    public function testRunningItAgainFinishesAHandoffThatFailed(): void
    {
        DB::connection('shard_1')->table('grants')->insert([
            'id' => 1,
            'user_id' => 7,
            'role' => 'one',
            'is_replica' => false,
        ]);

        $first = $this->strategy();
        $first->refuseToRedirect = true;

        try {
            $first->rebalance('grants', 'user_id', 'id', 'shard_1', 'shard_3', null, null, [
                'connections' => config('sharding.connections'),
                'table' => 'grants',
                'replica_count' => 1,
            ]);
        } catch (RebalanceIncomplete) {
            // the state this test is about: the row moved, the routing did not
        }

        $this->assertSame([], MappedStrategy::$map, 'the routing was updated after all');
        $this->assertSame(
            1,
            DB::connection('shard_3')->table('grants')->where('id', 1)->count(),
            'the row did not move',
        );
        // the source keeps its copy as the replica this key still names it
        // for, which is the retention rule rather than a failure to release
        $left = DB::connection('shard_1')->table('grants')->where('id', 1)->first();

        $this->assertNotNull($left);
        $this->assertNotEmpty($left->is_replica, 'the source is still claiming to be the row');

        // the same command again, with the store reachable this time
        $second = $this->strategy();

        $second->rebalance('grants', 'user_id', 'id', 'shard_1', 'shard_3', null, null, [
            'connections' => config('sharding.connections'),
            'table' => 'grants',
            'replica_count' => 1,
        ]);

        $this->assertSame(
            ['7' => ['shard_3', 'shard_2']],
            MappedStrategy::$map,
            'the rerun did not finish the handoff',
        );
    }

    /**
     * A strategy whose routing is a map this test can watch.
     *
     * @return MappedStrategy
     */
    protected function strategy(): MappedStrategy
    {
        return app(MappedStrategy::class);
    }
}

/**
 * Routing kept in a static map, so a redirect is observable.
 */
class MappedStrategy implements Strategy, RowMoveAware, SupportsAfterRebalance
{
    use Rebalanceable;

    /** @var array<string, list<string>> Where each key currently lives. */
    public static array $map = [];

    /** @var list<string> The placement of a key nothing has moved. */
    public static array $fallback = ['shard_1', 'shard_2'];

    /** Whether the range was handed over. */
    public bool $handedOver = false;

    /** Whether rowMoved() should fail. */
    public bool $refuseToRedirect = false;

    /** How many times rowMoved() was called. */
    public int $attempts = 0;

    /**
     * @param mixed $key
     * @param array<string, mixed> $config
     * @return array<int, string>
     */
    public function determine(mixed $key, array $config): array
    {
        return static::$map[(string) $key] ?? static::$fallback;
    }

    /**
     * @param int|string $key
     * @param string $connection
     * @param array<string, mixed> $config
     * @return void
     */
    public function rowMoved(int|string $key, string $connection, array $config): void
    {
        $this->attempts++;

        if ($this->refuseToRedirect) {
            throw new \RuntimeException('the mapping store is unreachable');
        }

        /*
        | A rule based on connection order, like both shipped row-aware
        | strategies use — and deliberately one that lands two along rather
        | than one, so the replica it picks is a connection this move never
        | wrote to. With the immediate successor the choice wraps onto the
        | source, which the retention rule had already kept as a replica: the
        | test then passed without the placement being materialised at all,
        | which the mutation showed.
        */
        $names = array_keys((array) ($config['connections'] ?? []));
        sort($names);
        $index = (int) array_search($connection, $names, true);

        static::$map[(string) $key] = [$connection, $names[($index + 2) % count($names)]];
    }

    /**
     * @param array<string, mixed> $config
     * @return void
     */
    public function afterRebalance(
        string $table,
        string $shardKey,
        ?string $from,
        ?string $to,
        ?int $start,
        ?int $end,
        array $config
    ): void {
        $this->handedOver = true;
    }

    /**
     * @return bool
     */
    public function canRebalance(): bool
    {
        return true;
    }

    /**
     * @param mixed $key
     * @param array<int, string> $connections
     * @param array<string, mixed> $config
     * @return void
     */
    public function recordMeta(mixed $key, array $connections, array $config): void
    {
    }

    /**
     * @param mixed $key
     * @param string $connection
     * @param array<string, mixed> $config
     * @return void
     */
    public function recordReplica(mixed $key, string $connection, array $config): void
    {
    }
}
