<?php

namespace Allnetru\Sharding\Tests\Unit;

use Allnetru\Sharding\Models\Concerns\Shardable;
use Allnetru\Sharding\IdGenerator;
use Allnetru\Sharding\ShardingManager;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Allnetru\Sharding\Tests\TestCase;

class ShardableTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config([
            'database.connections.shard_1' => [
                'driver' => 'sqlite',
                'database' => ':memory:',
                'prefix' => '',
            ],
            'database.connections.shard_2' => [
                'driver' => 'sqlite',
                'database' => ':memory:',
                'prefix' => '',
            ],
            'sharding.connections' => [
                'shard_1' => ['weight' => 1],
                'shard_2' => ['weight' => 1],
            ],
        ]);

        app()->singleton(ShardingManager::class, fn () => new ShardingManager(config('sharding')));
        app()->singleton(IdGenerator::class, fn () => new class() {
            private int $id = 0;

            public function generate($model): int
            {
                return ++$this->id;
            }
        });

        foreach (['shard_1', 'shard_2'] as $connection) {
            Schema::connection($connection)->create('items', function (Blueprint $table): void {
                $table->unsignedBigInteger('id')->primary();
                $table->integer('value');
                $table->boolean('is_replica')->default(false);
            });
        }
    }

    public function testGetConnectionNameAssignsIdAndConnections(): void
    {
        $item = new ShardableItem(['value' => 1]);

        $this->assertNull($item->id);

        $connection = $item->getConnectionName();

        $this->assertNotNull($item->id);

        $expected = app(ShardingManager::class)->connectionFor($item, $item->id);
        $this->assertSame($expected[0], $connection);
        $this->assertSame($expected[0], $item->getConnectionName());
        $this->assertSame(array_slice($expected, 1), $item->replicaConnections);
    }

    public function testSavingDistributesDataAcrossShards(): void
    {
        $manager = app(ShardingManager::class);
        $counts = ['shard_1' => 0, 'shard_2' => 0];

        foreach (range(1, 20) as $value) {
            $item = new ShardableItem(['value' => $value]);
            $item->save();

            $expected = $manager->connectionFor($item, $item->id)[0];
            $counts[$expected]++;

            $this->assertSame($expected, $item->getConnectionName());
            $this->assertDatabaseHas('items', ['id' => $item->id, 'value' => $value], $expected);
        }

        $this->assertSame($counts['shard_1'], DB::connection('shard_1')->table('items')->where('is_replica', false)->count());
        $this->assertSame($counts['shard_2'], DB::connection('shard_2')->table('items')->where('is_replica', false)->count());
        $this->assertGreaterThan(0, $counts['shard_1']);
        $this->assertGreaterThan(0, $counts['shard_2']);
        $this->assertLessThanOrEqual(6, abs($counts['shard_1'] - $counts['shard_2']));
    }

    public function testReplicasAreSavedAndMarked(): void
    {
        config()->set('sharding.replica_count', 1);
        app()->instance(ShardingManager::class, new ShardingManager(config('sharding')));

        $item = new ShardableItem(['value' => 42]);
        $item->save();

        $connections = app(ShardingManager::class)->connectionFor($item, $item->id);
        $primary = $connections[0];
        $replica = $connections[1];

        $this->assertDatabaseHas('items', ['id' => $item->id, 'is_replica' => false], $primary);
        $this->assertDatabaseHas('items', ['id' => $item->id, 'is_replica' => true], $replica);
    }
}

class ShardableItem extends Model
{
    use Shardable;

    protected $table = 'items';

    public $timestamps = false;

    protected $guarded = [];

    protected $casts = [
        'is_replica' => 'bool',
    ];
}
