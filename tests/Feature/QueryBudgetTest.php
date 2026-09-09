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
 * What a query costs, in round trips, under the strategy an application uses.
 *
 * Sharding is a promise about load: a read that names its key touches the one
 * connection that holds it and nothing else. That promise is easy to keep in
 * the shape of the SQL and easy to break in the trips around it — a routing
 * lookup on the metadata connection before every pinned read, a slot written
 * back after every insert, a key generated and a slot resolved just to build a
 * query builder. None of those show in the SQL a developer looks at, and each
 * of them is a round trip the sharding was supposed to save.
 *
 * So the budget is asserted, per connection, on the warm path — the path the
 * second request takes, once the routing for a key has been seen once.
 */
class QueryBudgetTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config([
            'database.connections.shard_a' => ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => ''],
            'database.connections.shard_b' => ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => ''],
            'sharding.connections' => ['shard_a' => ['weight' => 1], 'shard_b' => ['weight' => 1]],
            'sharding.pin_by_key' => true,
            'sharding.tables' => [
                'budget_items' => [
                    'strategy' => 'db_hash_range',
                    'meta_connection' => 'sqlite',
                    'slot_size' => 1000,
                    'replica_count' => 0,
                    'group' => 'budget',
                ],
                'budget_parts' => ['group' => 'budget'],
            ],
            'sharding.groups' => ['budget' => ['budget_items', 'budget_parts']],
        ]);

        app()->singleton(ShardingManager::class, fn () => new ShardingManager(config('sharding')));

        Schema::connection('sqlite')->create('shard_slots', function (Blueprint $table): void {
            $table->id();
            $table->string('table');
            $table->unsignedBigInteger('slot');
            $table->string('connection');
            $table->json('replicas')->nullable();
            $table->timestamps();
            $table->unique(['table', 'slot']);
        });

        foreach (['shard_a', 'shard_b'] as $connection) {
            Schema::connection($connection)->create('budget_items', function (Blueprint $table): void {
                $table->unsignedBigInteger('id')->primary();
                $table->unsignedBigInteger('tenant_id');
                $table->string('name');
                $table->boolean('is_replica')->default(false);
            });

            Schema::connection($connection)->create('budget_parts', function (Blueprint $table): void {
                $table->unsignedBigInteger('id')->primary();
                $table->unsignedBigInteger('tenant_id');
                $table->unsignedBigInteger('item_id');
                $table->string('label');
                $table->boolean('is_replica')->default(false);
            });
        }
    }

    /**
     * Building a query builder is not a database operation.
     *
     * Eloquent asks the model for its connection to pick a grammar, and the
     * trait answered a keyless model by generating a key and resolving the
     * shard for it — a metadata round trip per `Model::query()`, for a
     * connection the builder then decides for itself.
     *
     * @return void
     */
    public function testBuildingAQueryTouchesNoDatabase(): void
    {
        $this->warmUp(5);

        $cost = $this->cost(fn () => BudgetItem::query()->where('tenant_id', 5));

        $this->assertSame(0, $cost['sqlite'], "building a builder cost {$cost['sqlite']} metadata queries");
        $this->assertSame(0, $cost['shard_a'] + $cost['shard_b'], 'building a builder queried a shard');
    }

    /**
     * A read that names its key is one shard query and nothing else.
     *
     * @return void
     */
    public function testAKeyedReadIsOneShardQueryAndNoMetadataQueries(): void
    {
        $this->warmUp(5);

        $cost = $this->cost(fn () => BudgetItem::query()->where('tenant_id', 5)->get());

        $this->assertSame(1, $cost['shard_a'] + $cost['shard_b'], "a keyed read touched {$cost['shard_a']} + {$cost['shard_b']} shard queries");
        $this->assertSame(0, $cost['sqlite'], "a keyed read cost {$cost['sqlite']} metadata queries");
    }

    /**
     * An insert for a key whose slot is known is one shard write and nothing else.
     *
     * The `created` hook records the slot after every insert, in a transaction
     * with a locked read — three metadata round trips to write down what the
     * metadata already says.
     *
     * @return void
     */
    public function testAnInsertForAKnownKeyIsOneShardWriteAndNoMetadataQueries(): void
    {
        $this->warmUp(5);

        $cost = $this->cost(fn () => BudgetItem::create(['tenant_id' => 5, 'name' => 'second']));

        $this->assertSame(1, $cost['shard_a'] + $cost['shard_b'], "an insert touched {$cost['shard_a']} + {$cost['shard_b']} shard queries");
        $this->assertSame(0, $cost['sqlite'], "an insert for a known key cost {$cost['sqlite']} metadata queries");
    }

    /**
     * A first-or-create that names its key reads one shard, not every shard.
     *
     * @return void
     */
    public function testFirstOrCreateWithTheKeyReadsOneShard(): void
    {
        $this->warmUp(5);

        $cost = $this->cost(fn () => BudgetItem::query()->firstOrCreate(['tenant_id' => 5, 'name' => 'first']));

        $this->assertSame(1, $cost['shard_a'] + $cost['shard_b'], "firstOrCreate touched {$cost['shard_a']} + {$cost['shard_b']} shard queries");
        $this->assertSame(0, $cost['sqlite']);
    }

    /**
     * Eager-loading a colocated relation reads the shard the parents came from.
     *
     * `with('parts')` runs one relation query per batch of parents, and a batch
     * is what one shard returned. The children of a colocated table are on
     * that same shard by construction — that is what colocation is — but the
     * relation query is a fresh builder, and unless its `whereIn` happens to
     * be on the shard key it fanned out over every shard. N shards, N batches,
     * N queries each: a page that should cost one query per table cost N² for
     * the relation alone.
     *
     * @return void
     */
    public function testEagerLoadingAColocatedRelationStaysOnTheParentsShard(): void
    {
        $this->warmUp(5);

        $item = BudgetItem::query()->where('tenant_id', 5)->firstOrFail();
        BudgetPart::create(['tenant_id' => 5, 'item_id' => $item->id, 'label' => 'a']);
        BudgetPart::create(['tenant_id' => 5, 'item_id' => $item->id, 'label' => 'b']);

        $cost = $this->cost(fn () => BudgetItem::query()->where('tenant_id', 5)->with('parts')->get());

        $this->assertSame(2, $cost['shard_a'] + $cost['shard_b'], "one parent query and one child query expected, got {$cost['shard_a']} + {$cost['shard_b']}");
        $this->assertSame(0, $cost['sqlite']);
    }

    /**
     * The same for a relation eager-loaded on a fanned-out read.
     *
     * Without a key the parents come from every shard, one batch each, and each
     * batch's children are on its own shard: N queries for the relation, not
     * N times N.
     *
     * @return void
     */
    public function testEagerLoadingOnAFanOutCostsOneChildQueryPerShard(): void
    {
        $this->warmUp(5);
        $this->warmUp(6);

        foreach (BudgetItem::query()->get() as $item) {
            BudgetPart::create(['tenant_id' => $item->tenant_id, 'item_id' => $item->id, 'label' => 'a']);
        }

        $cost = $this->cost(fn () => BudgetItem::query()->with('parts')->get());

        $this->assertSame(4, $cost['shard_a'] + $cost['shard_b'], "two parent queries and two child queries expected, got {$cost['shard_a']} + {$cost['shard_b']}");
    }

    /**
     * A relation loaded onto a collection from several shards finds every child.
     *
     * The eager pin is only sound when every parent in the batch came from the
     * same connection. A collection assembled by a fan-out and then given
     * `->load()` is one batch with several homes; pinning it to the first
     * parent's shard would drop every other parent's children silently, so it
     * must fan out instead. This is the correctness half of the eager budget.
     *
     * @return void
     */
    public function testLoadingARelationOntoAMixedCollectionFindsEveryChild(): void
    {
        $this->warmUp(5);
        $this->warmUp(6);

        $items = BudgetItem::query()->get();

        $this->assertSame(
            2,
            $items->map(fn (BudgetItem $item): string => (string) $item->getConnectionName())->unique()->count(),
            'the two tenants landed on one shard, so this fixture proves nothing',
        );

        foreach ($items as $item) {
            BudgetPart::create(['tenant_id' => $item->tenant_id, 'item_id' => $item->id, 'label' => 'a']);
        }

        $items->load('parts');

        foreach ($items as $item) {
            $this->assertCount(1, $item->parts, "tenant {$item->tenant_id} lost its part to a pin on another shard");
        }
    }

    /**
     * A key the routing has never seen still resolves, and is then remembered.
     *
     * The cold path may pay the lookup; the point is that it pays it once.
     *
     * @return void
     */
    public function testAColdKeyIsResolvedOnceAndThenRemembered(): void
    {
        $first = $this->cost(fn () => BudgetItem::query()->where('tenant_id', 77)->get());
        $second = $this->cost(fn () => BudgetItem::query()->where('tenant_id', 77)->get());

        $this->assertSame(1, $first['shard_a'] + $first['shard_b'], 'a cold keyed read fanned out');
        $this->assertSame(0, $second['sqlite'], "the second read of a key still cost {$second['sqlite']} metadata queries");
    }

    /**
     * Put one row for the tenant in place, so its slot is recorded and seen.
     *
     * @param int $tenant
     * @return void
     */
    protected function warmUp(int $tenant): void
    {
        BudgetItem::create(['tenant_id' => $tenant, 'name' => 'first']);
        BudgetItem::query()->where('tenant_id', $tenant)->get();
    }

    /**
     * How many statements a closure runs on each connection.
     *
     * @param callable(): mixed $work
     * @return array{sqlite: int, shard_a: int, shard_b: int}
     */
    protected function cost(callable $work): array
    {
        $names = ['sqlite', 'shard_a', 'shard_b'];

        foreach ($names as $name) {
            DB::connection($name)->flushQueryLog();
            DB::connection($name)->enableQueryLog();
        }

        $work();

        $cost = [];

        foreach ($names as $name) {
            $cost[$name] = count(DB::connection($name)->getQueryLog());
            DB::connection($name)->disableQueryLog();
        }

        return $cost;
    }
}

/**
 * A row sharded by its tenant, under the hash-slot strategy.
 */
class BudgetItem extends Model
{
    use Shardable;

    protected $table = 'budget_items';

    protected string $shardKey = 'tenant_id';

    public $incrementing = false;

    public $timestamps = false;

    protected $guarded = [];

    /**
     * @return \Illuminate\Database\Eloquent\Relations\HasMany<BudgetPart, $this>
     */
    public function parts()
    {
        return $this->hasMany(BudgetPart::class, 'item_id');
    }
}

/**
 * A part of an item, colocated with it by tenant.
 */
class BudgetPart extends Model
{
    use Shardable;

    protected $table = 'budget_parts';

    protected string $shardKey = 'tenant_id';

    public $incrementing = false;

    public $timestamps = false;

    protected $guarded = [];
}
