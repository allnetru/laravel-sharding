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
use RuntimeException;

/**
 * A transaction on the shard the key names.
 *
 * `DB::transaction()` opens on the default connection, which on a sharded
 * schema wraps nothing the callback touches: the writes inside it go to the
 * shard their key names and commit one by one regardless. The application
 * used to spell the connection itself to get around that, which is the
 * knowledge it should not carry.
 */
class ShardTransactionTest extends TestCase
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
        return app(ShardingManager::class)->connectionFor(new TransactedOrder(), $tenantId)[0];
    }

    public function testTheBuildersTransactionOpensOnTheShardTheKeyNames(): void
    {
        $shard = $this->shardOf(7);
        DB::connection($shard)->table('orders')->insert(['id' => 1, 'tenant_id' => 7, 'value' => 1]);

        try {
            TransactedOrder::query()->where('tenant_id', 7)->transaction(static function (): void {
                TransactedOrder::query()->where('tenant_id', 7)->where('id', 1)->update(['value' => 100]);

                throw new RuntimeException('roll it back');
            });
        } catch (RuntimeException) {
            // expected: the point is what the rollback left behind
        }

        $this->assertSame(1, (int) DB::connection($shard)->table('orders')->where('id', 1)->value('value'));
    }

    public function testTheRowsOwnTransactionCommitsOnItsShard(): void
    {
        $shard = $this->shardOf(7);
        DB::connection($shard)->table('orders')->insert(['id' => 1, 'tenant_id' => 7, 'value' => 1]);

        $order = TransactedOrder::query()->where('tenant_id', 7)->where('id', 1)->firstOrFail();
        $order->transaction(static fn () => TransactedOrder::query()
            ->where('tenant_id', 7)
            ->where('id', 1)
            ->update(['value' => 5]));

        $this->assertSame(5, (int) DB::connection($shard)->table('orders')->where('id', 1)->value('value'));
    }

    public function testATransactionOnAKeylessRowIsRefused(): void
    {
        $this->expectException(UnsupportedCrossShardQuery::class);

        (new TransactedOrder())->transaction(static fn () => null);
    }

    public function testAZeroShardKeyIsAKeyLikeAnyOther(): void
    {
        $shard = $this->shardOf(0);
        DB::connection($shard)->table('orders')->insert(['id' => 2, 'tenant_id' => 0, 'value' => 1]);

        $order = TransactedOrder::query()->where('tenant_id', 0)->where('id', 2)->firstOrFail();
        $order->transaction(static fn () => TransactedOrder::query()
            ->where('tenant_id', 0)
            ->where('id', 2)
            ->update(['value' => 3]));

        $this->assertSame(3, (int) DB::connection($shard)->table('orders')->where('id', 2)->value('value'));
    }

    public function testATransactionOnAnUnpinnedQueryIsRefused(): void
    {
        $this->expectException(UnsupportedCrossShardQuery::class);
        $this->expectExceptionMessage('transaction() runs on one shard');

        TransactedOrder::query()->where('value', '>', 0)->transaction(static fn () => null);
    }

    public function testATransactionSpanningTwoKeysIsRefused(): void
    {
        $this->expectException(UnsupportedCrossShardQuery::class);

        TransactedOrder::query()->whereIn('tenant_id', [7, 8])->transaction(static fn () => null);
    }

    public function testAConnectionNamedOutrightIsHonoured(): void
    {
        DB::connection('shard_2')->table('orders')->insert(['id' => 9, 'tenant_id' => 99, 'value' => 5]);

        TransactedOrder::query()->onShardConnection('shard_2')->transaction(static fn () => DB::connection('shard_2')
            ->table('orders')
            ->where('id', 9)
            ->update(['value' => 6]));

        $this->assertSame(6, (int) DB::connection('shard_2')->table('orders')->where('id', 9)->value('value'));
    }
}

class TransactedOrder extends Model
{
    use Shardable;

    protected $table = 'orders';

    protected $guarded = [];

    public $timestamps = false;

    protected string $shardKey = 'tenant_id';
}
