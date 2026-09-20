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
 * SQL the builder cannot spell, routed by the query's own shard key.
 *
 * Before this the application picked a connection for such statements
 * itself, and a fresh model's connection is the first configured shard — so
 * an update written for a tenant on the second shard ran on the first,
 * touched no rows and raised nothing. Here the query names its key and the
 * package names the shard; without a key there is no guess, there is a
 * refusal.
 */
class ShardRawTest extends TestCase
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

    /**
     * The shard a tenant's rows live on, by the same strategy the model uses.
     */
    protected function shardOf(int $tenantId): string
    {
        return app(ShardingManager::class)->connectionFor(new RawOrder(), $tenantId)[0];
    }

    public function testRawSelectRunsOnTheShardTheKeyNames(): void
    {
        $shard = $this->shardOf(7);
        DB::connection($shard)->table('orders')->insert(['id' => 1, 'tenant_id' => 7, 'value' => 42]);

        $rows = RawOrder::query()->where('tenant_id', 7)->rawSelect(
            'select sum(value) as total from orders where tenant_id = ?',
            [7],
        );

        $this->assertSame(42, (int) $rows[0]->total);
        $this->assertSame(42, (int) RawOrder::query()->where('tenant_id', 7)->rawSelectOne('select value from orders where id = 1')->value);
    }

    public function testRawStatementChangesRowsWhereTheyAre(): void
    {
        $shard = $this->shardOf(7);
        $other = $shard === 'shard_1' ? 'shard_2' : 'shard_1';
        DB::connection($shard)->table('orders')->insert(['id' => 1, 'tenant_id' => 7, 'value' => 1]);
        DB::connection($other)->table('orders')->insert(['id' => 2, 'tenant_id' => 7, 'value' => 1]);

        $changed = RawOrder::query()->where('tenant_id', 7)->rawAffectingStatement(
            'update orders set value = value + 10 where tenant_id = ?',
            [7],
        );

        // the row that sits where the key says, and only it
        $this->assertSame(1, $changed);
        $this->assertSame(11, (int) DB::connection($shard)->table('orders')->where('id', 1)->value('value'));
        $this->assertSame(1, (int) DB::connection($other)->table('orders')->where('id', 2)->value('value'));
        $this->assertTrue(RawOrder::query()->where('tenant_id', 7)->rawStatement('update orders set value = 0 where tenant_id = ?', [7]));
    }

    public function testRawSqlWithoutTheKeyIsRefused(): void
    {
        $this->expectException(UnsupportedCrossShardQuery::class);
        $this->expectExceptionMessage('rawSelect() runs on one shard');

        RawOrder::query()->where('value', '>', 0)->rawSelect('select 1');
    }

    public function testTwoValuesOfTheKeyAreRefusedToo(): void
    {
        $this->expectException(UnsupportedCrossShardQuery::class);

        RawOrder::query()->whereIn('tenant_id', [7, 8])->rawStatement('select 1');
    }

    public function testANamedConnectionIsHonoured(): void
    {
        DB::connection('shard_2')->table('orders')->insert(['id' => 9, 'tenant_id' => 99, 'value' => 5]);

        $row = RawOrder::query()->onShardConnection('shard_2')->rawSelectOne('select value from orders where id = 9');

        $this->assertSame(5, (int) $row->value);
    }
}

class RawOrder extends Model
{
    use Shardable;

    protected $table = 'orders';

    protected $guarded = [];

    public $timestamps = false;

    protected string $shardKey = 'tenant_id';
}
