<?php

namespace Allnetru\Sharding\Tests\Unit;

use Allnetru\Sharding\IdGenerator;
use Allnetru\Sharding\Models\Concerns\Shardable;
use Allnetru\Sharding\Relations\ShardBelongsTo;
use Allnetru\Sharding\ShardBuilder;
use Allnetru\Sharding\ShardingManager;
use Allnetru\Sharding\Tests\TestCase;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class ShardableTest extends TestCase
{
    private CountingGenerator $generator;

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
        $this->generator = new CountingGenerator();
        app()->instance(IdGenerator::class, $this->generator);

        foreach (['shard_1', 'shard_2'] as $connection) {
            Schema::connection($connection)->create('items', function (Blueprint $table): void {
                $table->unsignedBigInteger('id')->primary();
                $table->integer('value');
                $table->boolean('is_replica')->default(false);
            });

            Schema::connection($connection)->create('organizations', function (Blueprint $table): void {
                $table->unsignedBigInteger('id')->primary();
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
        $this->assertSame(1, $this->generator->calls);
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

    public function testScopeWithoutReplicasFiltersResults(): void
    {
        DB::connection('shard_1')->table('items')->insert([
            ['id' => 1, 'value' => 10, 'is_replica' => false],
            ['id' => 2, 'value' => 11, 'is_replica' => true],
        ]);

        $results = ShardableItem::withoutReplicas()->get()->pluck('id')->all();

        $this->assertSame([1], $results);
    }

    public function testReplicaCreationDoesNotTriggerGenerator(): void
    {
        $item = new ShardableItem(['id' => 100, 'value' => 5, 'is_replica' => true]);
        $item->save();

        $this->assertSame(0, $this->generator->calls);
        $this->assertTrue($item->is_replica);
        $this->assertSame([], $item->replicaConnections);
    }

    public function testGetShardKeyRespectsCustomProperty(): void
    {
        $model = new CustomShardKeyModel(['tenant_id' => 5]);

        $this->assertSame('tenant_id', $model->getShardKey());
    }

    public function testBelongsToReturnsShardBelongsToRelation(): void
    {
        $organization = new ShardableTestOrganization(['id' => 5]);
        $organization->save();

        $user = new ShardableTestUser(['value' => 1]);
        $relation = $user->belongsTo(ShardableTestOrganization::class);

        $this->assertInstanceOf(ShardBelongsTo::class, $relation);
    }

    public function testNewEloquentBuilderReturnsShardBuilder(): void
    {
        $builder = (new ShardableItem())->newQuery();

        $this->assertInstanceOf(ShardBuilder::class, $builder);
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

class CustomShardKeyModel extends Model
{
    use Shardable;

    protected $table = 'items';

    protected string $shardKey = 'tenant_id';

    public $timestamps = false;

    protected $guarded = [];

    protected $casts = [
        'is_replica' => 'bool',
    ];
}

class ShardableTestOrganization extends Model
{
    use Shardable;

    protected $table = 'organizations';

    public $timestamps = false;

    protected $guarded = [];

    protected $casts = [
        'is_replica' => 'bool',
    ];
}

class ShardableTestUser extends Model
{
    use Shardable;

    protected $table = 'items';

    public $timestamps = false;

    protected $guarded = [];

    protected $casts = [
        'is_replica' => 'bool',
    ];
}

class CountingGenerator
{
    public int $calls = 0;

    private int $id = 0;

    public function generate($model): int
    {
        $this->calls++;

        return ++$this->id;
    }
}
