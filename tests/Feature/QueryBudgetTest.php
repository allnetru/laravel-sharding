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
                ],
            ],
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
}
