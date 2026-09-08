<?php

namespace Allnetru\Sharding\Tests\Unit;

use Allnetru\Sharding\Exceptions\UnsupportedCrossShardQuery;
use Allnetru\Sharding\Models\Concerns\Shardable;
use Allnetru\Sharding\ShardingManager;
use Allnetru\Sharding\Tests\TestCase;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Writes through a query that cannot name its shard.
 *
 * These ran against one randomly chosen shard, and delete() was the worst of
 * them: it removed the rows it could reach, left the others, and returned the
 * count it did remove — so it read as success.
 */
class ShardWriteTest extends TestCase
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
            Schema::connection($connection)->create('tickets', function (Blueprint $table): void {
                $table->unsignedBigInteger('id')->primary();
                $table->unsignedBigInteger('tenant_id')->nullable();
                $table->integer('value');
                $table->boolean('is_replica')->default(false);
                $table->timestamp('deleted_at')->nullable();
            });
        }

        DB::connection('shard_1')->table('tickets')->insert([
            ['id' => 1, 'tenant_id' => 1, 'value' => 1, 'is_replica' => false, 'deleted_at' => null],
            ['id' => 3, 'tenant_id' => 1, 'value' => 3, 'is_replica' => false, 'deleted_at' => null],
        ]);

        DB::connection('shard_2')->table('tickets')->insert([
            ['id' => 2, 'tenant_id' => 2, 'value' => 2, 'is_replica' => false, 'deleted_at' => null],
            ['id' => 4, 'tenant_id' => 2, 'value' => 4, 'is_replica' => false, 'deleted_at' => null],
        ]);
    }

    protected function rowsOn(string $connection): int
    {
        return DB::connection($connection)->table('tickets')->count();
    }

    protected function liveOn(string $connection): int
    {
        return DB::connection($connection)->table('tickets')->whereNull('deleted_at')->count();
    }

    public function testUpdateReachesEveryShardAndReportsThemAll(): void
    {
        $touched = Ticket::where('value', '>', 0)->update(['value' => 99]);

        $this->assertSame(4, $touched);
        $this->assertSame(4, Ticket::where('value', 99)->count());
    }

    public function testUpdateStillRespectsItsWhere(): void
    {
        // the row lives on shard_2, and the update used to guess a shard
        $touched = Ticket::where('id', 4)->update(['value' => 40]);

        $this->assertSame(1, $touched);
        $this->assertSame(40, (int) Ticket::where('id', 4)->value('value'));
        $this->assertSame(1, (int) Ticket::where('id', 1)->value('value'));
    }

    public function testDeleteRemovesTheRowsOnEveryShard(): void
    {
        $removed = Ticket::where('value', '>', 0)->delete();

        $this->assertSame(4, $removed);
        $this->assertSame(0, $this->rowsOn('shard_1'));
        $this->assertSame(0, $this->rowsOn('shard_2'));
    }

    public function testASoftDeleteStaysSoftOnEveryShard(): void
    {
        $removed = SoftTicket::where('value', '>', 0)->delete();

        $this->assertSame(4, $removed);

        // the rows are still there, and all four are marked
        $this->assertSame(2, $this->rowsOn('shard_1'));
        $this->assertSame(2, $this->rowsOn('shard_2'));
        $this->assertSame(0, $this->liveOn('shard_1'));
        $this->assertSame(0, $this->liveOn('shard_2'));
        $this->assertSame(0, SoftTicket::count());
        $this->assertSame(4, SoftTicket::withTrashed()->count());
    }

    public function testRestoreReachesEveryShard(): void
    {
        SoftTicket::where('value', '>', 0)->delete();

        $restored = SoftTicket::withTrashed()->where('value', '>', 0)->restore();

        $this->assertSame(4, $restored);
        $this->assertSame(4, SoftTicket::count());
    }

    public function testForceDeleteReachesEveryShard(): void
    {
        SoftTicket::where('value', '>', 0)->delete();

        $removed = SoftTicket::withTrashed()->where('value', '>', 0)->forceDelete();

        $this->assertSame(4, $removed);
        $this->assertSame(0, $this->rowsOn('shard_1'));
        $this->assertSame(0, $this->rowsOn('shard_2'));
    }

    public function testIncrementAndDecrementReachEveryShard(): void
    {
        $this->assertSame(4, Ticket::where('value', '>', 0)->increment('value', 10));
        $this->assertSame(50, (int) Ticket::sum('value'));

        $this->assertSame(4, Ticket::where('value', '>', 0)->decrement('value', 10));
        $this->assertSame(10, (int) Ticket::sum('value'));
    }

    public function testAWriteWithALimitIsRefused(): void
    {
        // five rows means five, not five per shard
        $this->expectException(UnsupportedCrossShardQuery::class);
        $this->expectExceptionMessageMatches('/cannot carry a limit across shards/');

        Ticket::where('value', '>', 0)->limit(1)->update(['value' => 99]);
    }

    public function testDeletingWithALimitIsRefused(): void
    {
        $this->expectException(UnsupportedCrossShardQuery::class);

        Ticket::where('value', '>', 0)->limit(1)->delete();
    }

    public function testChangingTheShardKeyIsRefused(): void
    {
        $this->expectException(UnsupportedCrossShardQuery::class);
        $this->expectExceptionMessageMatches('/it is the shard key/');

        Ticket::where('value', '>', 0)->update(['tenant_id' => 7]);
    }

    public function testUpsertIsRefusedRatherThanSentToOneShard(): void
    {
        $this->expectException(UnsupportedCrossShardQuery::class);
        $this->expectExceptionMessageMatches('/upsert\(\) cannot be spread across shards/');

        Ticket::query()->upsert([['id' => 9, 'tenant_id' => 1, 'value' => 9]], ['id'], ['value']);
    }

    public function testAPinnedWriteKeepsTheOrdinaryBehaviour(): void
    {
        $touched = Ticket::query()->onShardConnection('shard_1')->where('value', '>', 0)->update(['value' => 99]);

        $this->assertSame(2, $touched);
        $this->assertSame(2, $this->rowsOn('shard_2'));
        $this->assertSame(0, DB::connection('shard_2')->table('tickets')->where('value', 99)->count());

        // pinned, so the shard was named on purpose and a limit means what it says
        $this->assertSame(
            1,
            Ticket::query()->onShardConnection('shard_1')->where('value', 99)->limit(1)->update(['value' => 5]),
        );
    }
}

class Ticket extends Model
{
    use Shardable;

    protected $table = 'tickets';
    public $timestamps = false;
    public $incrementing = false;
    protected $guarded = [];
    protected string $shardKey = 'tenant_id';
}

class SoftTicket extends Ticket
{
    use SoftDeletes;

    protected $table = 'tickets';
}
