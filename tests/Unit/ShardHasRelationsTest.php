<?php

namespace Allnetru\Sharding\Tests\Unit;

use Allnetru\Sharding\IdGenerator;
use Allnetru\Sharding\Models\Concerns\Shardable;
use Allnetru\Sharding\ShardingManager;
use Allnetru\Sharding\Tests\TestCase;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class ShardHasRelationsTest extends TestCase
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
                $table->unsignedBigInteger('organization_id');
                $table->boolean('is_replica')->default(false);
            });

            Schema::connection($connection)->create('profiles', function (Blueprint $table): void {
                $table->unsignedBigInteger('id')->primary();
                $table->unsignedBigInteger('user_id');
                $table->boolean('is_replica')->default(false);
            });
        }
    }

    public function testHasManyLoadsAcrossShards(): void
    {
        config(['sharding.tables.organizations.connections' => ['shard_1' => ['weight' => 1]]]);

        $organization = new HasTestOrganization(['id' => 10]);
        $organization->save();

        $user = new HasTestUser(['id' => 1, 'organization_id' => $organization->id]);
        $user->save();

        $this->assertSame('shard_1', $organization->getConnectionName());
        $this->assertSame('shard_2', $user->getConnectionName());

        $users = $organization->users;
        $this->assertCount(1, $users);
        $this->assertSame($user->id, $users->first()->id);
        $this->assertSame('shard_2', $users->first()->getConnectionName());
    }

    public function testHasOneLoadsAcrossShards(): void
    {
        config(['sharding.tables.users.connections' => ['shard_1' => ['weight' => 1]]]);

        $user = new HasTestUser(['id' => 10, 'organization_id' => 1]);
        $user->save();

        $profile = new HasTestProfile(['id' => 1, 'user_id' => $user->id]);
        $profile->save();

        $this->assertSame('shard_1', $user->getConnectionName());
        $this->assertSame('shard_2', $profile->getConnectionName());
        $this->assertSame($profile->id, $user->profile->id);
        $this->assertSame('shard_2', $user->profile->getConnectionName());
    }
}

class HasTestOrganization extends Model
{
    use Shardable;

    protected $table = 'organizations';

    public $timestamps = false;

    protected $guarded = [];

    protected $casts = [
        'is_replica' => 'bool',
    ];

    public function users()
    {
        return $this->hasMany(HasTestUser::class, 'organization_id');
    }
}

class HasTestUser extends Model
{
    use Shardable;

    protected $table = 'users';

    public $timestamps = false;

    protected $guarded = [];

    protected $casts = [
        'is_replica' => 'bool',
    ];

    public function profile()
    {
        return $this->hasOne(HasTestProfile::class, 'user_id');
    }
}

class HasTestProfile extends Model
{
    use Shardable;

    protected $table = 'profiles';

    public $timestamps = false;

    protected $guarded = [];

    protected $casts = [
        'is_replica' => 'bool',
    ];
}
