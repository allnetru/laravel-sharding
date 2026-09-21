<?php

namespace Allnetru\Sharding\Tests\Unit;

use Allnetru\Sharding\Exceptions\UnsupportedCrossShardQuery;
use Allnetru\Sharding\Models\Concerns\Shardable;
use Allnetru\Sharding\ShardingManager;
use Allnetru\Sharding\Tests\TestCase;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * A raw aggregate read with first() keeps its shard.
 *
 * `first()` is a limit, and a limit had the primary key added to it so the
 * merge across shards could compare rows. On an aggregate there is nothing to
 * order — Postgres refuses `order by id` on a query with no `group by` — and
 * the application worked around it with `toBase()`, which drops the routing
 * entirely: a tenant on the second shard was counted on the first and
 * answered zero over hundreds of rows.
 */
class ShardRawAggregateTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config([
            'database.connections.shard_1' => ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => ''],
            'database.connections.shard_2' => ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => ''],
            'sharding.connections' => ['shard_1' => ['weight' => 1], 'shard_2' => ['weight' => 1]],
        ]);

        app()->singleton(ShardingManager::class, fn () => new ShardingManager(config('sharding')));

        foreach (['shard_1', 'shard_2'] as $connection) {
            Schema::connection($connection)->create('orders', function (Blueprint $table): void {
                $table->unsignedBigInteger('id')->primary();
                $table->unsignedBigInteger('tenant_id');
                $table->integer('value');
                $table->boolean('is_replica')->default(false);
            });
        }
    }

    public function testAnAggregateIsCountedOnTheKeysOwnShard(): void
    {
        [$mine, $other] = $this->shardsOf(7);

        DB::connection($mine)->table('orders')->insert([
            ['id' => 1, 'tenant_id' => 7, 'value' => 10],
            ['id' => 2, 'tenant_id' => 7, 'value' => 32],
        ]);

        // the other shard holds nothing of this tenant, and answering from it
        // is exactly the failure: zero over rows that are plainly there
        DB::connection($other)->table('orders')->insert(['id' => 3, 'tenant_id' => 8, 'value' => 99]);

        $sql = [];
        DB::connection($mine)->listen(static function ($query) use (&$sql): void {
            $sql[] = $query->sql;
        });

        $row = RawAggregatedOrder::query()
            ->where('tenant_id', 7)
            ->selectRaw('count(*) as total, sum(value) as summed')
            ->first();

        $this->assertSame(2, (int) $row->getAttribute('total'));
        $this->assertSame(42, (int) $row->getAttribute('summed'));

        // no ordering was added: Postgres refuses one here, and the workaround
        // that used to be needed for it dropped the routing
        $this->assertNotEmpty($sql);
        $this->assertStringNotContainsString('order by', strtolower(implode(' ', $sql)));
    }

    public function testAGroupedQueryKeepsItsOrdering(): void
    {
        [$mine] = $this->shardsOf(7);

        DB::connection($mine)->table('orders')->insert([
            ['id' => 1, 'tenant_id' => 7, 'value' => 10],
            ['id' => 2, 'tenant_id' => 7, 'value' => 10],
        ]);

        // grouped, so there is a column to order by and the bound still applies
        $rows = RawAggregatedOrder::query()
            ->where('tenant_id', 7)
            ->selectRaw('value, count(*) as total')
            ->groupBy('value')
            ->get();

        $this->assertSame(1, $rows->count());
        $this->assertSame(2, (int) $rows->first()->getAttribute('total'));
    }

    public function testAnUnpinnedAggregateIsNotAnsweredFromOneShard(): void
    {
        DB::connection('shard_1')->table('orders')->insert(['id' => 1, 'tenant_id' => 7, 'value' => 10]);
        DB::connection('shard_2')->table('orders')->insert(['id' => 2, 'tenant_id' => 8, 'value' => 32]);

        /*
        | No shard key, so every shard answers its own aggregate and nothing
        | here says how to add them up. Ordering does not save it — SQLite
        | accepts `order by id` over a query with no `group by` and one
        | shard's row would come back as the answer for all of them — so the
        | query is refused outright.
        */
        $this->expectException(UnsupportedCrossShardQuery::class);

        RawAggregatedOrder::query()
            ->selectRaw('sum(value) as summed')
            ->first();
    }

    public function testAWindowIsNotAnAggregateThatCollapses(): void
    {
        $builder = RawAggregatedOrder::query()->selectRaw('sum(value) over (order by id) as running');

        $collapses = new \ReflectionMethod($builder, 'collapsesToOneRow');
        $collapses->setAccessible(true);

        // a window answers one row per input row, and the leading `sum(` would
        // otherwise read it as an aggregate that collapses
        $this->assertFalse($collapses->invoke($builder, 'sum(value) over (order by id) as running'));
        $this->assertFalse($collapses->invoke($builder, 'st_astext(geom) as outline'));
        $this->assertTrue($collapses->invoke($builder, 'count(*) as total'));
        $this->assertTrue($collapses->invoke($builder, 'st_asewkt(st_collect(geom)) as hull'));

        // set-returning, so a wrapper around an aggregate is not one either
        $this->assertFalse($collapses->invoke($builder, 'st_dump(st_collect(geom)) as part'));
        $this->assertFalse($collapses->invoke($builder, 'st_dumppoints(st_union(geom)) as point'));

        // min and max are aggregates over one argument and scalars over two
        $this->assertTrue($collapses->invoke($builder, 'max(value) as highest'));
        $this->assertFalse($collapses->invoke($builder, 'max(value, 10) as adjusted'));
        $this->assertFalse($collapses->invoke($builder, 'min(value, other) as smaller'));

        // the aggregate at whatever depth the scalars around it put it: this
        // is the centre of an upload, and one level of wrapping missed it
        $this->assertTrue($collapses->invoke(
            $builder,
            "st_x(st_centroid(st_collect(coalesce(st_geomfromewkt(after->>'geom'), st_geomfromewkt(before->>'geom'))))) as lon",
        ));
        $this->assertFalse($collapses->invoke($builder, 'st_x(st_centroid(geom)) as lon'));
        $this->assertFalse($collapses->invoke($builder, 'st_dump(st_centroid(st_collect(geom))) as part'));

        // st_union and st_collect are scalars over two geometries, the way
        // min and max are over two numbers
        $this->assertFalse($collapses->invoke($builder, 'st_astext(st_centroid(st_union(geom, other))) as t'));
        $this->assertTrue($collapses->invoke($builder, 'st_astext(st_centroid(st_union(geom))) as t'));
    }

    public function testEveryBoundedReadRefusesTheSameQuery(): void
    {
        DB::connection('shard_1')->table('orders')->insert(['id' => 1, 'tenant_id' => 7, 'value' => 10]);
        DB::connection('shard_2')->table('orders')->insert(['id' => 2, 'tenant_id' => 8, 'value' => 32]);

        // paginate() and cursor() are bounded reads like get(): answering an
        // unpinned aggregate from whichever shard came first presents one
        // shard's sum as the total
        foreach (['get', 'first', 'cursor', 'paginate'] as $method) {
            $refused = false;

            try {
                $answer = RawAggregatedOrder::query()
                    ->selectRaw('sum(value) as summed')
                    ->{$method}();

                // cursor() is lazy, so the refusal waits for the first read
                if ($answer instanceof \Traversable) {
                    iterator_to_array($answer);
                }
            } catch (UnsupportedCrossShardQuery) {
                $refused = true;
            }

            $this->assertTrue($refused, sprintf('%s() answered an unpinned raw aggregate', $method));
        }
    }

    public function testTheRebalanceSwitchDoesNotChangeWhatAQueryMayBe(): void
    {
        [$mine] = $this->shardsOf(7);

        DB::connection($mine)->table('orders')->insert(['id' => 1, 'tenant_id' => 7, 'value' => 42]);

        /*
        | `pin_by_key` is turned off while a rebalance moves rows, and it
        | promises to change how many connections are asked and never what a
        | query returns. Reading it here turned a pinned aggregate into a
        | refusal for the duration of every rebalance.
        */
        config(['sharding.pin_by_key' => false]);

        $row = RawAggregatedOrder::query()
            ->where('tenant_id', 7)
            ->selectRaw('sum(value) as summed')
            ->first();

        $this->assertSame(42, (int) $row->getAttribute('summed'));
    }

    /**
     * @return array{0: string, 1: string}
     */
    protected function shardsOf(int $key): array
    {
        $mine = app(ShardingManager::class)->connectionFor(new RawAggregatedOrder(), $key)[0];

        return [$mine, $mine === 'shard_1' ? 'shard_2' : 'shard_1'];
    }
}

class RawAggregatedOrder extends Model
{
    use Shardable;

    protected $table = 'orders';

    protected $guarded = [];

    public $timestamps = false;

    protected string $shardKey = 'tenant_id';
}
