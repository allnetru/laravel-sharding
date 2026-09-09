<?php

namespace Allnetru\Sharding\Tests\Feature;

use Allnetru\Sharding\Exceptions\RebalanceIncomplete;
use Allnetru\Sharding\Models\Concerns\Shardable;
use Allnetru\Sharding\ShardingManager;
use Allnetru\Sharding\Strategies\Rebalanceable;
use Allnetru\Sharding\Strategies\RowMoveAware;
use Allnetru\Sharding\Strategies\Strategy;
use Allnetru\Sharding\Support\ShardedTable;
use Allnetru\Sharding\Tests\TestCase;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * A rebalance moves a colocation group, not a table.
 *
 * The routing a rebalance hands over belongs to the group — `rowMoved()` and
 * `afterRebalance()` write it under the group owner — so moving one table's
 * rows and redirecting the key sends every sibling table's reads to the new
 * connection while their rows are still on the old one. Nothing is lost and
 * nothing reaches it either. Until this, the command refused a populated group
 * outright; now it takes the group's tables together, moves every one of them,
 * and changes the routing once.
 *
 * Two tables of one group: an owner keyed by its own id and a child keyed by
 * `owner_id`. The value that decides the shard is the same; the column it is
 * written in is not.
 */
class ShardRebalanceGroupTest extends TestCase
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
            'sharding.strategies.group_mapped' => GroupMappedStrategy::class,
            'sharding.tables' => [
                'g_owners' => ['strategy' => 'group_mapped', 'replica_count' => 0, 'group' => 'g_data'],
                'g_notes' => ['group' => 'g_data'],
            ],
            'sharding.groups' => ['g_data' => ['g_owners', 'g_notes']],
        ]);

        GroupMappedStrategy::$map = [];
        GroupMappedStrategy::$redirects = 0;

        app()->singleton(ShardingManager::class, fn () => new ShardingManager(config('sharding')));

        foreach (['shard_1', 'shard_2', 'shard_3'] as $connection) {
            Schema::connection($connection)->create('g_owners', function (Blueprint $table): void {
                $table->unsignedBigInteger('id')->primary();
                $table->string('name');
                $table->boolean('is_replica')->default(false);
            });

            Schema::connection($connection)->create('g_notes', function (Blueprint $table): void {
                $table->unsignedBigInteger('id')->primary();
                $table->unsignedBigInteger('owner_id');
                $table->string('body');
                $table->boolean('is_replica')->default(false);
            });
        }
    }

    /**
     * Every table's rows move, and the key is redirected once for all of them.
     *
     * @return void
     */
    public function testAWholeGroupMovesAndTheRoutingChangesOnce(): void
    {
        DB::connection('shard_1')->table('g_owners')->insert(['id' => 7, 'name' => 'seven', 'is_replica' => false]);
        DB::connection('shard_1')->table('g_notes')->insert([
            ['id' => 1, 'owner_id' => 7, 'body' => 'first', 'is_replica' => false],
            ['id' => 2, 'owner_id' => 7, 'body' => 'second', 'is_replica' => false],
        ]);

        $moved = app(GroupMappedStrategy::class)->rebalance($this->tables(), 'shard_1', 'shard_3', null, null, [
            'connections' => config('sharding.connections'),
            'table' => 'g_owners',
            'group' => 'g_data',
            'replica_count' => 0,
        ]);

        $this->assertSame(3, $moved, 'not every row of the group was carried');
        $this->assertSame(1, DB::connection('shard_3')->table('g_owners')->where('id', 7)->count(), 'the owner did not arrive');
        $this->assertSame(2, DB::connection('shard_3')->table('g_notes')->where('owner_id', 7)->count(), 'the notes did not arrive');
        $this->assertSame(0, DB::connection('shard_1')->table('g_owners')->count());
        $this->assertSame(0, DB::connection('shard_1')->table('g_notes')->count());

        $this->assertSame(1, GroupMappedStrategy::$redirects, 'the key was redirected per table or per row rather than once');
        $this->assertSame(['shard_3'], GroupMappedStrategy::$map['7']);
    }

    /**
     * A key with rows of one table here and rows of another there is refused.
     *
     * The split the primary-key preflight cannot see, across tables: an owner
     * on the connection being walked and one of its notes on a connection that
     * is not. Moving the owner and redirecting the key would leave the note
     * behind a routing that no longer names where it is. Refused before
     * anything moves.
     *
     * @return void
     */
    public function testAKeySplitAcrossTheGroupsTablesIsRefusedUpFront(): void
    {
        DB::connection('shard_1')->table('g_owners')->insert(['id' => 7, 'name' => 'seven', 'is_replica' => false]);
        DB::connection('shard_2')->table('g_notes')->insert(['id' => 1, 'owner_id' => 7, 'body' => 'astray', 'is_replica' => false]);

        try {
            app(GroupMappedStrategy::class)->rebalance($this->tables(), 'shard_1', 'shard_3', null, null, [
                'connections' => config('sharding.connections'),
                'table' => 'g_owners',
                'group' => 'g_data',
                'replica_count' => 0,
            ]);

            $this->fail('half of a key was moved');
        } catch (RebalanceIncomplete $e) {
            $this->assertSame(0, $e->moved, 'rows moved before the split was found');
        }

        $this->assertSame(1, DB::connection('shard_1')->table('g_owners')->count(), 'the owner was carried off');
        $this->assertSame(0, DB::connection('shard_3')->table('g_owners')->count());
        $this->assertSame(0, GroupMappedStrategy::$redirects, 'the routing was touched');
    }

    /**
     * A misplaced key this run never touches does not block it.
     *
     * The partial-key check used to look at every key in range and refuse when
     * any of them had rows on a connection that was neither walked nor the
     * target — including keys with no rows on the walked connection at all.
     * With `--from=shard_1 --to=shard_3`, a key sitting entirely on shard_2
     * refused the run over rows the run was never going to move. Only keys
     * with rows on a walked connection count.
     *
     * @return void
     */
    public function testAMisplacedKeyThisRunDoesNotTouchDoesNotBlockIt(): void
    {
        // the key this run is about, on the connection being drained
        DB::connection('shard_1')->table('g_owners')->insert(['id' => 7, 'name' => 'seven', 'is_replica' => false]);
        // and one it is not about, somewhere else entirely, with routing that
        // says it belongs on shard_1 — misplaced, and not this run's problem
        DB::connection('shard_2')->table('g_owners')->insert(['id' => 9, 'name' => 'nine', 'is_replica' => false]);

        $moved = app(GroupMappedStrategy::class)->rebalance($this->tables(), 'shard_1', 'shard_3', null, null, [
            'connections' => config('sharding.connections'),
            'table' => 'g_owners',
            'group' => 'g_data',
            'replica_count' => 0,
        ]);

        $this->assertSame(1, $moved);
        $this->assertSame(1, DB::connection('shard_3')->table('g_owners')->where('id', 7)->count());
        $this->assertSame(1, DB::connection('shard_2')->table('g_owners')->where('id', 9)->count(), 'the untouched key was disturbed');
    }

    /**
     * The command takes every table of the group and moves them together.
     *
     * @return void
     */
    public function testTheCommandMovesTheGroupItIsGiven(): void
    {
        DB::connection('shard_1')->table('g_owners')->insert(['id' => 7, 'name' => 'seven', 'is_replica' => false]);
        DB::connection('shard_1')->table('g_notes')->insert(['id' => 1, 'owner_id' => 7, 'body' => 'first', 'is_replica' => false]);

        $this->artisan('shards:rebalance', [
            'model' => [GroupOwner::class, GroupNote::class],
            '--from' => 'shard_1',
            '--to' => 'shard_3',
        ])->assertSuccessful();

        $this->assertSame(1, DB::connection('shard_3')->table('g_owners')->count());
        $this->assertSame(1, DB::connection('shard_3')->table('g_notes')->count());
        $this->assertSame(['shard_3'], GroupMappedStrategy::$map['7']);
    }

    /**
     * A populated table of the group that is not named stops the command.
     *
     * @return void
     */
    public function testTheCommandRefusesAPopulatedTableLeftUnnamed(): void
    {
        DB::connection('shard_1')->table('g_owners')->insert(['id' => 7, 'name' => 'seven', 'is_replica' => false]);
        DB::connection('shard_1')->table('g_notes')->insert(['id' => 1, 'owner_id' => 7, 'body' => 'first', 'is_replica' => false]);

        $this->artisan('shards:rebalance', [
            'model' => [GroupOwner::class],
            '--from' => 'shard_1',
            '--to' => 'shard_3',
        ])->assertFailed();

        $this->assertSame(1, DB::connection('shard_1')->table('g_owners')->count(), 'the owner moved without its notes');
        $this->assertSame(0, GroupMappedStrategy::$redirects);
    }

    /**
     * An empty sibling may be left out: it has nothing to strand.
     *
     * @return void
     */
    public function testAnEmptySiblingMayBeLeftOut(): void
    {
        DB::connection('shard_1')->table('g_owners')->insert(['id' => 7, 'name' => 'seven', 'is_replica' => false]);

        $this->artisan('shards:rebalance', [
            'model' => [GroupOwner::class],
            '--from' => 'shard_1',
            '--to' => 'shard_3',
        ])->assertSuccessful();

        $this->assertSame(1, DB::connection('shard_3')->table('g_owners')->count());
    }

    /**
     * Tables of two different groups cannot be moved as one.
     *
     * @return void
     */
    public function testTablesOfDifferentGroupsAreRefused(): void
    {
        config([
            'sharding.tables.loners' => ['strategy' => 'group_mapped', 'replica_count' => 0],
        ]);
        app()->singleton(ShardingManager::class, fn () => new ShardingManager(config('sharding')));

        foreach (['shard_1', 'shard_2', 'shard_3'] as $connection) {
            Schema::connection($connection)->create('loners', function (Blueprint $table): void {
                $table->unsignedBigInteger('id')->primary();
                $table->boolean('is_replica')->default(false);
            });
        }

        $this->artisan('shards:rebalance', [
            'model' => [GroupOwner::class, Loner::class],
            '--from' => 'shard_1',
            '--to' => 'shard_3',
        ])->assertFailed();
    }

    /**
     * @return list<ShardedTable>
     */
    protected function tables(): array
    {
        return [
            new ShardedTable('g_owners', 'id', 'id'),
            new ShardedTable('g_notes', 'owner_id', 'id'),
        ];
    }
}

