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
 * that keeps its models elsewhere. So the command takes the models, one per
 * table: the tables of a group share the value that decides their shard but
 * not the column it is written in, and only the model knows its own.
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
                $table->string('name')->nullable();
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
     * With a replica configured, the source keeps the row as that replica.
     *
     * The source is frequently one of the connections the key's replicas
     * belong on — with one replica and two shards it always is — and these are
     * raw table writes, so no `created` hook rebuilds what a delete removes.
     * Deleting it would leave the metadata advertising a replica that does not
     * exist. Found in review.
     *
     * @return void
     */
    public function testTheSourceBecomesTheReplicaItShouldHaveBeen(): void
    {
        config(['sharding.tables.holders.replica_count' => 1]);
        app()->singleton(ShardingManager::class, fn () => new ShardingManager(config('sharding')));

        $placement = app(ShardingManager::class)->connectionFor(new Holder(), 1);

        $this->assertCount(2, $placement, 'the replica was not configured');

        [$right, $wrong] = [$placement[0], $placement[1]];

        DB::connection($wrong)->table('holders')->insert(['id' => 1, 'is_replica' => false]);

        $this->artisan('shards:distribute', ['model' => [Holder::class]])->assertSuccessful();

        $primary = DB::connection($right)->table('holders')->where('id', 1)->first();
        $replica = DB::connection($wrong)->table('holders')->where('id', 1)->first();

        $this->assertNotNull($primary);
        $this->assertEmpty($primary->is_replica, 'the primary did not arrive as the primary');
        $this->assertNotNull($replica, 'the replica this key needs was deleted');
        $this->assertNotEmpty($replica->is_replica, 'the copy left behind is still claiming to be primary');
    }

    /**
     * A different row that happens to share the identifier is not destroyed.
     *
     * What adopting sharding over databases that counted their own identifiers
     * looks like. Both rows are real data, so the primary key alone does not
     * settle that the row on the target is the row in hand — and a repair tool
     * that silently drops one of the two is worse than one that stops. Found
     * in review.
     *
     * @return void
     */
    public function testADifferentRowSharingTheIdentifierIsKept(): void
    {
        [$right, $wrong] = $this->shardsFor(1);

        DB::connection($wrong)->table('holders')->insert(['id' => 1, 'name' => 'mine', 'is_replica' => false]);
        DB::connection($right)->table('holders')->insert(['id' => 1, 'name' => 'theirs', 'is_replica' => false]);

        $this->artisan('shards:distribute', ['model' => [Holder::class]])->assertFailed();

        $this->assertSame('mine', DB::connection($wrong)->table('holders')->where('id', 1)->value('name'));
        $this->assertSame('theirs', DB::connection($right)->table('holders')->where('id', 1)->value('name'));
    }

    /**
     * A model that cannot be resolved stops the run before anything moves.
     *
     * Validating as the sweeps go means a name misspelled in the second
     * argument is found after the first table has already been rewritten,
     * which for a colocation group leaves half of it agreeing about where a
     * key lives and half of it not — the state this command exists to
     * prevent. Found in review.
     *
     * @return void
     */
    public function testOneUnresolvableModelMovesNothingAtAll(): void
    {
        [, $wrong] = $this->shardsFor(1);

        DB::connection($wrong)->table('holders')->insert(['id' => 1, 'is_replica' => false]);

        $this->artisan('shards:distribute', ['model' => [Holder::class, 'App\\Nonsense\\Missing']])
            ->assertFailed();

        $this->assertSame(
            1,
            DB::connection($wrong)->table('holders')->count(),
            'the first table was swept before the second model was even resolved',
        );
    }

    /**
     * A method by that name is not the trait, and this command is destructive.
     *
     * `method_exists` accepted any model with a domain method called
     * `getShardKey()` and then rewrote where its rows live, using the global
     * fallback configuration. Found in review.
     *
     * @return void
     */
    public function testAModelThatMerelyHasTheMethodIsRefused(): void
    {
        $this->artisan('shards:distribute', ['model' => [Decoy::class]])->assertFailed();
    }

    /**
     * A replica of a different row is not overwritten to make room.
     *
     * The promotion branch used to run before the comparison, so an occupant
     * marked `is_replica` was overwritten whatever row it was a copy of — and
     * the primary it belonged to went on being advertised as having one. What
     * the occupant claims to be does not settle whose row it is. Found in
     * review.
     *
     * @return void
     */
    public function testAReplicaOfADifferentRowIsNotOverwritten(): void
    {
        [$right, $wrong] = $this->shardsFor(1);

        DB::connection($wrong)->table('holders')->insert(['id' => 1, 'name' => 'mine', 'is_replica' => false]);
        DB::connection($right)->table('holders')->insert(['id' => 1, 'name' => 'somebody else', 'is_replica' => true]);

        $this->artisan('shards:distribute', ['model' => [Holder::class]])->assertFailed();

        $this->assertSame('mine', DB::connection($wrong)->table('holders')->where('id', 1)->value('name'));
        $this->assertSame('somebody else', DB::connection($right)->table('holders')->where('id', 1)->value('name'));
    }

    /**
     * The replicas a moved row is supposed to have are written too.
     *
     * With three shards the source is often not one of the key'"'"'s replica
     * connections, so there is nothing left behind to demote and nothing else
     * writes the copy either — the command reported success while the
     * metadata advertised a replica holding nothing. Found in review.
     *
     * @return void
     */
    public function testTheReplicasOfAMovedRowAreWritten(): void
    {
        [$placement, $source] = $this->threeShardsWithAReplica();

        DB::connection($source)->table('holders')->insert(['id' => 1, 'name' => 'mine', 'is_replica' => false]);

        $this->artisan('shards:distribute', ['model' => [Holder::class]])->assertSuccessful();

        $primary = DB::connection($placement[0])->table('holders')->where('id', 1)->first();
        $replica = DB::connection($placement[1])->table('holders')->where('id', 1)->first();

        $this->assertNotNull($primary, 'the primary did not arrive');
        $this->assertEmpty($primary->is_replica);
        $this->assertNotNull($replica, 'the replica this key is advertised as having was never written');
        $this->assertNotEmpty($replica->is_replica, 'the copy arrived claiming to be the row');
        $this->assertNull(
            DB::connection($source)->table('holders')->where('id', 1)->first(),
            'the source kept a copy it is not a connection for',
        );
    }

    /**
     * A collision on a replica connection stops the row from moving at all.
     *
     * The inspection used to happen inside each write, so a clash on the
     * second destination was found with the primary already on the target and
     * the source not yet released — two copies both claiming to be the row,
     * which a fan-out read returns twice, while the command reported that the
     * row had been left alone. Found in review.
     *
     * @return void
     */
    public function testACollisionOnAReplicaLeavesEverythingWhereItWas(): void
    {
        [$placement, $source] = $this->threeShardsWithAReplica();

        DB::connection($source)->table('holders')->insert(['id' => 1, 'name' => 'mine', 'is_replica' => false]);
        /*
        | Marked as a replica so the sweep leaves it alone — it is the replica
        | of some other row that happens to share the identifier, which is
        | exactly the occupant whose claim about itself must not be believed.
        */
        DB::connection($placement[1])->table('holders')->insert([
            'id' => 1,
            'name' => 'somebody else',
            'is_replica' => true,
        ]);

        $this->artisan('shards:distribute', ['model' => [Holder::class]])->assertFailed();

        $this->assertSame(
            'mine',
            DB::connection($source)->table('holders')->where('id', 1)->value('name'),
            'the source was released even though the row never fully arrived',
        );
        $this->assertNull(
            DB::connection($placement[0])->table('holders')->where('id', 1)->first(),
            'the primary was written before the replica destination was inspected',
        );
        $this->assertSame(
            'somebody else',
            DB::connection($placement[1])->table('holders')->where('id', 1)->value('name'),
        );
    }

    /**
     * An active shard without the table stops the run before it starts.
     *
     * Leaving such a connection out of the sources does not stop the routing
     * from naming it as a destination, so the run moved rows from the earlier
     * connections and then failed on a missing table halfway — the partial
     * repair the planning pass exists to prevent. A shard listed in
     * `DB_SHARD_MIGRATIONS` is a different matter: new writes are told to avoid
     * it, so it may legitimately not have the table yet. Found in review.
     *
     * @return void
     */
    public function testAnActiveShardWithoutTheTableIsRefused(): void
    {
        [, $wrong] = $this->shardsFor(1);

        DB::connection($wrong)->table('holders')->insert(['id' => 1, 'is_replica' => false]);
        Schema::connection('shard_2')->drop('holders');

        $this->artisan('shards:distribute', ['model' => [Holder::class]])->assertFailed();

        if ($wrong === 'shard_1') {
            $this->assertSame(
                1,
                DB::connection('shard_1')->table('holders')->count(),
                'rows were swept before the missing table was noticed',
            );
        }
    }

    /**
     * A shard being prepared is skipped rather than refused.
     *
     * @return void
     */
    public function testAShardUnderMigrationWithoutTheTableIsSkipped(): void
    {
        config(['sharding.migrations' => ['shard_2' => true]]);
        app()->singleton(ShardingManager::class, fn () => new ShardingManager(config('sharding')));

        Schema::connection('shard_2')->drop('holders');

        // with shard_2 excluded from routing, every key names shard_1, so the
        // row is already where it belongs and the run has nothing to do
        DB::connection('shard_1')->table('holders')->insert(['id' => 1, 'is_replica' => false]);

        $this->artisan('shards:distribute', ['model' => [Holder::class]])->assertSuccessful();

        $this->assertSame(1, DB::connection('shard_1')->table('holders')->count());
    }

    /**
     * Three shards, one replica, and a connection this key does not name.
     *
     * The interesting topology: the source is neither the primary nor the
     * replica of the key, so there is nothing there to demote and the replica
     * has to be written rather than left behind.
     *
     * @return array{0: list<string>, 1: string} The placement, and the odd connection out.
     */
    protected function threeShardsWithAReplica(): array
    {
        config([
            'database.connections.shard_3' => ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => ''],
            'sharding.connections' => [
                'shard_1' => ['weight' => 1],
                'shard_2' => ['weight' => 1],
                'shard_3' => ['weight' => 1],
            ],
            'sharding.tables.holders.replica_count' => 1,
        ]);

        Schema::connection('shard_3')->create('holders', function (Blueprint $table): void {
            $table->unsignedBigInteger('id')->primary();
            $table->string('name')->nullable();
            $table->boolean('is_replica')->default(false);
        });

        app()->singleton(ShardingManager::class, fn () => new ShardingManager(config('sharding')));

        $placement = array_values(app(ShardingManager::class)->connectionFor(new Holder(), 1));

        $this->assertCount(2, $placement, 'the replica was not configured');

        $source = collect(['shard_1', 'shard_2', 'shard_3'])
            ->reject(fn (string $name): bool => in_array($name, $placement, true))
            ->first();

        $this->assertNotNull($source, 'three shards and a placement of two should leave one over');

        return [$placement, $source];
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

/**
 * Not shardable, and saying otherwise loudly.
 */
class Decoy extends Model
{
    protected $table = 'holders';

    public $incrementing = false;

    public $timestamps = false;

    protected $guarded = [];

    /**
     * A domain method that happens to share the trait's name.
     *
     * @return string
     */
    public function getShardKey(): string
    {
        return 'id';
    }
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
