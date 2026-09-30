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
 * An aggregate that cannot be added up across shards, on a query that names one.
 *
 * A distinct count, a grouped count or a having clause is refused across
 * shards because each shard answers a different question. A query that gives
 * its shard key has one shard and one answer, and was refused all the same:
 * `where('user_id', 5)->distinct()->count('letter_id')` threw.
 */
class ShardKeyedAggregateTest extends TestCase
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
            Schema::connection($connection)->create('letters', function (Blueprint $table): void {
                $table->unsignedBigInteger('id')->primary();
                $table->unsignedBigInteger('user_id');
                $table->integer('letter_id');
                $table->boolean('is_replica')->default(false);
            });
        }

        [$mine, $other] = $this->shardsOf(7);

        // three rows in two letters on the key's own shard
        DB::connection($mine)->table('letters')->insert([
            ['id' => 1, 'user_id' => 7, 'letter_id' => 100],
            ['id' => 2, 'user_id' => 7, 'letter_id' => 100],
            ['id' => 3, 'user_id' => 7, 'letter_id' => 200],
        ]);

        // and the same letter id elsewhere, which a fan-out would count again
        DB::connection($other)->table('letters')->insert(['id' => 4, 'user_id' => 8, 'letter_id' => 100]);
    }

    public function testADistinctCountIsAnsweredOnTheKeysShard(): void
    {
        $this->assertSame(2, KeyedLetter::query()->where('user_id', 7)->distinct()->count('letter_id'));
    }

    public function testADistinctSumIsAnsweredOnTheKeysShard(): void
    {
        $this->assertSame(300, (int) KeyedLetter::query()->where('user_id', 7)->distinct()->sum('letter_id'));
    }

    public function testAGroupedCountIsAnsweredOnTheKeysShard(): void
    {
        // one row per letter, counted by the shard that holds them all
        $this->assertSame(2, KeyedLetter::query()->where('user_id', 7)->groupBy('letter_id')->get(['letter_id'])->count());
        $this->assertSame(2, (int) KeyedLetter::query()->where('user_id', 7)->groupBy('letter_id')->count());
    }

    public function testAHavingClauseIsAnsweredOnTheKeysShard(): void
    {
        $count = KeyedLetter::query()
            ->where('user_id', 7)
            ->groupBy('letter_id')
            ->havingRaw('count(*) > 1')
            ->count();

        // only letter 100 has two rows
        $this->assertSame(1, (int) $count);
    }

    public function testAGroupedAverageIsTheShardsOwn(): void
    {
        $expected = DB::connection($this->shardsOf(7)[0])->table('letters')
            ->where('user_id', 7)
            ->groupBy('letter_id')
            ->avg('letter_id');

        $this->assertEquals($expected, KeyedLetter::query()->where('user_id', 7)->groupBy('letter_id')->avg('letter_id'));
    }

    public function testAnUnkeyedDistinctCountIsStillRefused(): void
    {
        $this->expectException(UnsupportedCrossShardQuery::class);
        $this->expectExceptionMessageMatches('/once for every shard that holds it/');

        KeyedLetter::query()->distinct()->count('letter_id');
    }

    public function testARebalanceLeavesTheKeyedDistinctCountRefused(): void
    {
        /*
        | `pin_by_key` is off while a rebalance moves rows, so a keyed query
        | fans out and there are two answers again — refused, as without a key.
        */
        config(['sharding.pin_by_key' => false]);

        $this->expectException(UnsupportedCrossShardQuery::class);

        KeyedLetter::query()->where('user_id', 7)->distinct()->count('letter_id');
    }

    /**
     * @return array{0: string, 1: string}
     */
    protected function shardsOf(int $key): array
    {
        $mine = app(ShardingManager::class)->connectionFor(new KeyedLetter(), $key)[0];

        return [$mine, $mine === 'shard_1' ? 'shard_2' : 'shard_1'];
    }
}

class KeyedLetter extends Model
{
    use Shardable;

    protected $table = 'letters';

    protected $guarded = [];

    public $timestamps = false;

    protected string $shardKey = 'user_id';
}
