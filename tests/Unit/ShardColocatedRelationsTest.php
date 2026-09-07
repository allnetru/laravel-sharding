<?php

namespace Allnetru\Sharding\Tests\Unit;

use Allnetru\Sharding\Models\Concerns\Shardable;
use Allnetru\Sharding\ShardingManager;
use Allnetru\Sharding\Tests\TestCase;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Which shard a relation looks on, and how many it looks on.
 *
 * Every relation used to resolve the connection from whichever key joined the
 * two rows. That is the shard key only when a table is sharded by its own
 * primary key; under colocation it is a different column, so the query went
 * to a shard picked by an unrelated number. Reads survived it because
 * ShardBuilder fans out and merges — but the fan-out is the very cost
 * colocation exists to remove, and the methods it does not cover answered
 * from the one wrong shard: count() returned zero over an existing row.
 *
 * The tests below are built on a topology where the two keys deliberately
 * disagree. That matters: ShardHasRelationsTest passes with the bug in place,
 * because the hashes of its two keys happen to land on the same shard.
 */
class ShardColocatedRelationsTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config([
            'database.connections.shard_1' => [
                'driver' => 'sqlite',
                'database' => ':memory:',
                'prefix' => '',
            ],
            'database.connections.shard_2' => [
                'driver' => 'sqlite',
                'database' => ':memory:',
                'prefix' => '',
            ],
            'sharding.connections' => [
                'shard_1' => ['weight' => 1],
                'shard_2' => ['weight' => 1],
            ],
            'sharding.default' => 'hash',
            'sharding.tables' => [],
        ]);

        app()->singleton(ShardingManager::class, fn () => new ShardingManager(config('sharding')));

        foreach (['shard_1', 'shard_2'] as $connection) {
            Schema::connection($connection)->create('co_settlements', function (Blueprint $table): void {
                $table->unsignedBigInteger('id')->primary();
                $table->unsignedBigInteger('tenant_id');
                $table->boolean('is_replica')->default(false);
            });

            Schema::connection($connection)->create('co_parcels', function (Blueprint $table): void {
                $table->unsignedBigInteger('id')->primary();
                $table->unsignedBigInteger('tenant_id');
                $table->unsignedBigInteger('settlement_id');
                $table->boolean('is_replica')->default(false);
            });

            Schema::connection($connection)->create('co_notes', function (Blueprint $table): void {
                $table->unsignedBigInteger('id')->primary();
                $table->unsignedBigInteger('settlement_id');
                $table->boolean('is_replica')->default(false);
            });

            Schema::connection($connection)->create('co_users', function (Blueprint $table): void {
                $table->unsignedBigInteger('id')->primary();
                $table->boolean('is_replica')->default(false);
            });

            Schema::connection($connection)->create('co_profiles', function (Blueprint $table): void {
                $table->unsignedBigInteger('id')->primary();
                $table->unsignedBigInteger('user_id');
                $table->boolean('is_replica')->default(false);
            });
        }
    }

    /**
     * A colocated child is read from one shard, and it is the right one.
     *
     * @return void
     */
    public function testAColocatedHasManyReadsOneShard(): void
    {
        $settlement = $this->settlementWithDisagreeingKeys();

        $parcel = new CoParcel();
        $parcel->tenant_id = $settlement->tenant_id;
        $parcel->settlement_id = $settlement->getKey();
        $parcel->save();

        $fresh = CoSettlement::on($settlement->getConnectionName())
            ->where('id', $settlement->getKey())
            ->first();

        $connections = $this->connectionsUsed(function () use ($fresh, $parcel): void {
            $parcels = $fresh->parcels;

            $this->assertCount(1, $parcels);
            $this->assertSame($parcel->getKey(), $parcels->first()->getKey());
        });

        $this->assertSame(
            [$settlement->getConnectionName()],
            $connections,
            'A colocated relation must read the one shard the rows are on.',
        );
    }

    /**
     * Aggregates over a colocated relation are right.
     *
     * The case that was silently wrong. count() and exists() are not among
     * the methods ShardBuilder fans out, so they went to the single shard the
     * relation had chosen — and it was chosen from the parent's own id.
     *
     * @return void
     */
    public function testAggregatesOverAColocatedRelationAreCorrect(): void
    {
        $settlement = $this->settlementWithDisagreeingKeys();

        foreach ([1, 2] as $ignored) {
            $parcel = new CoParcel();
            $parcel->tenant_id = $settlement->tenant_id;
            $parcel->settlement_id = $settlement->getKey();
            $parcel->save();
        }

        $fresh = CoSettlement::on($settlement->getConnectionName())
            ->where('id', $settlement->getKey())
            ->first();

        $this->assertSame(2, $fresh->parcels()->count());
        $this->assertTrue($fresh->parcels()->exists());
    }

    /**
     * A colocated belongsTo is read from one shard.
     *
     * @return void
     */
    public function testAColocatedBelongsToReadsOneShard(): void
    {
        $settlement = $this->settlementWithDisagreeingKeys();

        $parcel = new CoParcel();
        $parcel->tenant_id = $settlement->tenant_id;
        $parcel->settlement_id = $settlement->getKey();
        $parcel->save();

        $fresh = CoParcel::on($parcel->getConnectionName())
            ->where('id', $parcel->getKey())
            ->first();

        $connections = $this->connectionsUsed(function () use ($fresh, $settlement): void {
            $owner = $fresh->settlement;

            $this->assertNotNull($owner);
            $this->assertSame($settlement->getKey(), $owner->getKey());
        });

        $this->assertSame([$settlement->getConnectionName()], $connections);
    }

    /**
     * A child sharded by the parent's key resolves from the parent's key.
     *
     * The other shape of colocation the README documents, and the one the
     * group examples in the config use: `co_profiles` declares `user_id` as
     * its shard key, so a profile lands on its user's shard. Here the relation
     * does constrain the shard key, and the value it constrains it to is the
     * parent's own key.
     *
     * @return void
     */
    public function testAChildShardedByTheParentsKeyReadsOneShard(): void
    {
        $user = new CoUser();
        $user->save();

        $profile = new CoProfile();
        $profile->user_id = $user->getKey();
        $profile->save();

        $this->assertSame(
            $user->getConnectionName(),
            $profile->getConnectionName(),
            'The colocated child must be written to its parent shard.',
        );

        $fresh = CoUser::on($user->getConnectionName())->where('id', $user->getKey())->first();

        $connections = $this->connectionsUsed(function () use ($fresh, $profile): void {
            $found = $fresh->profile;

            $this->assertNotNull($found);
            $this->assertSame($profile->getKey(), $found->getKey());
        });

        $this->assertSame([$user->getConnectionName()], $connections);
        $this->assertSame(1, $fresh->profiles()->count());
    }

    /**
     * A belongsTo whose owner shards by its own key resolves exactly.
     *
     * The default shape, and the one case the old code got right. Kept as a
     * test so the new rule cannot regress it.
     *
     * @return void
     */
    public function testABelongsToOnAnOwnKeyShardedTableReadsOneShard(): void
    {
        $user = new CoUser();
        $user->save();

        $profile = new CoProfile();
        $profile->user_id = $user->getKey();
        $profile->save();

        $fresh = CoProfile::on($profile->getConnectionName())->where('id', $profile->getKey())->first();

        $connections = $this->connectionsUsed(function () use ($fresh, $user): void {
            $owner = $fresh->user;

            $this->assertNotNull($owner);
            $this->assertSame($user->getKey(), $owner->getKey());
        });

        $this->assertSame([$user->getConnectionName()], $connections);
    }

    /**
     * A child sharded by its own key is still found, by fanning out.
     *
     * Nothing on the parent can say where such a row went, so the honest
     * answer is every shard. Guessing is what this whole change removes, and
     * a test that the guess is gone has to prove the fan-out took its place —
     * otherwise the fix trades wrong aggregates for missing rows.
     *
     * @return void
     */
    public function testAChildShardedByItsOwnKeyStillFansOut(): void
    {
        $settlement = $this->settlementWithDisagreeingKeys();

        $note = new CoNote();
        $note->settlement_id = $settlement->getKey();
        $note->save();

        $fresh = CoSettlement::on($settlement->getConnectionName())
            ->where('id', $settlement->getKey())
            ->first();

        $connections = $this->connectionsUsed(function () use ($fresh, $note): void {
            $notes = $fresh->notes;

            $this->assertCount(1, $notes);
            $this->assertSame($note->getKey(), $notes->first()->getKey());
        });

        $this->assertSame(['shard_1', 'shard_2'], $connections);
    }

    /**
     * A settlement whose tenant shard is not the shard its own id hashes to.
     *
     * The whole point of the fixture. With the two keys agreeing, the old
     * behaviour and the new one are indistinguishable, which is why the
     * existing relation tests passed throughout.
     *
     * @return CoSettlement
     */
    protected function settlementWithDisagreeingKeys(): CoSettlement
    {
        /** @var ShardingManager $manager */
        $manager = app(ShardingManager::class);

        for ($tenant = 1; $tenant < 500; $tenant++) {
            $settlement = new CoSettlement();
            $settlement->tenant_id = $tenant;
            $settlement->save();

            $byOwnKey = $manager->connectionFor(new CoParcel(), $settlement->getKey())[0];

            if ($byOwnKey !== $settlement->getConnectionName()) {
                return $settlement;
            }
        }

        $this->fail('No tenant produced disagreeing shard keys, so the fixture proves nothing.');
    }

    /**
     * Which connections a piece of work queried, sorted and deduplicated.
     *
     * One global listener rather than one per connection: DB::listen is
     * dispatcher-wide, so registering it per connection logs every query once
     * per listener and labels it by the wrong name.
     *
     * @param callable $work
     * @return list<string>
     */
    protected function connectionsUsed(callable $work): array
    {
        $seen = [];

        DB::listen(function ($query) use (&$seen): void {
            $seen[] = $query->connectionName;
        });

        $work();

        $seen = array_values(array_unique($seen));
        sort($seen);

        return $seen;
    }
}

