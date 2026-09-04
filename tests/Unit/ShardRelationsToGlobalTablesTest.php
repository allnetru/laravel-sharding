<?php

namespace Allnetru\Sharding\Tests\Unit;

use Allnetru\Sharding\IdGenerator;
use Allnetru\Sharding\Models\Concerns\Shardable;
use Allnetru\Sharding\ShardingManager;
use Allnetru\Sharding\Tests\TestCase;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Relations from a sharded model to a global table.
 *
 * Reference data lives on the default connection and is deliberately not
 * sharded, so a sharded row has to be able to reach it. Two things used to
 * break that, and both failed the same confusing way: the query went looking
 * for a table on a shard where it does not exist.
 *
 * The concrete case that exposed this was users sharded by their own id with
 * roles stored in global spatie/laravel-permission tables.
 */
class ShardRelationsToGlobalTablesTest extends TestCase
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
            // only the sharded table is declared. The global one is absent on
            // purpose: that is what tells the package the two are different.
            'sharding.tables' => [
                'accounts' => ['strategy' => 'hash'],
            ],
        ]);

        app()->singleton(ShardingManager::class, fn () => new ShardingManager(config('sharding')));
        app()->singleton(IdGenerator::class, fn () => new class() {
            private int $id = 0;

            public function generate($model): int
            {
                return ++$this->id;
            }
        });

        // the reference table exists only on the default connection, exactly
        // as a global table does in a real application.
        Schema::create('plans', function (Blueprint $table): void {
            $table->unsignedBigInteger('id')->primary();
            $table->string('name');
        });

        Schema::create('badges', function (Blueprint $table): void {
            $table->unsignedBigInteger('id')->primary();
            $table->string('name');
        });

        Schema::create('badge_account', function (Blueprint $table): void {
            $table->unsignedBigInteger('badge_id');
            $table->unsignedBigInteger('account_id');
        });

        foreach (['shard_1', 'shard_2'] as $connection) {
            Schema::connection($connection)->create('accounts', function (Blueprint $table): void {
                $table->unsignedBigInteger('id')->primary();
                $table->unsignedBigInteger('plan_id')->nullable();
                $table->boolean('is_replica')->default(false);
            });
        }
    }

    /**
     * belongsTo to a global table stays on the default connection.
     *
     * @return void
     */
    public function testBelongsToGlobalTableUsesTheDefaultConnection(): void
    {
        $plan = TestPlan::query()->create(['id' => 1, 'name' => 'free']);

        $account = new TestAccount(['plan_id' => $plan->id]);
        $account->save();

        $this->assertNotSame('shard_1', $plan->getConnectionName());
        $this->assertNotNull($account->plan);
        $this->assertSame('free', $account->plan->name);
    }

    /**
     * The relation object is the plain one, not the shard-routing one.
     *
     * ShardBelongsTo resolves a shard for the related model, which is wrong for
     * a table that has no shards at all.
     *
     * @return void
     */
    public function testBelongsToGlobalTableReturnsThePlainRelation(): void
    {
        $account = new TestAccount(['plan_id' => 1]);
        $account->save();

        $relation = $account->plan();

        $this->assertInstanceOf(BelongsTo::class, $relation);
        $this->assertNotInstanceOf(\Allnetru\Sharding\Relations\ShardBelongsTo::class, $relation);
    }

    /**
     * A sharded related model still gets the shard-routing relation.
     *
     * The fix must not make every relation plain.
     *
     * @return void
     */
    public function testBelongsToShardedTableStillResolvesTheShard(): void
    {
        $parent = new TestAccount();
        $parent->save();

        $child = new TestAccount(['parent_id' => $parent->id]);

        $this->assertInstanceOf(
            \Allnetru\Sharding\Relations\ShardBelongsTo::class,
            $child->parent()
        );
    }

    /**
     * belongsToMany through a global pivot works.
     *
     * This is the shape spatie/laravel-permission uses for roles, and the one
     * that made the defect visible.
     *
     * @return void
     */
    public function testBelongsToManyGlobalTableUsesTheDefaultConnection(): void
    {
        $account = new TestAccount();
        $account->save();

        $badge = TestBadge::query()->create(['id' => 7, 'name' => 'resident']);

        $account->badges()->attach($badge->id);

        $this->assertSame(['resident'], $account->badges()->pluck('name')->all());
    }

    /**
     * A global related model does not inherit the shard connection.
     *
     * Eloquent copies the parent connection into a related model that has none
     * of its own, which is what routed these queries to a shard.
     *
     * @return void
     */
    public function testGlobalRelatedModelKeepsTheDefaultConnection(): void
    {
        $account = new TestAccount();
        $account->save();

        $this->assertNotSame('', (string) $account->getConnectionName());
        $this->assertContains($account->getConnectionName(), ['shard_1', 'shard_2']);

        $related = $account->plan()->getRelated();

        $this->assertNotSame($account->getConnectionName(), $related->getConnectionName());
    }

    /**
     * isShardable tells the two kinds of model apart.
     *
     * @return void
     */
    public function testIsShardableDistinguishesShardedFromGlobal(): void
    {
        $manager = app(ShardingManager::class);

        $this->assertTrue($manager->isShardable(new TestAccount()));
        $this->assertFalse($manager->isShardable(new TestPlan()));

        $this->assertTrue($manager->isShardable('accounts'));
        $this->assertFalse($manager->isShardable('plans'));
    }
}

class TestPlan extends Model
{
    protected $table = 'plans';

    public $incrementing = false;

    public $timestamps = false;

    protected $guarded = [];
}

class TestBadge extends Model
{
    protected $table = 'badges';

    public $incrementing = false;

    public $timestamps = false;

    protected $guarded = [];
}

class TestAccount extends Model
{
    use Shardable;

    protected $table = 'accounts';

    public $incrementing = false;

    public $timestamps = false;

    protected $guarded = [];

    protected $casts = [
        'is_replica' => 'bool',
    ];

    public function plan()
    {
        return $this->belongsTo(TestPlan::class, 'plan_id');
    }

    public function parent()
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    public function badges()
    {
        return $this->belongsToMany(TestBadge::class, 'badge_account', 'account_id', 'badge_id');
    }
}
