<?php

namespace Allnetru\Sharding\Tests\Unit;

use Allnetru\Sharding\Exceptions\UnsupportedCrossShardQuery;
use Allnetru\Sharding\Models\Concerns\Shardable;
use Allnetru\Sharding\ShardingManager;
use Allnetru\Sharding\Tests\TestCase;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * whereHas and withCount compile into a correlated subquery, and a subquery
 * runs on the connection its outer query runs on.
 *
 * Under colocation that is the right connection and the answer is correct —
 * the case this test exists to protect, because it is the common one and a
 * check written carelessly would start refusing it. Across colocation groups
 * the subquery sees whichever related rows happen to share the shard, which
 * is refused rather than answered.
 */
class ShardRelationSubqueryTest extends TestCase
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
                'tenant_data' => ['rs_owners', 'rs_items', 'rs_parts'],
            ],
        ]);

        app()->singleton(ShardingManager::class, fn () => new ShardingManager(config('sharding')));

        foreach (['shard_1', 'shard_2'] as $connection) {
            Schema::connection($connection)->create('rs_owners', function (Blueprint $table): void {
                $table->unsignedBigInteger('id')->primary();
                $table->unsignedBigInteger('tenant_id');
                $table->boolean('is_replica')->default(false);
            });

            Schema::connection($connection)->create('rs_items', function (Blueprint $table): void {
                $table->unsignedBigInteger('id')->primary();
                $table->unsignedBigInteger('tenant_id');
                $table->unsignedBigInteger('owner_id');
                $table->boolean('is_replica')->default(false);
            });

            Schema::connection($connection)->create('rs_parts', function (Blueprint $table): void {
                $table->unsignedBigInteger('id')->primary();
                $table->unsignedBigInteger('tenant_id');
                $table->unsignedBigInteger('item_id');
                $table->boolean('is_replica')->default(false);
            });

            Schema::connection($connection)->create('rs_loose', function (Blueprint $table): void {
                $table->unsignedBigInteger('id')->primary();
                $table->unsignedBigInteger('owner_id');
                $table->boolean('is_replica')->default(false);
            });
        }
    }

    /**
     * Two tenants whose keys hash to different shards, each with one owner and
     * one colocated item, so no single shard can answer for both.
     *
     * @return list<RsOwner>
     */
    protected function ownersOnBothShards(): array
    {
        $manager = app(ShardingManager::class);
        $tenants = [];

        for ($tenant = 1; $tenant < 400 && count($tenants) < 2; $tenant++) {
            $probe = new RsOwner();
            $probe->tenant_id = $tenant;
            $shard = $manager->connectionFor($probe, $tenant)[0];
            $tenants[$shard] ??= $tenant;
        }

        $this->assertCount(2, $tenants, 'the fixture needs a tenant on each shard');

        $id = 100;
        $owners = [];

        foreach ($tenants as $tenant) {
            $owner = new RsOwner();
            $owner->id = ++$id;
            $owner->tenant_id = $tenant;
            $owner->save();

            $item = new RsItem();
            $item->id = ++$id;
            $item->tenant_id = $tenant;
            $item->owner_id = $owner->id;
            $item->save();

            $owners[] = $owner;
        }

        return $owners;
    }

    public function testAColocatedWhereHasIsAnsweredAndNotRefused(): void
    {
        $this->ownersOnBothShards();

        $this->assertSame(2, RsOwner::whereHas('items')->get()->count());
        $this->assertSame(2, RsOwner::has('items')->count());
        $this->assertSame(0, RsOwner::doesntHave('items')->get()->count());
    }

    public function testAColocatedWithCountIsAnsweredAndNotRefused(): void
    {
        $this->ownersOnBothShards();

        $this->assertSame([1, 1], RsOwner::withCount('items')->orderBy('id')->get()->pluck('items_count')->all());
    }

    public function testAColocatedNestedRelationIsAnsweredAndNotRefused(): void
    {
        $this->ownersOnBothShards();

        // both hops stay inside tenant_data
        $this->assertSame(0, RsOwner::whereHas('items.parts')->get()->count());
    }

    public function testAWhereHasAcrossGroupsIsRefused(): void
    {
        $this->expectException(UnsupportedCrossShardQuery::class);
        $this->expectExceptionMessageMatches('/cannot ask about \'loose\' across shards/');

        RsOwner::whereHas('loose')->get();
    }

    public function testDoesntHaveAcrossGroupsIsRefused(): void
    {
        $this->expectException(UnsupportedCrossShardQuery::class);

        RsOwner::doesntHave('loose')->get();
    }

    public function testWithCountAcrossGroupsIsRefused(): void
    {
        $this->expectException(UnsupportedCrossShardQuery::class);
        $this->expectExceptionMessageMatches('/withcount\(\) cannot ask about/i');

        RsOwner::withCount('loose')->get();
    }

    public function testAnAliasedAggregateIsRecognisedByItsRelationName(): void
    {
        // the name carries "as total", and stripping it is what lets the
        // relation be found at all
        $this->expectException(UnsupportedCrossShardQuery::class);

        RsOwner::withCount('loose as total')->get();
    }

    public function testAnAggregateGivenAsAClosureKeyIsChecked(): void
    {
        $this->expectException(UnsupportedCrossShardQuery::class);

        RsOwner::withCount(['loose' => fn ($query) => $query])->get();
    }

    public function testAPinnedQueryMayAskAnything(): void
    {
        $owners = $this->ownersOnBothShards();
        $connection = $owners[0]->getConnectionName();

        // the shard was named on purpose, so there is one connection and the
        // subquery is as correct as it would be without sharding
        $this->assertSame(
            1,
            RsOwner::query()->onShardConnection($connection)->whereHas('loose')->count()
                + RsOwner::query()->onShardConnection($connection)->doesntHave('loose')->count(),
        );
    }

    public function testAnUnknownRelationStillRaisesLaravelsOwnError(): void
    {
        $this->expectException(\BadMethodCallException::class);

        RsOwner::whereHas('nothingLikeThis')->get();
    }
}

class RsOwner extends Model
{
    use Shardable;

    protected $table = 'rs_owners';
    public $timestamps = false;
    public $incrementing = false;
    protected $guarded = [];
    protected string $shardKey = 'tenant_id';

    public function items()
    {
        return $this->hasMany(RsItem::class, 'owner_id');
    }

    public function loose()
    {
        return $this->hasMany(RsLoose::class, 'owner_id');
    }
}

class RsItem extends Model
{
    use Shardable;

    protected $table = 'rs_items';
    public $timestamps = false;
    public $incrementing = false;
    protected $guarded = [];
    protected string $shardKey = 'tenant_id';

    public function parts()
    {
        return $this->hasMany(RsPart::class, 'item_id');
    }
}

class RsPart extends Model
{
    use Shardable;

    protected $table = 'rs_parts';
    public $timestamps = false;
    public $incrementing = false;
    protected $guarded = [];
    protected string $shardKey = 'tenant_id';
}

class RsLoose extends Model
{
    use Shardable;

    protected $table = 'rs_loose';
    public $timestamps = false;
    public $incrementing = false;
    protected $guarded = [];
}