class CoSettlement extends Model
{
    use Shardable;

    public $incrementing = false;
    public $timestamps = false;
    protected $table = 'co_settlements';
    protected string $shardKey = 'tenant_id';
    protected $guarded = [];

    /**
     * @return \Illuminate\Database\Eloquent\Relations\HasMany<CoParcel, $this>
     */
    public function parcels()
    {
        return $this->hasMany(CoParcel::class, 'settlement_id', 'id');
    }

    /**
     * @return \Illuminate\Database\Eloquent\Relations\HasMany<CoNote, $this>
     */
    public function notes()
    {
        return $this->hasMany(CoNote::class, 'settlement_id', 'id');
    }
}

class CoParcel extends Model
{
    use Shardable;

    public $incrementing = false;
    public $timestamps = false;
    protected $table = 'co_parcels';
    protected string $shardKey = 'tenant_id';
    protected $guarded = [];

    /**
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo<CoSettlement, $this>
     */
    public function settlement()
    {
        return $this->belongsTo(CoSettlement::class, 'settlement_id', 'id');
    }
}

class CoNote extends Model
{
    use Shardable;

    public $incrementing = false;
    public $timestamps = false;
    protected $table = 'co_notes';
    protected $guarded = [];
}

class CoUser extends Model
{
    use Shardable;

    public $incrementing = false;
    public $timestamps = false;
    protected $table = 'co_users';
    protected $guarded = [];

    /**
     * @return \Illuminate\Database\Eloquent\Relations\HasOne<CoProfile, $this>
     */
    public function profile()
    {
        return $this->hasOne(CoProfile::class, 'user_id', 'id');
    }

    /**
     * @return \Illuminate\Database\Eloquent\Relations\HasMany<CoProfile, $this>
     */
    public function profiles()
    {
        return $this->hasMany(CoProfile::class, 'user_id', 'id');
    }
}

class CoProfile extends Model
{
    use Shardable;

    public $incrementing = false;
    public $timestamps = false;
    protected $table = 'co_profiles';
    protected string $shardKey = 'user_id';
    protected $guarded = [];

    /**
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo<CoUser, $this>
     */
    public function user()
    {
        return $this->belongsTo(CoUser::class, 'user_id', 'id');
    }
}
