<?php

namespace Allnetru\Sharding\Tests\Unit;

use Allnetru\Sharding\Models\Concerns\Shardable;
use Allnetru\Sharding\ShardingManager;
use Allnetru\Sharding\Tests\TestCase;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class ShardBuilderTest extends TestCase
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
        ]);

        app()->singleton(ShardingManager::class, fn () => new ShardingManager(config('sharding')));

        foreach (['shard_1', 'shard_2'] as $connection) {
            Schema::connection($connection)->create('items', function (Blueprint $table): void {
                $table->unsignedBigInteger('id')->primary();
                $table->integer('value');
                $table->boolean('is_replica')->default(false);
            });

            Schema::connection($connection)->create('parents', function (Blueprint $table): void {
                $table->unsignedBigInteger('id')->primary();
                $table->boolean('is_replica')->default(false);
            });

            Schema::connection($connection)->create('children', function (Blueprint $table): void {
                $table->unsignedBigInteger('id')->primary();
                $table->unsignedBigInteger('parent_id');
                $table->boolean('is_replica')->default(false);
            });
        }
    }

    public function testGetMergesResultsInOrder(): void
    {
        foreach ([1, 3, 5] as $id) {
            DB::connection('shard_1')->table('items')->insert(['id' => $id, 'value' => $id, 'is_replica' => false]);
            DB::connection('shard_2')->table('items')->insert(['id' => $id, 'value' => $id, 'is_replica' => true]);
        }

        foreach ([2, 4, 6] as $id) {
            DB::connection('shard_2')->table('items')->insert(['id' => $id, 'value' => $id, 'is_replica' => false]);
            DB::connection('shard_1')->table('items')->insert(['id' => $id, 'value' => $id, 'is_replica' => true]);
        }

        $values = Item::orderBy('id')->get()->pluck('id')->all();

        $this->assertSame([1, 2, 3, 4, 5, 6], $values);
    }

    public function testLimitAppliesGlobally(): void
    {
        foreach ([1, 3, 5] as $id) {
            DB::connection('shard_1')->table('items')->insert(['id' => $id, 'value' => $id, 'is_replica' => false]);
            DB::connection('shard_2')->table('items')->insert(['id' => $id, 'value' => $id, 'is_replica' => true]);
        }

        foreach ([2, 4, 6] as $id) {
            DB::connection('shard_2')->table('items')->insert(['id' => $id, 'value' => $id, 'is_replica' => false]);
            DB::connection('shard_1')->table('items')->insert(['id' => $id, 'value' => $id, 'is_replica' => true]);
        }

        $values = Item::orderBy('id')->limit(3)->get()->pluck('id')->all();

        $this->assertSame([1, 2, 3], $values);
    }

    public function testOffsetAndLimitApplyGlobally(): void
    {
        foreach ([1, 3, 5] as $id) {
            DB::connection('shard_1')->table('items')->insert(['id' => $id, 'value' => $id, 'is_replica' => false]);
            DB::connection('shard_2')->table('items')->insert(['id' => $id, 'value' => $id, 'is_replica' => true]);
        }

        foreach ([2, 4, 6] as $id) {
            DB::connection('shard_2')->table('items')->insert(['id' => $id, 'value' => $id, 'is_replica' => false]);
            DB::connection('shard_1')->table('items')->insert(['id' => $id, 'value' => $id, 'is_replica' => true]);
        }

        $values = Item::orderBy('id')->offset(2)->limit(3)->get()->pluck('id')->all();

        $this->assertSame([3, 4, 5], $values);
    }

    public function testPaginateIsOrderedAndMemoryEfficient(): void
    {
        foreach (range(1, 1000) as $id) {
            DB::connection('shard_1')->table('items')->insert(['id' => $id, 'value' => $id, 'is_replica' => false]);
            DB::connection('shard_2')->table('items')->insert(['id' => $id, 'value' => $id, 'is_replica' => true]);
        }

        foreach (range(1001, 2000) as $id) {
            DB::connection('shard_2')->table('items')->insert(['id' => $id, 'value' => $id, 'is_replica' => false]);
            DB::connection('shard_1')->table('items')->insert(['id' => $id, 'value' => $id, 'is_replica' => true]);
        }

        memory_reset_peak_usage();
        $baseline = memory_get_peak_usage(true);

        $page = Item::orderBy('id')->paginate(10, ['*'], 'page', 2);

        $this->assertSame(range(11, 20), $page->pluck('id')->all());
        $this->assertLessThan(8 * 1024 * 1024, memory_get_peak_usage(true) - $baseline);
    }

    public function testEagerLoadsRelationsAcrossShards(): void
    {
        DB::connection('shard_1')->table('parents')->insert(['id' => 1, 'is_replica' => false]);
        DB::connection('shard_1')->table('children')->insert([
            ['id' => 1, 'parent_id' => 1, 'is_replica' => false],
            ['id' => 2, 'parent_id' => 1, 'is_replica' => false],
        ]);

        DB::connection('shard_2')->table('parents')->insert(['id' => 2, 'is_replica' => false]);
        DB::connection('shard_2')->table('children')->insert([
            ['id' => 3, 'parent_id' => 2, 'is_replica' => false],
            ['id' => 4, 'parent_id' => 2, 'is_replica' => false],
        ]);

        $parents = ParentModel::with('children')->orderBy('id')->get();

        $this->assertCount(2, $parents);
        $this->assertSame([1, 2], $parents->pluck('id')->all());
        $this->assertSame([1, 2], $parents[0]->children->pluck('id')->all());
        $this->assertSame([3, 4], $parents[1]->children->pluck('id')->all());
    }
}

class Item extends Model
{
    use Shardable;

    protected $table = 'items';

    public $timestamps = false;

    protected $guarded = [];

    protected $casts = [
        'is_replica' => 'bool',
    ];
}

class ParentModel extends Model
{
    use Shardable;

    protected $table = 'parents';

    public $timestamps = false;

    protected $guarded = [];

    protected $casts = [
        'is_replica' => 'bool',
    ];

    public function children()
    {
        return $this->hasMany(ChildModel::class, 'parent_id');
    }
}

class ChildModel extends Model
{
    use Shardable;

    protected $table = 'children';

    public $timestamps = false;

    protected $guarded = [];

    protected $casts = [
        'is_replica' => 'bool',
    ];

    public function parent()
    {
        return $this->belongsTo(ParentModel::class, 'parent_id');
    }
}
