<?php

namespace Allnetru\Sharding\Tests\Feature;

use Allnetru\Sharding\Exceptions\RebalanceIncomplete;
use Allnetru\Sharding\ShardingManager;
use Allnetru\Sharding\Strategies\Rebalanceable;
use Allnetru\Sharding\Strategies\Strategy;
use Allnetru\Sharding\Strategies\SupportsAfterRebalance;
use Allnetru\Sharding\Tests\TestCase;
use Illuminate\Database\Eloquent\Model;
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

        $moved = $this->strategy()->rebalance('grants', 'user_id', 'id', $source, null, null, null, [
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

    /**
     * A different row holding the same identifier is refused, not overwritten.
     *
     * Two shards that allocated their identifiers independently hold different
     * rows under the same number, and both are real data. Before the lookup
     * went by the row key this could not happen — it asked by the shard key,
     * missed such an occupant, and the insert failed on the unique index and
     * rolled back — so the fix that split the keys introduced it. Found in
     * review.
     *
     * @return void
     */
    public function testADifferentRowHoldingTheSameIdentifierIsRefused(): void
    {
        $target = app(ShardingManager::class)->connectionFor('grants', 1)[0];
        $source = $target === 'shard_1' ? 'shard_2' : 'shard_1';

        DB::connection($source)->table('grants')->insert([
            'id' => 1,
            'user_id' => 1,
            'role' => 'mine',
            'is_replica' => false,
        ]);

        // the same identifier, a different row, and a shard key that belongs
        // right where it already is
        DB::connection($target)->table('grants')->insert([
            'id' => 1,
            'user_id' => 99,
            'role' => 'somebody else',
            'is_replica' => false,
        ]);

        $strategy = $this->strategy();

        try {
            $strategy->rebalance('grants', 'user_id', 'id', $source, null, null, null, [
                'connections' => config('sharding.connections'),
                'table' => 'grants',
            ]);

            $this->fail('a refused row was reported as a successful rebalance');
        } catch (RebalanceIncomplete $e) {
            $this->assertSame(1, $e->failed);
            $this->assertSame(0, $e->moved);
        }

        $this->assertSame(
            'mine',
            DB::connection($source)->table('grants')->where('id', 1)->value('role'),
            'the source copy was let go of',
        );
        $this->assertSame(
            'somebody else',
            DB::connection($target)->table('grants')->where('id', 1)->value('role'),
            'a different row was overwritten',
        );

        // the half that makes the refusal matter: handing the range to the new
        // connection while a row is still on the old one is what loses it
        $this->assertFalse($strategy->handedOver, 'the routing was advanced over a row left behind');
    }

    /**
     * The copy left behind is deleted, not silently kept as a replica.
     *
     * When the target already held the row — a run interrupted between the two
     * commits — the source was marked `is_replica = true` whatever connection
     * it was. With no replicas configured that leaves a copy nothing
     * advertises, hidden by the `is_replica = false` scope, so the table
     * carries a duplicate that nothing can see and nothing rebuilds. Found in
     * review.
     *
     * @return void
     */
    public function testTheCopyLeftBehindIsNotKeptAsAReplicaNobodyAskedFor(): void
    {
        $target = app(ShardingManager::class)->connectionFor('grants', 1)[0];
        $source = $target === 'shard_1' ? 'shard_2' : 'shard_1';

        $row = ['id' => 1, 'user_id' => 1, 'role' => 'one', 'is_replica' => false];

        // exactly what an interruption between the two commits leaves behind
        DB::connection($source)->table('grants')->insert($row);
        DB::connection($target)->table('grants')->insert($row);

        $moved = $this->strategy()->rebalance('grants', 'user_id', 'id', $source, null, null, null, [
            'connections' => config('sharding.connections'),
            'table' => 'grants',
        ]);

        $this->assertSame(1, $moved);
        $this->assertSame(
            0,
            DB::connection($source)->table('grants')->count(),
            'the source kept a copy this key has no replica connection for',
        );
    }

    /**
     * The command says it failed when a row was left behind.
     *
     * The counter was private to the trait, so `rebalance()` returned zero
     * moved and the command printed «Moved 0 records» and exited
     * successfully — over rows that are still on the connection the run was
     * asked to empty. Found in review.
     *
     * @return void
     */
    public function testTheCommandFailsWhenARowIsRefused(): void
    {
        config([
            'sharding.strategies.grants_hash' => GrantsStrategy::class,
            'sharding.tables.grants.strategy' => 'grants_hash',
        ]);
        app()->singleton(ShardingManager::class, fn () => new ShardingManager(config('sharding')));

        $target = app(ShardingManager::class)->connectionFor('grants', 1)[0];
        $source = $target === 'shard_1' ? 'shard_2' : 'shard_1';

        DB::connection($source)->table('grants')->insert([
            'id' => 1,
            'user_id' => 1,
            'role' => 'mine',
            'is_replica' => false,
        ]);
        DB::connection($target)->table('grants')->insert([
            'id' => 1,
            'user_id' => 99,
            'role' => 'somebody else',
            'is_replica' => false,
        ]);

        $this->artisan('shards:rebalance', ['model' => Grant::class, '--from' => $source])
            ->assertFailed();
    }

    /**
     * A clash anywhere in the range means nothing moves at all.
     *
     * Withholding the range handoff protects the rows left behind and strands
     * every row committed before the clash: the old range still routes keyed
     * reads to the source, which those rows have left. There is no right
     * choice after the fact, so the choice is removed — the first pass reads
     * every row that would move and refuses the whole run. Found in review.
     *
     * @return void
     */
    public function testAClashAnywhereInTheRangeMovesNothing(): void
    {
        $target = app(ShardingManager::class)->connectionFor('grants', 1)[0];
        $source = $target === 'shard_1' ? 'shard_2' : 'shard_1';

        // two rows of one user: the first would move cleanly, the second clashes
        DB::connection($source)->table('grants')->insert([
            ['id' => 1, 'user_id' => 1, 'role' => 'first', 'is_replica' => false],
            ['id' => 2, 'user_id' => 1, 'role' => 'second', 'is_replica' => false],
        ]);
        DB::connection($target)->table('grants')->insert([
            'id' => 2,
            'user_id' => 99,
            'role' => 'somebody else',
            'is_replica' => false,
        ]);

        try {
            $this->strategy()->rebalance('grants', 'user_id', 'id', $source, null, null, null, [
                'connections' => config('sharding.connections'),
                'table' => 'grants',
            ]);

            $this->fail('the run went ahead over a row it could not move');
        } catch (RebalanceIncomplete $e) {
            $this->assertSame(0, $e->moved, 'rows were moved before the clash was found');
        }

        $this->assertSame(
            ['first', 'second'],
            DB::connection($source)->table('grants')->orderBy('id')->pluck('role')->all(),
            'a row was carried off before the run was refused',
        );
    }

    /**
     * Two sources holding one identifier are refused before either moves.
     *
     * The occupancy preflight cannot see a clash the run creates itself: two
     * source connections hold different rows under one identifier and both are
     * sent to a third connection, empty when it was looked at. The first
     * landed, the second found it — and without this check the first was
     * already off its source and stranded. Found in review.
     *
     * The target has to be a connection that is not itself a source, or the
     * first row never moves and the case cannot arise: that is what my first
     * attempt at this test got wrong, and the mutation showed it.
     *
     * @return void
     */
    public function testTwoSourcesHoldingOneIdentifierAreRefused(): void
    {
        config([
            'database.connections.shard_3' => ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => ''],
        ]);

        Schema::connection('shard_3')->create('grants', function (Blueprint $table): void {
            $table->unsignedBigInteger('id')->primary();
            $table->unsignedBigInteger('user_id');
            $table->string('role');
            $table->boolean('is_replica')->default(false);
        });

        DB::connection('shard_1')->table('grants')->insert([
            'id' => 1,
            'user_id' => 1,
            'role' => 'from one',
            'is_replica' => false,
        ]);
        DB::connection('shard_2')->table('grants')->insert([
            'id' => 1,
            'user_id' => 2,
            'role' => 'from two',
            'is_replica' => false,
        ]);

        try {
            $this->strategy()->rebalance('grants', 'user_id', 'id', null, 'shard_3', null, null, [
                'connections' => config('sharding.connections'),
                'table' => 'grants',
            ]);

            $this->fail('the run went ahead over two rows sharing an identifier');
        } catch (RebalanceIncomplete $e) {
            $this->assertSame(0, $e->moved, 'a row was moved before the clash was found');
        }

        $this->assertSame(
            'from one',
            DB::connection('shard_1')->table('grants')->where('id', 1)->value('role'),
        );
        $this->assertSame(
            'from two',
            DB::connection('shard_2')->table('grants')->where('id', 1)->value('role'),
        );
        $this->assertSame(0, DB::connection('shard_3')->table('grants')->count(), 'a row reached the target');
    }

    /**
     * A replica sharing its primary's key is not read as a clash.
     *
     * The normal state of a replicated row: the copy carries the same primary
     * key on another connection. Counting it would refuse every rebalance of a
     * replicated table, so the shared-key check looks at primaries only — on
     * both sides.
     *
     * The fixture has to configure the replica for this to mean anything. With
     * `replica_count` at zero a replica row is a state the package cannot
     * produce, and the run then legitimately treats it as a stray copy — which
     * is what my first attempt at this test measured.
     *
     * @return void
     */
    public function testAReplicaSharingItsPrimarysKeyIsNotAClash(): void
    {
        config(['sharding.tables.grants.replica_count' => 1]);
        app()->singleton(ShardingManager::class, fn () => new ShardingManager(config('sharding')));

        /*
        | A key whose primary lands on the *first* connection of the list, so
        | its replica sits on a later one. The pairwise check only looks
        | forward, so that orientation is the one where the replica can be
        | mistaken for a clash — with the other orientation the walk skips the
        | replica and the pair is never examined. My first attempt at this test
        | took whatever key 1 gave and measured nothing, which the mutation
        | showed.
        */
        $userId = null;
        $manager = app(ShardingManager::class);

        for ($candidate = 1; $candidate <= 50; $candidate++) {
            if (($manager->connectionFor('grants', $candidate)[0] ?? null) === 'shard_1') {
                $userId = $candidate;

                break;
            }
        }

        $this->assertNotNull($userId, 'no key of the first fifty lands on the first connection');

        $placement = $manager->connectionFor('grants', $userId);

        $this->assertCount(2, $placement, 'the replica was not configured');

        [$primary, $replica] = $placement;

        $row = ['id' => 1, 'user_id' => $userId, 'role' => 'one', 'is_replica' => false];

        // both copies exactly where this key says they belong
        DB::connection($primary)->table('grants')->insert($row);
        DB::connection($replica)->table('grants')->insert(array_merge($row, ['is_replica' => true]));

        $moved = $this->strategy()->rebalance('grants', 'user_id', 'id', null, null, null, null, [
            'connections' => config('sharding.connections'),
            'table' => 'grants',
            'replica_count' => 1,
        ]);

        $this->assertSame(0, $moved, 'nothing needed moving');
        $this->assertNotNull(
            DB::connection($replica)->table('grants')->where('id', 1)->first(),
            'the replica was disturbed',
        );
    }

    /**
     * An interrupted move is resumable: the same row on both is not a clash.
     *
     * The shared-key check counted every primary key two source connections
     * both held, which is exactly the state an interruption between the two
     * commits leaves — the same row, byte for byte, on both. So the check
     * refused a rerun of precisely the run that needs one. The payloads decide,
     * the way they decide everywhere else here. Found in review.
     *
     * @return void
     */
    public function testAnInterruptedMoveIsStillResumable(): void
    {
        $target = app(ShardingManager::class)->connectionFor('grants', 1)[0];
        $source = $target === 'shard_1' ? 'shard_2' : 'shard_1';

        $row = ['id' => 1, 'user_id' => 1, 'role' => 'one', 'is_replica' => false];

        // what an interruption between the target and source commits leaves
        DB::connection($source)->table('grants')->insert($row);
        DB::connection($target)->table('grants')->insert($row);

        $moved = $this->strategy()->rebalance('grants', 'user_id', 'id', null, null, null, null, [
            'connections' => config('sharding.connections'),
            'table' => 'grants',
        ]);

        $this->assertSame(1, $moved, 'the rerun was refused');
        $this->assertSame(
            0,
            DB::connection($source)->table('grants')->count(),
            'the stale source copy was not released',
        );
    }

    /**
     * A run that would move only part of a key is refused before it moves.
     *
     * The primary-key preflight asks whether two connections hold the same
     * *row*; this is two connections holding the same *key*, which on a
     * colocated table they do with entirely different row keys. With `--from`
     * naming one of them, its rows moved and the rest stayed — so the key
     * ended up spread across two connections, its routing could name only one,
     * and the other half stopped being found. Detected afterwards that is
     * unrecoverable: the move has happened and rerunning finds the same split.
     * Found in review.
     *
     * @return void
     */
    public function testARunThatWouldMoveOnlyPartOfAKeyIsRefused(): void
    {
        config([
            'database.connections.shard_3' => ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => ''],
            'sharding.connections' => [
                'shard_1' => ['weight' => 1],
                'shard_2' => ['weight' => 1],
                'shard_3' => ['weight' => 1],
            ],
        ]);

        Schema::connection('shard_3')->create('grants', function (Blueprint $table): void {
            $table->unsignedBigInteger('id')->primary();
            $table->unsignedBigInteger('user_id');
            $table->string('role');
            $table->boolean('is_replica')->default(false);
        });

        app()->singleton(ShardingManager::class, fn () => new ShardingManager(config('sharding')));

        // one user, two roles, two connections, and different row keys — so
        // nothing about the primary keys says anything is wrong
        DB::connection('shard_1')->table('grants')->insert([
            'id' => 1,
            'user_id' => 1,
            'role' => 'here',
            'is_replica' => false,
        ]);
        DB::connection('shard_2')->table('grants')->insert([
            'id' => 2,
            'user_id' => 1,
            'role' => 'and there',
            'is_replica' => false,
        ]);

        try {
            $this->strategy()->rebalance('grants', 'user_id', 'id', 'shard_1', 'shard_3', null, null, [
                'connections' => config('sharding.connections'),
                'table' => 'grants',
            ]);

            $this->fail('half of a key was moved');
        } catch (RebalanceIncomplete $e) {
            $this->assertSame(0, $e->moved);
        }

        $this->assertSame(1, DB::connection('shard_1')->table('grants')->count(), 'a row was carried off');
        $this->assertSame(1, DB::connection('shard_2')->table('grants')->count());
        $this->assertSame(0, DB::connection('shard_3')->table('grants')->count());
    }

    /**
     * A shard being added is not read from, table or no table.
     *
     * `connectionsFor()` answers with everything configured while
     * `connectionFor()` leaves out anything in `DB_SHARD_MIGRATIONS`, and the
     * whole-topology scans the preflight added used the first — so a newly
     * added shard whose table is not migrated yet killed an otherwise targeted
     * rebalance before anything moved. Found in review.
     *
     * @return void
     */
    public function testAShardBeingAddedIsNotScanned(): void
    {
        config([
            'database.connections.shard_3' => ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => ''],
            'sharding.connections' => [
                'shard_1' => ['weight' => 1],
                'shard_2' => ['weight' => 1],
                'shard_3' => ['weight' => 1],
            ],
            // added, and not ready: no grants table on it at all
            'sharding.migrations' => ['shard_3' => true],
        ]);

        app()->singleton(ShardingManager::class, fn () => new ShardingManager(config('sharding')));

        $target = app(ShardingManager::class)->connectionFor('grants', 1)[0];
        $source = $target === 'shard_1' ? 'shard_2' : 'shard_1';

        DB::connection($source)->table('grants')->insert([
            'id' => 1,
            'user_id' => 1,
            'role' => 'one',
            'is_replica' => false,
        ]);

        $moved = $this->strategy()->rebalance('grants', 'user_id', 'id', $source, null, null, null, [
            'connections' => config('sharding.connections'),
            'table' => 'grants',
        ]);

        $this->assertSame(1, $moved, 'the run died on the shard that is not ready');
        $this->assertSame(1, DB::connection($target)->table('grants')->count());
    }

    /**
     * A key whose rows are primaries on two connections is not decided.
     *
     * Choosing either connection strands the rows on the other, so it is a
     * failure rather than a guess — and it is refused up front by
     * `keysLeftBehind()` rather than noticed afterwards. This is also what
     * makes the placement pass simple: it never has to meet an occupant
     * claiming to be the primary, because that state cannot get past here.
     *
     * @return void
     */
    public function testAKeyWithPrimariesOnTwoConnectionsIsNotDecided(): void
    {
        $target = app(ShardingManager::class)->connectionFor('grants', 1)[0];
        $other = $target === 'shard_1' ? 'shard_2' : 'shard_1';

        // the same row on both, and a --from that only walks one of them, so
        // the move pass cannot resolve it the way a plain rerun would
        $row = ['id' => 1, 'user_id' => 1, 'role' => 'one', 'is_replica' => false];

        DB::connection($target)->table('grants')->insert($row);
        DB::connection($other)->table('grants')->insert($row);

        try {
            $this->strategy()->rebalance('grants', 'user_id', 'id', $target, null, null, null, [
                'connections' => config('sharding.connections'),
                'table' => 'grants',
            ]);

            $this->fail('a key on two connections was decided rather than refused');
        } catch (RebalanceIncomplete $e) {
            $this->assertSame(1, $e->failed);
        }

        $this->assertSame(1, DB::connection($target)->table('grants')->count());
        $this->assertSame(1, DB::connection($other)->table('grants')->count());
    }

    /**
     * A strategy using the trait, routing through the manager.
     *
     * @return Strategy
     */
    protected function strategy(): Strategy
    {
        return new class() implements Strategy, SupportsAfterRebalance {
            use Rebalanceable;

            /** Whether the routing was handed over to the new connection. */
            public bool $handedOver = false;

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
    }
}

/**
 * The colocated table under test.
 */
class Grant extends Model
{
    protected $table = 'grants';

    protected string $shardKey = 'user_id';

    public $incrementing = false;

    public $timestamps = false;

    protected $guarded = [];
}

/**
 * The same strategy the tests build by hand, reachable from the config.
 */
class GrantsStrategy implements Strategy
{
    use Rebalanceable;

    /**
     * @param mixed $key
     * @param array<string, mixed> $config
     * @return array<int, string>
     */
    public function determine(mixed $key, array $config): array
    {
        $names = array_keys((array) ($config['connections'] ?? []));

        return [$names[((int) $key) % max(1, count($names))]];
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
