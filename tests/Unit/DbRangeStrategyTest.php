<?php

namespace Allnetru\Sharding\Tests\Unit;

use Allnetru\Sharding\Models\ShardRange;
use Allnetru\Sharding\Strategies\DbRangeStrategy;
use Allnetru\Sharding\Strategies\HashStrategy;
use Allnetru\Sharding\Tests\TestCase;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class DbRangeStrategyTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Schema::dropIfExists('shard_ranges');
        Schema::create('shard_ranges', function (Blueprint $table): void {
            $table->id();
            $table->string('table');
            $table->unsignedBigInteger('start');
            $table->unsignedBigInteger('end');
            $table->string('connection');
            $table->json('replicas')->nullable();
            $table->timestamps();
            $table->index(['table', 'start', 'end']);
            $table->unique(['table', 'start']);
        });
    }

    public function testDetermineReturnsExistingRangeWithReplicas(): void
    {
        $config = $this->baseConfig();
        $strategy = new DbRangeStrategy();

        DB::table('shard_ranges')->insert([
            'table' => 'orders',
            'start' => 1,
            'end' => 1000,
            'connection' => 'shard_b',
            'replicas' => json_encode(['shard_c']),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $connections = $strategy->determine(50, $config);

        $this->assertSame(['shard_b', 'shard_c'], $connections);
    }

    public function testDetermineFallsBackToHashStrategyWhenRangeMissing(): void
    {
        $config = $this->baseConfig();
        $strategy = new DbRangeStrategy();

        $connections = $strategy->determine(1500, $config);

        $start = intdiv(1500 - 1, $config['range_size']) * $config['range_size'] + 1;
        $expectedPrimary = app(HashStrategy::class)->determine($start, $config)[0];

        $this->assertSame($expectedPrimary, $connections[0]);
    }

    public function testDetermineThrowsWhenScopeMissing(): void
    {
        $strategy = new DbRangeStrategy();

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('No table scope provided for sharding.');

        $strategy->determine(5, ['range_size' => 100]);
    }

    public function testRecordMetaCreatesAndUpdatesRange(): void
    {
        $config = $this->baseConfig();
        $strategy = new DbRangeStrategy();

        $strategy->recordMeta(25, ['shard_a', 'shard_b'], $config);
        $range = ShardRange::on('sqlite')->where('table', 'orders')->where('start', 1)->where('end', 1000)->firstOrFail();
        $this->assertSame('shard_a', $range->connection);
        $this->assertSame(['shard_b'], $range->replicas);

        $strategy->recordMeta(25, ['shard_c'], $config);
        $range = ShardRange::on('sqlite')->where('table', 'orders')->where('start', 1)->where('end', 1000)->firstOrFail();
        $this->assertSame('shard_c', $range->connection);
        $this->assertSame([], $range->replicas);
        $this->assertSame(1, ShardRange::count());
    }

    public function testRecordReplicaCreatesAndAppendsUniqueReplicas(): void
    {
        $config = $this->baseConfig();
        $strategy = new DbRangeStrategy();

        $strategy->recordReplica(75, 'shard_a', $config);
        $range = ShardRange::on('sqlite')->where('table', 'orders')->where('start', 1)->where('end', 1000)->firstOrFail();
        $this->assertSame('shard_a', $range->connection);
        $this->assertSame([], $range->replicas);

        $strategy->recordMeta(75, ['shard_b'], $config);
        $strategy->recordReplica(75, 'shard_c', $config);
        $strategy->recordReplica(75, 'shard_c', $config);

        $range = ShardRange::on('sqlite')->where('table', 'orders')->where('start', 1)->where('end', 1000)->firstOrFail();
        $this->assertSame(['shard_c'], $range->replicas);
        $this->assertSame(1, ShardRange::count());
    }

    public function testAfterRebalancePersistsRangeWithReplicas(): void
    {
        $config = $this->baseConfig();
        $strategy = new DbRangeStrategy();

        $strategy->afterRebalance('orders', 'id', null, 'shard_b', 2001, 3000, $config);

        $range = ShardRange::where('start', 2001)->first();
        $this->assertSame('shard_b', $range->connection);
        $this->assertSame(['shard_c'], $range->replicas);

        // Ensure method is a no-op when missing data
        $strategy->afterRebalance('orders', 'id', null, null, null, 3000, $config);
        $this->assertSame(1, ShardRange::count());
    }

    public function testCanRebalance(): void
    {
        $this->assertTrue((new DbRangeStrategy())->canRebalance());
    }

    private function baseConfig(): array
    {
        return [
            'table' => 'orders',
            'meta_connection' => 'sqlite',
            'range_table' => 'shard_ranges',
            'range_size' => 1000,
            'connections' => [
                'shard_a' => ['weight' => 1],
                'shard_b' => ['weight' => 1],
                'shard_c' => ['weight' => 1],
            ],
            'replica_count' => 1,
        ];
    }
}