/**
 * Routing kept in a static map, redirect by redirect, so the count is visible.
 */
class GroupMappedStrategy implements Strategy, RowMoveAware
{
    use Rebalanceable;

    /** @var array<string, list<string>> Where each key currently lives. */
    public static array $map = [];

    /** How many times the routing was redirected. */
    public static int $redirects = 0;

    /**
     * @param mixed $key
     * @param array<string, mixed> $config
     * @return array<int, string>
     */
    public function determine(mixed $key, array $config): array
    {
        return static::$map[(string) $key] ?? ['shard_1'];
    }

    /**
     * @param int|string $key
     * @param string $connection
     * @param array<string, mixed> $config
     * @return void
     */
    public function rowMoved(int|string $key, string $connection, array $config): void
    {
        static::$redirects++;
        static::$map[(string) $key] = [$connection];
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

/**
 * The group's owner, sharded by its own identifier.
 */
class GroupOwner extends Model
{
    use Shardable;

    protected $table = 'g_owners';

    protected string $shardKey = 'id';

    public $incrementing = false;

    public $timestamps = false;

    protected $guarded = [];
}

/**
 * The group's child, sharded by the owner it belongs to.
 */
class GroupNote extends Model
{
    use Shardable;

    protected $table = 'g_notes';

    protected string $shardKey = 'owner_id';

    public $incrementing = false;

    public $timestamps = false;

    protected $guarded = [];
}

/**
 * A table of no group at all.
 */
class Loner extends Model
{
    use Shardable;

    protected $table = 'loners';

    protected string $shardKey = 'id';

    public $incrementing = false;

    public $timestamps = false;

    protected $guarded = [];
}
