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
 * A derived table survives the copy made for each shard.
 *
 * `setModel()` writes the model's table into `from` unconditionally, so the
 * per-shard copy threw away a `fromRaw()` — a CTE, a VALUES list, a derived
 * table — while keeping the bindings that came with it. What ran was the
 * plain table with more placeholders than values, and Postgres answered
 * «Invalid parameter number», naming neither the clause nor the call.
 */
class ShardFromRawTest extends TestCase
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

            Schema::connection($connection)->create('order_items', function (Blueprint $table): void {
                $table->unsignedBigInteger('id')->primary();
                $table->unsignedBigInteger('tenant_id');
                $table->unsignedBigInteger('order_id');
                $table->boolean('is_replica')->default(false);
            });
        }
    }

    public function testADerivedTableIsKeptOnThePinnedShard(): void
    {
        $shard = app(ShardingManager::class)->connectionFor(new DerivedOrder(), 7)[0];
        DB::connection($shard)->table('orders')->insert(['id' => 1, 'tenant_id' => 7, 'value' => 42]);

        // the derived table carries the key's columns through, so the
        // builder's own where and the replica filter still apply to it
        $rows = DerivedOrder::query()
            ->where('tenant_id', 7)
            ->fromRaw(
                '(select tenant_id, is_replica, value as measured from orders where tenant_id = ?) as orders',
                [7],
            )
            ->selectRaw('orders.measured')
            ->get();

        $this->assertSame([42], $rows->pluck('measured')->map(intval(...))->all());
    }

    public function testADerivedTableIsKeptOnEveryShardOfAFanOut(): void
    {
        DB::connection('shard_1')->table('orders')->insert(['id' => 1, 'tenant_id' => 7, 'value' => 1]);
        DB::connection('shard_2')->table('orders')->insert(['id' => 2, 'tenant_id' => 8, 'value' => 2]);

        $rows = DerivedOrder::query()
            ->fromRaw('(select tenant_id, is_replica, value + ? as measured from orders) as orders', [10])
            ->selectRaw('orders.measured')
            ->get();

        $this->assertSame([11, 12], $rows->pluck('measured')->map(intval(...))->sort()->values()->all());
    }

    public function testTheReplicaFilterTakesTheDerivedTablesAlias(): void
    {
        $shard = app(ShardingManager::class)->connectionFor(new DerivedOrder(), 7)[0];
        DB::connection($shard)->table('orders')->insert(['id' => 1, 'tenant_id' => 7, 'value' => 42]);
        DB::connection($shard)->table('order_items')->insert(['id' => 1, 'tenant_id' => 7, 'order_id' => 1]);

        // both sides carry is_replica, so a bare name would be ambiguous
        $rows = DerivedOrder::query()
            ->where('orders.tenant_id', 7)
            ->fromRaw('(select id, tenant_id, is_replica, value from orders where tenant_id = ?) as orders', [7])
            ->join('order_items', 'order_items.order_id', '=', 'orders.id')
            ->selectRaw('orders.value as measured')
            ->get();

        $this->assertSame([42], $rows->pluck('measured')->map(intval(...))->all());
    }

    public function testALateralJoinDoesNotStealTheAlias(): void
    {
        $shard = app(ShardingManager::class)->connectionFor(new DerivedOrder(), 7)[0];
        DB::connection($shard)->table('orders')->insert(['id' => 1, 'tenant_id' => 7, 'value' => 42]);

        // two sources, and only the first carries the model's columns: the
        // replica filter must not name the lateral one
        $rows = DerivedOrder::query()
            ->where('orders.tenant_id', 7)
            ->fromRaw(
                '(select id, tenant_id, is_replica, value from orders where tenant_id = ?) as orders,'
                . ' (select 1 as one) as d',
                [7],
            )
            ->selectRaw('orders.value as measured')
            ->get();

        $this->assertSame([42], $rows->pluck('measured')->map(intval(...))->all());
    }
}

class DerivedOrder extends Model
{
    use Shardable;

    protected $table = 'orders';

    protected $guarded = [];

    public $timestamps = false;

    protected string $shardKey = 'tenant_id';
}
