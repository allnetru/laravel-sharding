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
 * Aggregates over a query that cannot name its shard.
 *
 * Every case here answered from one randomly chosen shard before the fix:
 * a model with no shard key generates one in getConnectionName() and routes
 * to whatever it hashes to, so the same call could give a different wrong
 * answer twice in a row.
 */
class ShardAggregateTest extends TestCase
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
            Schema::connection($connection)->create('measurements', function (Blueprint $table): void {
                $table->unsignedBigInteger('id')->primary();
                $table->integer('value')->nullable();
                $table->boolean('is_replica')->default(false);
            });
        }
    }

    /**
     * @param array<int, array{int, int|null}> $rows
     */
    protected function insertRows(string $connection, array $rows, bool $isReplica = false): void
    {
        foreach ($rows as [$id, $value]) {
            DB::connection($connection)->table('measurements')->insert([
                'id' => $id,
                'value' => $value,
                'is_replica' => $isReplica,
            ]);
        }
    }

    /**
     * Six rows, ids 1..6, values equal to the ids: sum 21, avg 3.5.
     */
    protected function seedInterleaved(): void
    {
        $this->insertRows('shard_1', [[1, 1], [3, 3], [5, 5]]);
        $this->insertRows('shard_2', [[2, 2], [4, 4], [6, 6]]);
    }

    public function testCountSumsEveryShard(): void
    {
        $this->seedInterleaved();

        $this->assertSame(6, Measurement::count());
        $this->assertSame(6, Measurement::query()->count());
    }

    public function testCountDoesNotAnswerFromOneShardTwiceInARow(): void
    {
        $this->seedInterleaved();

        // the shard used to be picked per call, so repeating the call was the
        // cheapest way to see the bug at all
        $this->assertSame([6, 6, 6], [Measurement::count(), Measurement::count(), Measurement::count()]);
    }

    public function testCountIgnoresReplicaRows(): void
    {
        $this->seedInterleaved();

        // the same rows copied onto the other shard: summing the shards would
        // count each of them twice if replicas were not excluded
        $this->insertRows('shard_2', [[1, 1], [3, 3], [5, 5]], isReplica: true);
        $this->insertRows('shard_1', [[2, 2], [4, 4], [6, 6]], isReplica: true);

        $this->assertSame(6, Measurement::count());
        $this->assertSame(21, (int) Measurement::sum('value'));
    }

    public function testCountRespectsAWhereThatMatchesOnOneShardOnly(): void
    {
        $this->seedInterleaved();

        // the row lives on shard_2, and a count that guessed shard_1 answered
        // zero over an existing row
        $this->assertSame(1, Measurement::where('value', 4)->count());
        $this->assertSame(0, Measurement::where('value', 99)->count());
    }

    public function testSumAndExtremesSpanTheShards(): void
    {
        $this->seedInterleaved();

        $this->assertSame(21, (int) Measurement::sum('value'));
        $this->assertSame(1, (int) Measurement::min('value'));
        $this->assertSame(6, (int) Measurement::max('value'));
    }

    public function testAverageIsNotTheAverageOfTheShardAverages(): void
    {
        // shard_1 averages 2, shard_2 averages 10, and the answer is neither
        // 6 nor anything else you get without weighting by row count
        $this->insertRows('shard_1', [[1, 1], [2, 2], [3, 3]]);
        $this->insertRows('shard_2', [[4, 10]]);

        $this->assertSame(4.0, (float) Measurement::avg('value'));
        $this->assertSame(4.0, (float) Measurement::average('value'));
    }

    public function testAverageIgnoresNullsTheWaySqlDoes(): void
    {
        $this->insertRows('shard_1', [[1, 2], [3, null]]);
        $this->insertRows('shard_2', [[2, 4], [4, null]]);

        // AVG divides by the rows that have a value, not by the rows
        $this->assertSame(3.0, (float) Measurement::avg('value'));
    }

    public function testExtremesIgnoreShardsThatHaveNothingToSay(): void
    {
        $this->insertRows('shard_1', [[1, null], [3, null]]);
        $this->insertRows('shard_2', [[2, 7]]);

        $this->assertSame(7, (int) Measurement::min('value'));
        $this->assertSame(7, (int) Measurement::max('value'));
    }

    public function testAggregatesOverNoRowsAnswerTheWaySqlDoes(): void
    {
        $this->assertSame(0, Measurement::count());
        $this->assertSame(0, (int) Measurement::sum('value'));
        $this->assertNull(Measurement::min('value'));
        $this->assertNull(Measurement::max('value'));
        $this->assertNull(Measurement::avg('value'));
    }

    public function testExistsAsksEveryShardBeforeSayingNo(): void
    {
        $this->seedInterleaved();

        $this->assertTrue(Measurement::query()->exists());
        // only on shard_2
        $this->assertTrue(Measurement::where('value', 4)->exists());
        $this->assertFalse(Measurement::where('value', 99)->exists());
        $this->assertFalse(Measurement::where('value', 4)->doesntExist());
        $this->assertTrue(Measurement::where('value', 99)->doesntExist());
    }

    public function testAGroupedAggregateIsRefusedRatherThanGuessed(): void
    {
        $this->seedInterleaved();

        $this->expectException(UnsupportedCrossShardQuery::class);
        $this->expectExceptionMessageMatches('/count\(\) cannot be combined across shards/');

        Measurement::query()->groupBy('value')->count();
    }

    public function testADistinctAggregateIsRefusedRatherThanDoubleCounted(): void
    {
        $this->seedInterleaved();

        $this->expectException(UnsupportedCrossShardQuery::class);
        $this->expectExceptionMessageMatches('/once for every shard that holds it/');

        Measurement::query()->distinct()->count('value');
    }

    public function testAHavingClauseIsRefused(): void
    {
        $this->seedInterleaved();

        $this->expectException(UnsupportedCrossShardQuery::class);

        Measurement::query()->groupBy('value')->having('value', '>', 1)->sum('value');
    }

    public function testAPinnedQueryKeepsTheOrdinaryBehaviour(): void
    {
        $this->seedInterleaved();

        $pinned = Measurement::query()->onShardConnection('shard_1');

        $this->assertSame(3, $pinned->count());

        // pinned, so the shard was named on purpose and grouping is answerable
        $this->assertSame(1, Measurement::query()->onShardConnection('shard_1')->groupBy('value')->count());
    }
}

class Measurement extends Model
{
    use Shardable;

    protected $table = 'measurements';
    public $timestamps = false;
    public $incrementing = false;
    protected $guarded = [];
}
