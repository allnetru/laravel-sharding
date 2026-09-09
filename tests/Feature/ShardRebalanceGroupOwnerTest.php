<?php

namespace Allnetru\Sharding\Tests\Feature;

use Allnetru\Sharding\ShardingManager;
use Allnetru\Sharding\Strategies\Strategy;
use Allnetru\Sharding\Tests\TestCase;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Rebalancing a colocated child reads the group owner's configuration.
 *
 * A child table's own entry declares only its group — the package documents
 * that shape and this project's config uses it for thirty tables — so it has
 * no strategy and no slot size of its own. The command read that entry
 * directly, fell back to the default strategy, and handed the child's name to
 * the strategy as the table its slots live under.
 *
 * The rows then moved and the slot metadata was written somewhere nothing
 * reads, so a keyed query went on being routed to the shard the old metadata
 * still named: the rebalance appeared to work and left the data unreachable.
 * Found in review.
 */
class ShardRebalanceGroupOwnerTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config([
            'database.connections.shard_1' => ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => ''],
            'database.connections.shard_2' => ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => ''],
            'sharding.connections' => ['shard_1' => ['weight' => 1], 'shard_2' => ['weight' => 1]],
            'sharding.default' => 'hash',
            'sharding.strategies.recording' => RecordingStrategy::class,
            'sharding.tables' => [
                // the owner carries the strategy and the slot size
                'owners' => ['strategy' => 'recording', 'slot_size' => 1000, 'group' => 'owned_data'],
                // the child carries only its group, which is the documented shape
                'owned_notes' => ['group' => 'owned_data'],
            ],
            'sharding.groups' => ['owned_data' => ['owners', 'owned_notes']],
        ]);

        app()->singleton(ShardingManager::class, fn () => new ShardingManager(config('sharding')));

        RecordingStrategy::$config = null;

        foreach (['shard_1', 'shard_2'] as $connection) {
            Schema::connection($connection)->create('owned_notes', function (Blueprint $table): void {
                $table->unsignedBigInteger('id')->primary();
                $table->unsignedBigInteger('owner_id');
                $table->boolean('is_replica')->default(false);
            });

            Schema::connection($connection)->create('owners', function (Blueprint $table): void {
                $table->unsignedBigInteger('id')->primary();
                $table->boolean('is_replica')->default(false);
            });
        }
    }

    /**
     * The strategy and the slot table come from the owner, not the child.
     *
     * @return void
     */
    public function testTheChildIsRebalancedUnderItsOwnersConfiguration(): void
    {
        $this->artisan('shards:rebalance', ['model' => OwnedNote::class])->assertSuccessful();

        $config = RecordingStrategy::$config;

        $this->assertNotNull($config, 'the owner\'s strategy was never reached');
        $this->assertSame('owners', $config['table'], 'the slots were written under the child\'s name');
        $this->assertSame(1000, $config['slot_size'] ?? null, 'the owner\'s slot size was not passed on');
    }

    /**
     * The new range is written where the routing reads it from.
     *
     * `RangeStrategy::afterRebalance()` wrote to
     * `sharding.tables.<the table it was handed>.ranges`, and the table it was
     * handed is the child's. The manager resolves the group's owner, so the
     * range was written where nothing reads it: the rows moved and the
     * effective range went on naming the old connection. `DbRangeStrategy`
     * already resolved the scope correctly, which is all the difference was.
     * Found in review.
     *
     * @return void
     */
    public function testTheNewRangeIsWrittenUnderTheGroupOwner(): void
    {
        config([
            'sharding.tables.owners.strategy' => 'range',
            'sharding.tables.owners.ranges' => [],
        ]);
        app()->singleton(ShardingManager::class, fn () => new ShardingManager(config('sharding')));

        $this->artisan('shards:rebalance', [
            'model' => OwnedNote::class,
            '--to' => 'shard_2',
            '--start' => 1,
            '--end' => 100,
        ])->assertSuccessful();

        $this->assertSame(
            [['start' => 1, 'end' => 100, 'connection' => 'shard_2']],
            config('sharding.tables.owners.ranges'),
            'the range was not written under the group owner',
        );
        $this->assertSame(
            [],
            (array) config('sharding.tables.owned_notes.ranges', []),
            'the range was written under the child, where nothing reads it',
        );
    }

    /**
     * A populated colocation group is not rebalanced one table at a time.
     *
     * The routing belongs to the group: `rowMoved()` and `afterRebalance()`
     * write it under the owner, so moving this table's rows and redirecting
     * the key sends every sibling's reads to the new connection while their
     * rows stay on the old one. Nothing is lost and nothing reaches it either.
     * Refused rather than half-done. Found in review.
     *
     * @return void
     */
    public function testAPopulatedColocationGroupIsRefused(): void
    {
        DB::connection('shard_1')->table('owners')->insert(['id' => 1, 'is_replica' => false]);

        $this->artisan('shards:rebalance', ['model' => OwnedNote::class])->assertFailed();

        $this->assertNull(RecordingStrategy::$config, 'the rebalance ran over a populated group');
    }

    /**
     * An active connection that routes the table and has not got it is refused.
     *
     * Leaving it out of the scans does not leave it out of `connectionFor()`:
     * the rows would move, the routing would be handed over, and the pass that
     * makes the placement real would die on the missing table with the metadata
     * already advertising a replica that cannot exist. Found in review — and it
     * was my own fix from the round before, which skipped such a connection
     * instead of refusing it.
     *
     * @return void
     */
    public function testAnActiveConnectionWithoutTheTableIsRefused(): void
    {
        config([
            'database.connections.shard_3' => ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => ''],
            'sharding.connections' => [
                'shard_1' => ['weight' => 1],
                'shard_2' => ['weight' => 1],
                // active, routed to, and without the table
                'shard_3' => ['weight' => 1],
            ],
        ]);

        app()->singleton(ShardingManager::class, fn () => new ShardingManager(config('sharding')));

        $this->artisan('shards:rebalance', ['model' => OwnedNote::class])->assertFailed();

        $this->assertNull(RecordingStrategy::$config, 'the rebalance ran against a schema it would die on');
    }

    /**
     * A range bound that is not a number is refused, not quietly turned to 0.
     *
     * A slot is a numeric range and the bounds apply to the shard key, so a
     * string shard key has no range this command can express. Casting made
     * `--start=tenant-a` into 0 and selected an unrelated set of rows.
     * Reachable only since the bounds moved from the primary key onto the
     * shard key. Found in review.
     *
     * @return void
     */
    public function testANonNumericRangeBoundIsRefused(): void
    {
        $this->artisan('shards:rebalance', ['model' => OwnedNote::class, '--start' => 'tenant-a'])
            ->assertFailed();

        $this->assertNull(RecordingStrategy::$config, 'the rebalance ran on a bound it could not express');
    }
}

/**
 * A strategy that only remembers how it was called.
 */
class RecordingStrategy implements Strategy
{
    /** @var array<string, mixed>|null */
    public static ?array $config = null;

    /**
     * @param mixed $key
     * @param array<string, mixed> $config
     * @return array<int, string>
     */
    public function determine(mixed $key, array $config): array
    {
        return ['shard_1'];
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

    /**
     * @return bool
     */
    public function canRebalance(): bool
    {
        return true;
    }

    /**
     * @param array<string, mixed> $config
     */
    public function rebalance(
        string $table,
        string $shardKey,
        string $rowKey,
        ?string $from,
        ?string $to,
        ?int $start,
        ?int $end,
        array $config
    ): int {
        static::$config = $config;

        return 0;
    }
}

/**
 * The child table, so the command can resolve a model for it.
 */
class OwnedNote extends Model
{
    protected $table = 'owned_notes';

    protected string $shardKey = 'owner_id';

    public $incrementing = false;

    public $timestamps = false;

    protected $guarded = [];
}
