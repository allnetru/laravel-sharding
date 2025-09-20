<?php

namespace Allnetru\Sharding\Tests\Unit;

use Allnetru\Sharding\Models\ShardSlot;
use Allnetru\Sharding\Strategies\DbHashRangeStrategy;
use Allnetru\Sharding\Strategies\HashStrategy;
use Illuminate\Support\Facades\DB;
use Allnetru\Sharding\Tests\TestCase;

class DbHashRangeStrategyTest extends TestCase
{
    public function test_large_key_distribution(): void
    {
        $config = [
            'slot_size' => 1000,
            'meta_connection' => 'sqlite',
            'slot_table' => 'shard_slots',
            'table' => 'users',
            'connections' => [
                'shard1' => ['weight' => 1],
                'shard2' => ['weight' => 1],
            ],
            'replica_count' => 1,
        ];

        $schema = DB::connection('sqlite')->getSchemaBuilder();
        $schema->dropIfExists('shard_slots');
        $schema->create('shard_slots', function ($table) {
            $table->string('table');
            $table->unsignedBigInteger('slot');
            $table->string('connection');
            $table->json('replicas')->nullable();
            $table->timestamps();
        });

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
}
