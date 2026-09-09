<?php

namespace Allnetru\Sharding\Tests\Unit\Support;

use Allnetru\Sharding\Exceptions\UnsupportedCrossShardQuery;
use Allnetru\Sharding\Models\Concerns\Shardable;
use Allnetru\Sharding\ShardingManager;
use Allnetru\Sharding\Support\Colocation;
use Allnetru\Sharding\Tests\TestCase;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Which relations may be answered on the parent's shard.
 *
 * The answer drives two things that lose rows when it is wrong: the eager load
 * pinned to the batch's connection, and the correlated subquery `whereHas`
 * compiles to. So every shape is listed here, the ones that hold and the ones
 * that do not, and the one that used to be misjudged is exercised end to end.
 */
class ColocationTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config([
            'database.connections.shard_1' => ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => ''],
            'database.connections.shard_2' => ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => ''],
            'sharding.connections' => ['shard_1' => ['weight' => 1], 'shard_2' => ['weight' => 1]],
            'sharding.default' => 'hash',
            'sharding.tables' => [],
            'sharding.groups' => [
                'user_data' => ['co_users', 'co_grants'],
                'tenant_data' => ['co_notes', 'co_parts'],
            ],
        ]);

        app()->singleton(ShardingManager::class, fn () => new ShardingManager(config('sharding')));

        foreach (['shard_1', 'shard_2'] as $connection) {
            Schema::connection($connection)->create('co_users', function (Blueprint $table): void {
                $table->unsignedBigInteger('id')->primary();
                $table->unsignedBigInteger('invited_by')->nullable();
                $table->boolean('is_replica')->default(false);
            });
        }
    }

    /**
     * @return Colocation
     */
    protected function colocation(): Colocation
    {
        return new Colocation(app(ShardingManager::class));
    }

    public function testAGroupSharingAColumnBothRowsCarryHolds(): void
    {
        $this->assertTrue($this->colocation()->holds((new CoNote())->parts()));
    }

    public function testAChildShardedByTheColumnThatPointsAtItsParentHolds(): void
    {
        $this->assertTrue($this->colocation()->holds((new CoUser())->grants()));
        $this->assertTrue($this->colocation()->holds((new CoGrant())->user()));
    }

    /**
     * Both sides shard by `id`, so the names agree, and the values are two
     * different rows' identities. The shared-column shape used to accept the
     * names alone, pin the eager load to the parent's shard, and lose every
     * inviter on the other one. Found in review.
     */
    public function testATableShardedByItsOwnKeyRelatingToItselfDoesNotHold(): void
    {
        $this->assertFalse($this->colocation()->holds((new CoUser())->inviter()));
        $this->assertFalse($this->colocation()->holds((new CoUser())->invitees()));
    }

    public function testATableRelatingToItselfByAColumnBothRowsCarryHolds(): void
    {
        $this->assertTrue($this->colocation()->holds((new CoNote())->children()));
        // ungrouped, and colocated with itself all the same
        $this->assertTrue($this->colocation()->holds((new CoTag())->children()));
    }

    /**
     * Outside a group two tables configured alike are still two scopes to a
     * strategy that records its routing, so the same key may sit on different
     * shards. The comparison that was meant to decide this compared the
     * strategy configurations, which differ by table name and so said no every
     * time, including for a table relating to itself. Found in review.
     */
    public function testTwoUngroupedTablesDoNotHold(): void
    {
        $this->assertFalse($this->colocation()->holds((new CoTag())->labels()));
    }

    public function testARelationAcrossGroupsDoesNotHold(): void
    {
        $this->assertFalse($this->colocation()->holds((new CoUser())->notes()));
    }

    public function testAnEagerLoadOfASelfRelationFindsTheRowOnTheOtherShard(): void
    {
        [$inviter, $invitee] = $this->usersOnBothShards();

        $loaded = CoUser::query()->where('id', $invitee->id)->with('inviter')->get()->first();

        $this->assertNotNull($loaded);
        $this->assertNotNull($loaded->inviter, 'the eager load was pinned to the wrong shard');
        $this->assertSame($inviter->id, $loaded->inviter->id);
    }

    public function testAWhereHasOnASelfRelationIsRefusedRatherThanAnsweredByOneShard(): void
    {
        $this->usersOnBothShards();

        $this->expectException(UnsupportedCrossShardQuery::class);

        CoUser::whereHas('inviter')->get();
    }

    /**
     * Two users whose ids hash to different shards, the second invited by the
     * first.
     *
     * @return array{0: CoUser, 1: CoUser}
     */
    protected function usersOnBothShards(): array
    {
        $manager = app(ShardingManager::class);
        $ids = [];

        for ($id = 1; $id < 400 && count($ids) < 2; $id++) {
            $ids[$manager->connectionFor(new CoUser(), $id)[0]] ??= $id;
        }

        $this->assertCount(2, $ids, 'the fixture needs a user on each shard');
        [$first, $second] = array_values($ids);

        $inviter = new CoUser();
        $inviter->id = $first;
        $inviter->save();

        $invitee = new CoUser();
        $invitee->id = $second;
        $invitee->invited_by = $first;
        $invitee->save();

        return [$inviter, $invitee];
    }
}

class CoUser extends Model
{
    use Shardable;

    protected $table = 'co_users';
    public $timestamps = false;
    public $incrementing = false;
    protected $guarded = [];

    public function inviter()
    {
        return $this->belongsTo(self::class, 'invited_by');
    }

    public function invitees()
    {
        return $this->hasMany(self::class, 'invited_by');
    }

    public function grants()
    {
        return $this->hasMany(CoGrant::class, 'user_id');
    }

    public function notes()
    {
        return $this->hasMany(CoNote::class, 'user_id');
    }
}

class CoGrant extends Model
{
    use Shardable;

    protected $table = 'co_grants';
    public $timestamps = false;
    public $incrementing = false;
    protected $guarded = [];
    protected string $shardKey = 'user_id';

    public function user()
    {
        return $this->belongsTo(CoUser::class, 'user_id');
    }
}

class CoNote extends Model
{
    use Shardable;

    protected $table = 'co_notes';
    public $timestamps = false;
    public $incrementing = false;
    protected $guarded = [];
    protected string $shardKey = 'tenant_id';

    public function children()
    {
        return $this->hasMany(self::class, 'parent_id');
    }

    public function parts()
    {
        return $this->hasMany(CoPart::class, 'note_id');
    }
}

class CoTag extends Model
{
    use Shardable;

    protected $table = 'co_tags';
    public $timestamps = false;
    public $incrementing = false;
    protected $guarded = [];
    protected string $shardKey = 'tenant_id';

    public function children()
    {
        return $this->hasMany(self::class, 'parent_id');
    }

    public function labels()
    {
        return $this->hasMany(CoLabel::class, 'tag_id');
    }
}

class CoLabel extends Model
{
    use Shardable;

    protected $table = 'co_labels';
    public $timestamps = false;
    public $incrementing = false;
    protected $guarded = [];
    protected string $shardKey = 'tenant_id';
}

class CoPart extends Model
{
    use Shardable;

    protected $table = 'co_parts';
    public $timestamps = false;
    public $incrementing = false;
    protected $guarded = [];
    protected string $shardKey = 'tenant_id';
}
