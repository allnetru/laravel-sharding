<?php

namespace Allnetru\Sharding\Tests\Feature;

use Allnetru\Sharding\Models\Concerns\Shardable;
use Allnetru\Sharding\ShardingManager;
use Allnetru\Sharding\Tests\TestCase;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Putting existing rows on the shard their own key names.
 *
 * The repair an upgrade needs, and it was broken in the way that mattered
 * most: the target was resolved from the **primary** key. For a colocated
 * table those are different columns, so `user_roles` rows were scattered by
 * their own identifiers — exactly the rows colocation exists to keep together
 * — and a group could come out worse than it went in.
 *
 * It could not run at all on most applications either: it looked for
 * `App\Models\<Table>` for every table of the group, which fails for anything
 * that keeps its models elsewhere. Nothing needs a model per table: the shard
 * key of a group is the group's.
 */
class ShardDistributeTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config([
            'database.connections.shard_1' => ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => ''],
            'database.connections.shard_2' => ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => ''],
            'sharding.connections' => ['shard_1' => ['weight' => 1], 'shard_2' => ['weight' => 1]],
            'sharding.tables' => [
                'holders' => ['strategy' => 'hash', 'replica_count' => 0, 'group' => 'holder_data'],
                'holder_notes' => ['strategy' => 'hash', 'replica_count' => 0, 'group' => 'holder_data'],
            ],
            'sharding.groups' => ['holder_data' => ['holders', 'holder_notes']],
        ]);

        app()->singleton(ShardingManager::class, fn () => new ShardingManager(config('sharding')));

        foreach (['shard_1', 'shard_2'] as $connection) {
            Schema::connection($connection)->create('holders', function (Blueprint $table): void {
                $table->unsignedBigInteger('id')->primary();
                $table->boolean('is_replica')->default(false);
            });

            Schema::connection($connection)->create('holder_notes', function (Blueprint $table): void {
                $table->unsignedBigInteger('id')->primary();
                $table->unsignedBigInteger('holder_id');
                $table->boolean('is_replica')->default(false);
            });
        }
    }

    /**
     * A row on the wrong shard is carried to the right one.
     *
     * @return void
     */
    public function testAMisplacedRowIsMoved(): void
    {
        [$right, $wrong] = $this->shardsFor(1);

        DB::connection($wrong)->table('holders')->insert(['id' => 1, 'is_replica' => false]);

        $this->artisan('shards:distribute', ['model' => [Holder::class]])->assertSuccessful();

        $this->assertSame(0, DB::connection($wrong)->table('holders')->count(), 'it stayed behind');
        $this->assertSame(1, DB::connection($right)->table('holders')->count(), 'it did not arrive');
    }

    /**
     * A colocated table is moved by its group's key, not by its own id.
     *
     * The bug this command had: resolving the target from the primary key
     * scattered the rows colocation exists to keep together. The identifiers
     * below are chosen so the two answers differ — and the note's own model is
     * passed, because the column its key lives in is `holder_id` and only the
     * note knows that.
     *
     * @return void
     */
    public function testAColocatedRowIsMovedByTheGroupKey(): void
    {
        $manager = app(ShardingManager::class);

        $holderId = null;

        // an identifier whose own shard is not its holder's, so «by which key»
        // is a question with two different answers
        foreach (range(1, 40) as $candidate) {
            $byHolder = $manager->connectionFor(new HolderNote(), $candidate)[0];
            $byOwnId = $manager->connectionFor(new Holder(), 7)[0];

            if ($byHolder !== $byOwnId) {
                $holderId = $candidate;

                break;
            }
        }

        $this->assertNotNull($holderId, 'the fixture found no pair that tells the two keys apart');

        [$right, $wrong] = $this->shardsFor($holderId, HolderNote::class);

        DB::connection($wrong)->table('holder_notes')->insert([
            'id' => 7,
            'holder_id' => $holderId,
            'is_replica' => false,
        ]);

        $this->artisan('shards:distribute', ['model' => [Holder::class, HolderNote::class]])
            ->assertSuccessful();

        $this->assertSame(
            1,
            DB::connection($right)->table('holder_notes')->where('holder_id', $holderId)->count(),
            'the note did not follow its holder',
        );
    }

    /**
     * A dry run answers the question and touches nothing.
     *
     * @return void
     */
    public function testADryRunMovesNothing(): void
    {
        [, $wrong] = $this->shardsFor(1);

        DB::connection($wrong)->table('holders')->insert(['id' => 1, 'is_replica' => false]);

        $this->artisan('shards:distribute', ['model' => [Holder::class], '--dry-run' => true])
            ->expectsOutputToContain('1')
            ->assertSuccessful();

        $this->assertSame(1, DB::connection($wrong)->table('holders')->count(), 'a dry run moved a row');
    }

    /**
     * A replica copy is left where it is.
     *
     * It belongs on a replica connection rather than on the primary its key
     * names, so the rule this command applies is not its rule. Moving one by
     * the wrong rule would break the pair it is half of.
     *
     * @return void
     */
    public function testAReplicaIsLeftAlone(): void
    {
        [, $wrong] = $this->shardsFor(1);

        DB::connection($wrong)->table('holders')->insert(['id' => 1, 'is_replica' => true]);

        $this->artisan('shards:distribute', ['model' => [Holder::class]])->assertSuccessful();

        $this->assertSame(1, DB::connection($wrong)->table('holders')->count(), 'a replica was moved');
    }

    /**
     * A row already in place is not rewritten.
     *
     * @return void
     */
    public function testARowInPlaceIsNotTouched(): void
    {
        [$right] = $this->shardsFor(1);

        DB::connection($right)->table('holders')->insert(['id' => 1, 'is_replica' => false]);

        $this->artisan('shards:distribute', ['model' => [Holder::class]])
            ->expectsOutputToContain('Moved 0')
            ->assertSuccessful();

        $this->assertSame(1, DB::connection($right)->table('holders')->count());
    }

    /**
     * A model that is not shardable is refused rather than half-processed.
     *
     * @return void
     */
    public function testANonShardableModelIsRefused(): void
    {
        $this->artisan('shards:distribute', ['model' => [PlainRow::class]])->assertFailed();
    }

    /**
     * A replica already on the target is promoted, not collided with.
     *
     * With replication on — and one replica is the default — the copy of a row
     * frequently sits on exactly the connection its key names, which on two
     * shards is every time. An unconditional insert violates the primary key
     * and takes the whole repair down with it. Found in review.
     *
     * @return void
     */
    public function testAReplicaOnTheTargetIsPromoted(): void
    {
        [$right, $wrong] = $this->shardsFor(1);

        DB::connection($wrong)->table('holders')->insert(['id' => 1, 'is_replica' => false]);
        DB::connection($right)->table('holders')->insert(['id' => 1, 'is_replica' => true]);

        $this->artisan('shards:distribute', ['model' => [Holder::class]])->assertSuccessful();

        $this->assertSame(0, DB::connection($wrong)->table('holders')->count(), 'the misplaced primary stayed');

        $promoted = DB::connection($right)->table('holders')->where('id', 1)->first();

        $this->assertNotNull($promoted);
        $this->assertEmpty($promoted->is_replica, 'the copy was left as a replica');
    }

    /**
     * A run interrupted between the write and the delete is recoverable.
     *
     * The documented recovery is to run the command again, and that only works
     * if finding the row already on the target is a state rather than an
     * error.
     *
     * @return void
     */
    public function testARunInterruptedAfterTheWriteIsRecoverable(): void
    {
        [$right, $wrong] = $this->shardsFor(1);

        // exactly what an interruption leaves behind: the row on both
        DB::connection($wrong)->table('holders')->insert(['id' => 1, 'is_replica' => false]);
        DB::connection($right)->table('holders')->insert(['id' => 1, 'is_replica' => false]);

        $this->artisan('shards:distribute', ['model' => [Holder::class]])->assertSuccessful();

        $this->assertSame(0, DB::connection($wrong)->table('holders')->count());
        $this->assertSame(1, DB::connection($right)->table('holders')->count());
    }

    /**
     * A model whose primary key is not `id` is paged and deleted by its own.
     *
     * @return void
     */
    public function testAModelWithItsOwnPrimaryKeyIsSwept(): void
    {
        foreach (['shard_1', 'shard_2'] as $connection) {
            Schema::connection($connection)->create('oddities', function (Blueprint $table): void {
                $table->unsignedBigInteger('oddity_key')->primary();
                $table->unsignedBigInteger('holder_id');
                $table->boolean('is_replica')->default(false);
            });
        }

        config(['sharding.tables.oddities' => ['strategy' => 'hash', 'replica_count' => 0, 'group' => 'holder_data']]);
        config(['sharding.groups.holder_data' => ['holders', 'holder_notes', 'oddities']]);
        app()->singleton(ShardingManager::class, fn () => new ShardingManager(config('sharding')));

        [$right, $wrong] = $this->shardsFor(1, Oddity::class);

        DB::connection($wrong)->table('oddities')->insert([
            'oddity_key' => 500,
            'holder_id' => 1,
            'is_replica' => false,
        ]);

        $this->artisan('shards:distribute', ['model' => [Oddity::class]])->assertSuccessful();

        $this->assertSame(1, DB::connection($right)->table('oddities')->count(), 'it did not arrive');
        $this->assertSame(0, DB::connection($wrong)->table('oddities')->count(), 'it stayed behind');
    }

    /**
     * The shard a key names, and one it does not.
     *
     * @param int $key The shard key value.
     * @param class-string<Model> $model The model whose group decides.
     *
     * @return array{0: string, 1: string}
     */
    protected function shardsFor(int $key, string $model = Holder::class): array
    {
        $right = app(ShardingManager::class)->connectionFor(new $model(), $key)[0];

        return [$right, $right === 'shard_1' ? 'shard_2' : 'shard_1'];
    }
}

class Holder extends Model
{
    use Shardable;

    protected $table = 'holders';

    public $incrementing = false;

    public $timestamps = false;

    protected $guarded = [];

    protected $casts = ['is_replica' => 'bool'];
}

class HolderNote extends Model
{
    use Shardable;

    protected $table = 'holder_notes';

    protected string $shardKey = 'holder_id';

    public $incrementing = false;

    public $timestamps = false;

    protected $guarded = [];

    protected $casts = ['is_replica' => 'bool'];
}

class PlainRow extends Model
{
    protected $table = 'holders';

    public $timestamps = false;
}

class Oddity extends Model
{
    use Shardable;

    protected $table = 'oddities';

    protected $primaryKey = 'oddity_key';

    protected string $shardKey = 'holder_id';

    public $incrementing = false;

    public $timestamps = false;

    protected $guarded = [];

    protected $casts = ['is_replica' => 'bool'];
}
