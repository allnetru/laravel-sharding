<?php

namespace Allnetru\Sharding\Tests\Unit;

use Allnetru\Sharding\Models\Concerns\Shardable;
use Allnetru\Sharding\ShardingManager;
use Allnetru\Sharding\Tests\TestCase;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Query\Expression;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The two reads that still answered from one shard: cursor() and pluck().
 */
class ShardStreamTest extends TestCase
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
            Schema::connection($connection)->create('readings', function (Blueprint $table): void {
                $table->unsignedBigInteger('id')->primary();
                $table->string('label');
                $table->integer('value');
                $table->boolean('is_replica')->default(false);
                $table->timestamp('deleted_at')->nullable();
            });
        }

        foreach ([1, 3, 5] as $id) {
            DB::connection('shard_1')->table('readings')->insert([
                'id' => $id, 'label' => "l{$id}", 'value' => $id, 'is_replica' => false, 'deleted_at' => null,
            ]);
        }

        foreach ([2, 4, 6] as $id) {
            DB::connection('shard_2')->table('readings')->insert([
                'id' => $id, 'label' => "l{$id}", 'value' => $id, 'is_replica' => false, 'deleted_at' => null,
            ]);
        }
    }

    public function testCursorWalksEveryShardInTheQuerysOrder(): void
    {
        $ids = [];

        foreach (Reading::orderBy('id')->cursor() as $reading) {
            $ids[] = $reading->id;
        }

        // merged, not shard after shard: the old answer would have been
        // three rows from whichever shard was guessed
        $this->assertSame([1, 2, 3, 4, 5, 6], $ids);
    }

    public function testCursorFollowsADescendingOrder(): void
    {
        $this->assertSame(
            [6, 5, 4, 3, 2, 1],
            Reading::orderByDesc('id')->cursor()->pluck('id')->all(),
        );
    }

    public function testCursorIsLazyAndStopsWhenTheCallerDoes(): void
    {
        $seen = [];

        foreach (Reading::orderBy('id')->cursor() as $reading) {
            $seen[] = $reading->id;

            if (count($seen) === 2) {
                break;
            }
        }

        $this->assertSame([1, 2], $seen);
    }

    public function testCursorHonoursGlobalScopes(): void
    {
        DB::connection('shard_1')->table('readings')->where('id', 3)->update(['deleted_at' => '2020-01-01 00:00:00']);
        DB::connection('shard_2')->table('readings')->where('id', 4)->update(['deleted_at' => '2020-01-01 00:00:00']);

        $this->assertSame([1, 2, 5, 6], SoftReading::orderBy('id')->cursor()->pluck('id')->all());
    }

    public function testPluckSpansTheShards(): void
    {
        $this->assertSame(
            ['l1', 'l2', 'l3', 'l4', 'l5', 'l6'],
            Reading::orderBy('id')->pluck('label')->all(),
        );
    }

    public function testPluckKeepsItsKey(): void
    {
        $this->assertSame(
            [1 => 'l1', 2 => 'l2', 3 => 'l3', 4 => 'l4', 5 => 'l5', 6 => 'l6'],
            Reading::orderBy('id')->pluck('label', 'id')->all(),
        );
    }

    public function testPluckOrdersByAColumnItDoesNotPluck(): void
    {
        // the merge compares models, so the ordering column has to survive the
        // read even though the caller never asked for it
        $this->assertSame(
            ['l6', 'l5', 'l4', 'l3', 'l2', 'l1'],
            Reading::orderByDesc('value')->pluck('label')->all(),
        );
    }

    public function testPluckAcceptsAQualifiedColumn(): void
    {
        $this->assertSame(
            ['l1', 'l2', 'l3', 'l4', 'l5', 'l6'],
            Reading::orderBy('id')->pluck('readings.label')->all(),
        );
    }

    public function testPluckAcceptsAnExpression(): void
    {
        // the branch that reaches for the grammar, which a static closure
        // could not have done
        $this->assertSame(
            ['l1', 'l2', 'l3', 'l4', 'l5', 'l6'],
            Reading::orderBy('id')->pluck(new Expression('label'))->all(),
        );
    }

    public function testPluckRespectsALimit(): void
    {
        $this->assertSame(['l1', 'l2'], Reading::orderBy('id')->limit(2)->pluck('label')->all());
    }
}

class Reading extends Model
{
    use Shardable;

    protected $table = 'readings';
    public $timestamps = false;
    public $incrementing = false;
    protected $guarded = [];
}

class SoftReading extends Reading
{
    use SoftDeletes;

    protected $table = 'readings';
}
