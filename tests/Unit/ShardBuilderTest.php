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

    public function testLimitIsPushedDownToEveryShard(): void
    {
        $this->seedInterleaved();
        $this->startLoggingShardQueries();

        $values = Item::orderBy('id')->limit(3)->get()->pluck('id')->all();

        $this->assertSame([1, 2, 3], $values);

        foreach (['shard_1', 'shard_2'] as $connection) {
            $this->assertMatchesRegularExpression(
                '/limit 3$/',
                $this->lastSelectOn($connection),
                "the query sent to {$connection} carried no bound",
            );
        }
    }

    public function testOffsetAndLimitPushDownTheirSumToEveryShard(): void
    {
        $this->seedInterleaved();
        $this->startLoggingShardQueries();

        $values = Item::orderBy('id')->offset(2)->limit(3)->get()->pluck('id')->all();

        $this->assertSame([3, 4, 5], $values);

        // five rather than three: the merge discards the first two globally,
        // and either shard may be the one that supplied them
        foreach (['shard_1', 'shard_2'] as $connection) {
            $this->assertMatchesRegularExpression('/limit 5$/', $this->lastSelectOn($connection));
        }
    }

    public function testAnUnorderedLimitOrdersEachShardBeforeBoundingIt(): void
    {
        $this->seedInterleaved();
        $this->startLoggingShardQueries();

        $values = Item::limit(3)->get()->pluck('id')->all();

        // without the order the bound would cut an arbitrary three rows from
        // each shard, and merging arbitrary subsets answers arbitrarily
        $this->assertSame([1, 2, 3], $values);

        foreach (['shard_1', 'shard_2'] as $connection) {
            $this->assertStringContainsString('order by "id" asc', $this->lastSelectOn($connection));
        }
    }

    public function testAnUnboundedGetStaysUnbounded(): void
    {
        $this->seedInterleaved();
        $this->startLoggingShardQueries();

        $values = Item::orderBy('id')->get()->pluck('id')->all();

        $this->assertSame([1, 2, 3, 4, 5, 6], $values);

        // no limit was asked for, so none can be derived: every row may be
        // part of the answer
        foreach (['shard_1', 'shard_2'] as $connection) {
            $this->assertStringNotContainsString('limit', $this->lastSelectOn($connection));
        }
    }

    public function testPaginateBoundsTheCursorAndLeavesTheCountAlone(): void
    {
        $this->seedInterleaved();
        $this->startLoggingShardQueries();

        $page = Item::orderBy('id')->paginate(2, ['*'], 'page', 2);

        $this->assertSame([3, 4], $page->pluck('id')->all());
        $this->assertSame(6, $page->total());

        foreach (['shard_1', 'shard_2'] as $connection) {
            $this->assertMatchesRegularExpression('/limit 4$/', $this->lastSelectOn($connection));
            $this->assertStringNotContainsString('limit', $this->lastCountOn($connection));
        }
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

    public function testGetWithoutExplicitOrderUsesPrimaryKey(): void
    {
        foreach ([5, 1, 3, 2, 4] as $id) {
            $target = $id % 2 === 0 ? 'shard_2' : 'shard_1';
            DB::connection($target)->table('items')->insert(['id' => $id, 'value' => $id, 'is_replica' => false]);
        }

        $values = Item::get()->pluck('id')->all();

        $this->assertSame([1, 2, 3, 4, 5], $values);
    }

    public function testChunkIteratesAcrossAllConnections(): void
    {
        foreach (range(1, 6) as $id) {
            $target = $id <= 3 ? 'shard_1' : 'shard_2';
            DB::connection($target)->table('items')->insert(['id' => $id, 'value' => $id, 'is_replica' => false]);
        }

        $seen = [];
        $result = Item::orderBy('id')->chunk(2, function ($items) use (&$seen): void {
            foreach ($items as $item) {
                $seen[] = $item->id;
            }
        });

        $this->assertTrue($result);
        $this->assertSame(range(1, 6), $seen);
    }

    public function testChunkByIdIteratesAcrossAllConnections(): void
    {
        foreach (range(1, 6) as $id) {
            $target = $id <= 3 ? 'shard_1' : 'shard_2';
            DB::connection($target)->table('items')->insert(['id' => $id, 'value' => $id, 'is_replica' => false]);
        }

        $seen = [];
        $result = Item::orderBy('id')->chunkById(2, function ($items) use (&$seen): void {
            foreach ($items as $item) {
                $seen[] = $item->id;
            }
        });

        $this->assertTrue($result);
        $this->assertSame(range(1, 6), $seen);
    }

    public function testFirstOrCreateFindsExistingAcrossConnections(): void
    {
        DB::connection('shard_2')->table('items')->insert(['id' => 50, 'value' => 100, 'is_replica' => false]);

        $item = Item::query()->firstOrCreate(['id' => 50], ['value' => 200]);

        $this->assertSame(100, $item->value);
        $this->assertSame('shard_2', $item->getConnectionName());
    }

    public function testUpdateOrCreateUpdatesExistingAcrossConnections(): void
    {
        DB::connection('shard_1')->table('items')->insert(['id' => 75, 'value' => 10, 'is_replica' => false]);

        $item = Item::query()->updateOrCreate(['id' => 75], ['value' => 20]);

        $this->assertSame(20, $item->value);
        $this->assertSame('shard_1', $item->getConnectionName());
        $this->assertDatabaseHas('items', ['id' => 75, 'value' => 20], 'shard_1');
    }

    /**
     * Put the odd identifiers on one shard and the even ones on the other, so
     * neither shard alone can answer an ordered page.
     */
    protected function seedInterleaved(): void
    {
        foreach ([1, 3, 5] as $id) {
            DB::connection('shard_1')->table('items')->insert(['id' => $id, 'value' => $id, 'is_replica' => false]);
        }

        foreach ([2, 4, 6] as $id) {
            DB::connection('shard_2')->table('items')->insert(['id' => $id, 'value' => $id, 'is_replica' => false]);
        }
    }

    protected function startLoggingShardQueries(): void
    {
        foreach (['shard_1', 'shard_2'] as $connection) {
            DB::connection($connection)->flushQueryLog();
            DB::connection($connection)->enableQueryLog();
        }
    }

    /**
     * The last row-returning statement the shard was asked to run.
     */
    protected function lastSelectOn(string $connection): string
    {
        return $this->lastQueryOn($connection, static fn (string $sql): bool => str_starts_with($sql, 'select ')
            && !str_contains($sql, 'count(*)'));
    }

    protected function lastCountOn(string $connection): string
    {
        return $this->lastQueryOn($connection, static fn (string $sql): bool => str_contains($sql, 'count(*)'));
    }

    /**
     * @param callable(string): bool $matches
     */
    protected function lastQueryOn(string $connection, callable $matches): string
    {
        $found = null;

        foreach (DB::connection($connection)->getQueryLog() as $entry) {
            if ($matches($entry['query'])) {
                $found = $entry['query'];
            }
        }

        $this->assertNotNull($found, "no matching statement reached {$connection}");

        return $found;
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
