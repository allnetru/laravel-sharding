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
 * The query builder under a keyed query, on the shard the key names.
 *
 * `toBase()` is how a caller reads plain rows — a select of expressions a
 * model's casts would misread — and how Eloquent itself answers `rawValue()`,
 * `implode()` and `aggregate()`. It returned the query on the model's default
 * connection, so on any other shard a query that named its key came back
 * empty, silently.
 */
class ShardToBaseTest extends TestCase
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
            Schema::connection($connection)->create('plots', function (Blueprint $table): void {
                $table->unsignedBigInteger('id')->primary();
                $table->unsignedBigInteger('tenant_id');
                $table->integer('area');
                $table->boolean('is_replica')->default(false);
            });
        }
    }

    /**
     * A tenant whose rows live on a shard other than the model's default connection: the one a query used to miss.
     */
    protected function tenantAway(): int
    {
        $default = (new BasePlot())->getConnectionName() ?? DB::getDefaultConnection();

        for ($tenant = 1; $tenant < 100; $tenant++) {
            $shard = app(ShardingManager::class)->connectionFor(new BasePlot(), $tenant)[0];

            if ($shard !== $default) {
                DB::connection($shard)->table('plots')->insert(['id' => $tenant * 10, 'tenant_id' => $tenant, 'area' => 600]);

                return $tenant;
            }
        }

        $this->fail('every tenant landed on the default connection');
    }

    public function testAKeyedQuerysBaseReadsTheShardTheKeyNames(): void
    {
        $tenant = $this->tenantAway();
        $base = BasePlot::query()->where('tenant_id', $tenant)->selectRaw('area * 2 as doubled')->toBase();

        $this->assertSame(app(ShardingManager::class)->connectionFor(new BasePlot(), $tenant)[0], $base->getConnection()->getName());
        $this->assertSame([1200], $base->get()->map(static fn (object $row): int => (int) $row->doubled)->all());
    }

    public function testEloquentsOwnPassthroughsReadThatShardToo(): void
    {
        $tenant = $this->tenantAway();

        $this->assertSame(600, (int) BasePlot::query()->where('tenant_id', $tenant)->rawValue('max(area)'));
        $this->assertSame('600', BasePlot::query()->where('tenant_id', $tenant)->implode('area', ','));
    }

    public function testTheBaseKeepsTheModelsScopes(): void
    {
        $tenant = $this->tenantAway();
        $shard = app(ShardingManager::class)->connectionFor(new BasePlot(), $tenant)[0];
        DB::connection($shard)->table('plots')->insert(['id' => $tenant * 10 + 1, 'tenant_id' => $tenant, 'area' => 1, 'is_replica' => true]);

        $this->assertSame(1, BasePlot::query()->where('tenant_id', $tenant)->toBase()->count());
    }

    public function testAQueryNamingNoKeyIsLeftAsItWas(): void
    {
        $query = BasePlot::query()->where('area', '>', 100);

        // nothing to pin it to: it compiles as before, on the model's own connection
        $this->assertSame($query->getModel()->getConnection()->getName(), $query->toBase()->getConnection()->getName());
        $this->assertStringContainsString('"area" > ?', $query->toBase()->toSql());
    }

    public function testAKeyedQueryIsNotPinnedWhileARebalanceMovesRows(): void
    {
        /*
        | `pin_by_key` is off while a rebalance moves rows: no read is pinned
        | by its key then, and the base builder stays where it always was.
        */
        $tenant = $this->tenantAway();
        config(['sharding.pin_by_key' => false]);
        $query = BasePlot::query()->where('tenant_id', $tenant);

        $this->assertSame($query->getModel()->getConnection()->getName(), $query->toBase()->getConnection()->getName());
    }

    public function testAPinnedBuildersBaseStaysWhereItWasPinned(): void
    {
        $base = BasePlot::query()->onShardConnection('shard_2')->toBase();

        $this->assertSame('shard_2', $base->getConnection()->getName());
    }
}

class BasePlot extends Model
{
    use Shardable;

    protected $table = 'plots';

    protected $guarded = [];

    public $timestamps = false;

    protected string $shardKey = 'tenant_id';
}
