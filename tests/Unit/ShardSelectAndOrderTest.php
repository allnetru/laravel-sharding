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
 * What the fan-out used to do to the select list and the order.
 */
class ShardSelectAndOrderTest extends TestCase
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
            Schema::connection($connection)->create('samples', function (Blueprint $table): void {
                $table->unsignedBigInteger('id')->primary();
                $table->string('label');
                $table->integer('value');
                $table->boolean('is_replica')->default(false);
            });

            // sqlite compiles a truncate into a delete plus a reset of
            // sqlite_sequence, and that table only exists once something in
            // the database autoincrements
            Schema::connection($connection)->create('sequence_anchors', function (Blueprint $table): void {
                $table->increments('id');
                $table->integer('n');
            });
            DB::connection($connection)->table('sequence_anchors')->insert([['n' => 1], ['n' => 2]]);
        }

        // ids ascend on one shard while values descend, so ordering by value
        // and ordering by id are different answers
        foreach ([[1, 6], [3, 4], [5, 2]] as [$id, $value]) {
            DB::connection('shard_1')->table('samples')->insert([
                'id' => $id, 'label' => "l{$id}", 'value' => $value, 'is_replica' => false,
            ]);
        }

        foreach ([[2, 5], [4, 3], [6, 1]] as [$id, $value]) {
            DB::connection('shard_2')->table('samples')->insert([
                'id' => $id, 'label' => "l{$id}", 'value' => $value, 'is_replica' => false,
            ]);
        }
    }

    public function testSelectRawSurvivesALimit(): void
    {
        // without a limit this always worked; with one the expression was
        // dropped, because the per-shard query was given select(['*'])
        $doubled = Sample::selectRaw('*, value * 2 as doubled')
            ->orderBy('id')
            ->limit(3)
            ->get()
            ->pluck('doubled')
            ->all();

        $this->assertSame([12, 10, 8], $doubled);
    }

    public function testANarrowedSelectSurvivesALimit(): void
    {
        $rows = Sample::select('id', 'label')->orderBy('id')->limit(2)->get();

        $this->assertSame([1, 2], $rows->pluck('id')->all());
        $this->assertSame(['l1', 'l2'], $rows->pluck('label')->all());
    }

    public function testANarrowedSelectStillOrdersByAColumnItOmits(): void
    {
        // value is not selected, and the merge compares models: without the
        // ordering column the comparison reads null on every row and the rows
        // come back in whatever order the shards were visited
        $rows = Sample::select('id', 'label')->orderBy('value')->limit(4)->get();

        $this->assertSame([6, 5, 4, 3], $rows->pluck('id')->all());
    }

    public function testAQualifiedOrderColumnIsCompared(): void
    {
        // orderBy('samples.value') used to compare a property no model has
        $this->assertSame(
            [6, 5, 4, 3, 2, 1],
            Sample::orderBy('samples.value')->limit(6)->get()->pluck('id')->all(),
        );
    }

    public function testARawOrderIsRefusedRatherThanIgnored(): void
    {
        $this->expectException(UnsupportedCrossShardQuery::class);
        $this->expectExceptionMessageMatches('/cannot follow a raw order across shards/');

        Sample::orderByRaw('value desc')->get();
    }

    public function testARawOrderIsRefusedOnACursorToo(): void
    {
        $this->expectException(UnsupportedCrossShardQuery::class);

        Sample::orderByRaw('value desc')->cursor()->all();
    }

    public function testAPinnedQueryMayOrderRaw(): void
    {
        // the shard was named, so nothing is merged and the database sorts
        $ids = Sample::query()
            ->onShardConnection('shard_1')
            ->orderByRaw('value desc')
            ->get()
            ->pluck('id')
            ->all();

        $this->assertSame([1, 3, 5], $ids);
    }

    public function testTruncateEmptiesEveryShard(): void
    {
        Sample::query()->truncate();

        $this->assertSame(0, DB::connection('shard_1')->table('samples')->count());
        $this->assertSame(0, DB::connection('shard_2')->table('samples')->count());
    }

    public function testPaginateStillTotalsEveryShard(): void
    {
        $page = Sample::orderBy('id')->paginate(2, ['*'], 'page', 2);

        $this->assertSame([3, 4], $page->pluck('id')->all());
        $this->assertSame(6, $page->total());
    }
}

class Sample extends Model
{
    use Shardable;

    protected $table = 'samples';
    public $timestamps = false;
    public $incrementing = false;
    protected $guarded = [];
}
