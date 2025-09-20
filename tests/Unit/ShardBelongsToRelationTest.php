<?php

namespace Allnetru\Sharding\Tests\Unit;

use Allnetru\Sharding\IdGenerator;
use Allnetru\Sharding\Models\Concerns\Shardable;
use Allnetru\Sharding\ShardingManager;
use Allnetru\Sharding\Tests\TestCase;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class ShardBelongsToRelationTest extends TestCase
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
            Schema::connection($connection)->create('organizations', function (Blueprint $table): void {
                $table->unsignedBigInteger('id')->primary();
                $table->boolean('is_replica')->default(false);
            });

            Schema::connection($connection)->create('users', function (Blueprint $table): void {
                $table->unsignedBigInteger('id')->primary();
                $table->unsignedBigInteger('organization_id')->nullable();
                $table->boolean('is_replica')->default(false);
            });
        }
    }

    public function test_belongs_to_loads_across_shards(): void
    {
        $organization = new TestOrganization(['id' => 1]);
        $organization->save();

        $user = new TestUser(['id' => 4, 'organization_id' => $organization->id]);
        $user->save();

        $this->assertNotSame($user->getConnectionName(), $organization->getConnectionName());
        $this->assertSame($organization->id, $user->organization->id);
        $this->assertSame(
            $organization->getConnectionName(),
            $user->organization->getConnectionName()
        );
    }

    public function test_belongs_to_returns_null_when_foreign_key_is_missing(): void
    {
        $user = new TestUser(['id' => 5, 'organization_id' => null]);
        $user->save();

        $this->assertNull($user->organization);
    }
}

class TestOrganization extends Model
{
    use Shardable;

    protected $table = 'organizations';

    public $timestamps = false;

    protected $guarded = [];

    protected $casts = [
        'is_replica' => 'bool',
    ];
}

class TestUser extends Model
{
    use Shardable;

    protected $table = 'users';

    public $timestamps = false;

    protected $guarded = [];

    protected $casts = [
        'is_replica' => 'bool',
    ];

    public function organization()
    {
        return $this->belongsTo(TestOrganization::class, 'organization_id');
    }
}
