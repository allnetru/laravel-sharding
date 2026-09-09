<?php

namespace Allnetru\Sharding\Tests\Unit;

use Allnetru\Sharding\Models\ShardSlot;
use Allnetru\Sharding\Strategies\DbHashRangeStrategy;
use Allnetru\Sharding\Strategies\HashStrategy;
use Allnetru\Sharding\Support\ShardedTable;
use Allnetru\Sharding\Tests\TestCase;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use InvalidArgumentException;

class DbHashRangeStrategyTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Schema::dropIfExists('shard_slots');
        Schema::create('shard_slots', function (Blueprint $table): void {
            $table->id();
            $table->string('table');
            $table->unsignedBigInteger('slot');
            $table->string('connection');
            $table->json('replicas')->nullable();
            $table->timestamps();
            $table->unique(['table', 'slot']);
        });
    }

    public function testDeterminePersistsHashSlotAssignments(): void
    {
        $config = $this->baseConfig();
        $strategy = new DbHashRangeStrategy();
        $key = 'key2';

        $resolved = $strategy->determine($key, $config);
        $strategy->recordMeta($key, $resolved, $config);

        $hash = (int) sprintf('%u', crc32((string) $key));
        $slotId = intdiv($hash, $config['slot_size']);
        $expectedConnection = app(HashStrategy::class)->determine($slotId, $config)[0];

        $this->assertSame($expectedConnection, $resolved[0]);
        $this->assertCount(2, $resolved);

        $slot = ShardSlot::on('sqlite')->where('table', 'users')->where('slot', $slotId)->first();
        $this->assertNotNull($slot);
        $this->assertSame($expectedConnection, $slot->connection);

        $names = array_keys($config['connections']);
        sort($names);
        $expectedReplica = $names[(array_search($expectedConnection, $names, true) + 1) % count($names)];
        $this->assertSame([$expectedReplica], $slot->replicas);
    }

    public function testDetermineThrowsWhenScopeMissing(): void
    {
        $strategy = new DbHashRangeStrategy();

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('No table scope provided for sharding.');

        $strategy->determine(1, [
            'connections' => ['primary' => ['weight' => 1]],
        ]);
    }

    public function testDetermineUsesExistingSlotAndReplicas(): void
    {
        $config = $this->baseConfig();
        $strategy = new DbHashRangeStrategy();
        $key = 'existing-slot';
        $hash = (int) sprintf('%u', crc32($key));
        $slotId = intdiv($hash, $config['slot_size']);

        DB::table('shard_slots')->insert([
            'table' => 'users',
            'slot' => $slotId,
            'connection' => 'shard2',
            'replicas' => json_encode(['shard1', 'shard3']),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $connections = $strategy->determine($key, $config);

        $this->assertSame(['shard2', 'shard1', 'shard3'], $connections);
    }

    public function testRecordMetaCreatesAndUpdatesSlot(): void
    {
        $config = $this->baseConfig();
        $strategy = new DbHashRangeStrategy();
        $key = 'meta-slot';
        $hash = (int) sprintf('%u', crc32($key));
        $slotId = intdiv($hash, $config['slot_size']);

        $strategy->recordMeta($key, ['shard1', 'shard2'], $config);
        $slot = ShardSlot::on('sqlite')->where('table', 'users')->where('slot', $slotId)->firstOrFail();
        $this->assertSame('shard1', $slot->connection);
        $this->assertSame(['shard2'], $slot->replicas);

        $strategy->recordMeta($key, ['shard3', 'shard1'], $config);
        $slot = ShardSlot::on('sqlite')->where('table', 'users')->where('slot', $slotId)->firstOrFail();
        $this->assertSame('shard3', $slot->connection);
        $this->assertSame(['shard1'], $slot->replicas);
        $this->assertSame(1, ShardSlot::count());
    }

    public function testRecordReplicaCreatesNewSlotAndAppendsUniqueReplicas(): void
    {
        $config = $this->baseConfig();
        $strategy = new DbHashRangeStrategy();
        $key = 'replica-slot';
        $hash = (int) sprintf('%u', crc32($key));
        $slotId = intdiv($hash, $config['slot_size']);

        $strategy->recordReplica($key, 'shard1', $config);
        $slot = ShardSlot::on('sqlite')->where('table', 'users')->where('slot', $slotId)->firstOrFail();
        $this->assertSame('shard1', $slot->connection);
        $this->assertSame([], $slot->replicas);

        $strategy->recordMeta($key, ['shard2'], $config);
        $strategy->recordReplica($key, 'shard3', $config);
        $strategy->recordReplica($key, 'shard3', $config);

        $slot = ShardSlot::on('sqlite')->where('table', 'users')->where('slot', $slotId)->firstOrFail();
        $this->assertSame(['shard3'], $slot->replicas);
        $this->assertSame(1, ShardSlot::count());
    }

    public function testRowMovedSwapsReplicaAndHandlesDuplicateInserts(): void
    {
        $config = $this->baseConfig();
        $strategy = new DbHashRangeStrategy();
        $triggered = false;

        ShardSlot::flushEventListeners();
        ShardSlot::saving(function (ShardSlot $slot) use (&$triggered): void {
            if ($triggered) {
                return;
            }

            $triggered = true;
            DB::table('shard_slots')->insert([
                'table' => $slot->getAttribute('table'),
                'slot' => $slot->getAttribute('slot'),
                'connection' => 'shard1',
                'replicas' => json_encode([]),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        });

        try {
            $strategy->rowMoved(44, 'shard2', $config);
        } finally {
            ShardSlot::flushEventListeners();
        }

        $hash = (int) sprintf('%u', crc32('44'));
        $slotId = intdiv($hash, $config['slot_size']);
        $slot = ShardSlot::on('sqlite')->where('table', 'users')->where('slot', $slotId)->firstOrFail();
        $this->assertSame('shard2', $slot->connection);
        $this->assertSame(['shard3'], $slot->replicas);

        DB::table('shard_slots')->where('table', 'users')->where('slot', $slotId)->update([
            'connection' => 'shard2',
            'replicas' => json_encode(['shard1']),
            'updated_at' => now(),
        ]);

        $strategy->rowMoved(44, 'shard1', $config);

        $slot = ShardSlot::on('sqlite')->where('table', 'users')->where('slot', $slotId)->firstOrFail();
        $this->assertSame('shard1', $slot->connection);
        $this->assertSame(['shard2'], $slot->replicas);
        $this->assertSame(1, ShardSlot::count());
    }

    /**
     * A slot is a hash of the key, so keys inside a range share slots with keys
     * outside it; redirecting a slot after moving only part of it strands the
     * rest. Found in review.
     */
    public function testABoundedMoveToAnExplicitTargetIsRefused(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/routes by slot/');

        (new DbHashRangeStrategy())->rebalance([new ShardedTable('users', 'id', 'id')], null, 'shard2', 1, 10, $this->baseConfig());
    }

    public function testABoundedMoveByTheRoutingIsNotRefusedUpFront(): void
    {
        $strategy = new class () extends DbHashRangeStrategy {
            public bool $reached = false;

            protected function scannable(\Allnetru\Sharding\ShardingManager $manager, string $table): array
            {
                $this->reached = true;

                return [];
            }
        };

        $strategy->rebalance([new ShardedTable('users', 'id', 'id')], null, null, 1, 10, $this->baseConfig());
        $strategy->rebalance([new ShardedTable('users', 'id', 'id')], null, 'shard2', null, null, $this->baseConfig());

        $this->assertTrue($strategy->reached);
    }

    private function baseConfig(): array
    {
        return [
            'slot_size' => 1000,
            'meta_connection' => 'sqlite',
            'slot_table' => 'shard_slots',
            'table' => 'users',
            'connections' => [
                'shard1' => ['weight' => 1],
                'shard2' => ['weight' => 1],
                'shard3' => ['weight' => 1],
            ],
            'replica_count' => 1,
        ];
    }
}
