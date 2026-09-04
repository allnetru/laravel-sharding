<?php

namespace Allnetru\Sharding\Tests\Unit;

use Allnetru\Sharding\IdGenerator;
use Allnetru\Sharding\Models\Concerns\Shardable;
use Allnetru\Sharding\ShardingManager;
use Allnetru\Sharding\Tests\TestCase;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Colocation by a column other than the primary key.
 *
 * This is the shape the README documents for grouping related tables: the
 * child declares `$shardKey` so it lands on the shard chosen for its parent.
 * Before the fix the `creating` hook filled only the shard key, the primary
 * key stayed null and the insert failed, so the documented shape did not work
 * at all.
 */
class ShardableCustomShardKeyTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config([
            'database.connections.shard_a' => [
                'driver' => 'sqlite',
                'database' => ':memory:',
                'prefix' => '',
            ],
            'sharding.connections' => [
                'shard_a' => ['weight' => 1],
            ],
            'sharding.default' => 'hash',
            'sharding.tables' => [],
        ]);

        app()->singleton(ShardingManager::class, fn () => new ShardingManager(config('sharding')));

        Schema::connection('shard_a')->create('parcels', function (Blueprint $table): void {
            $table->unsignedBigInteger('id')->primary();
            $table->unsignedBigInteger('tenant_id');
            $table->string('plot_number');
            $table->boolean('is_replica')->default(false);
        });
    }

    public function testPrimaryKeyIsGeneratedWhenShardKeyIsAnotherColumn(): void
    {
        $model = new ParcelWithTenantShardKey();
        $model->tenant_id = 4242;
        $model->plot_number = '105';
        $model->save();

        $this->assertNotNull($model->getKey(), 'Primary key was not generated.');
        $this->assertGreaterThan(0, $model->getKey());
        $this->assertSame(4242, $model->tenant_id, 'Shard key must be preserved as given.');
    }

    public function testRowIsActuallyPersistedAndReadableBack(): void
    {
        $model = new ParcelWithTenantShardKey();
        $model->tenant_id = 77;
        $model->plot_number = '12a';
        $model->save();

        $found = ParcelWithTenantShardKey::on($model->getConnectionName())
            ->where('id', $model->getKey())
            ->first();

        $this->assertNotNull($found);
        $this->assertSame('12a', $found->plot_number);
        $this->assertSame(77, $found->tenant_id);
    }

    public function testTwoRowsOfTheSameTenantGetDistinctPrimaryKeys(): void
    {
        $keys = [];

        for ($i = 0; $i < 50; $i++) {
            $model = new ParcelWithTenantShardKey();
            $model->tenant_id = 9;
            $model->plot_number = (string) $i;
            $model->save();

            $keys[] = $model->getKey();
        }

        $this->assertCount(50, array_unique($keys));
    }

    public function testExplicitPrimaryKeyIsNotOverwritten(): void
    {
        $model = new ParcelWithTenantShardKey();
        $model->id = 555000111;
        $model->tenant_id = 3;
        $model->plot_number = '7';
        $model->save();

        $this->assertSame(555000111, $model->getKey());
    }

    public function testGeneratorIsNotCalledForAutoIncrementingKeys(): void
    {
        Schema::connection('shard_a')->create('notes', function (Blueprint $table): void {
            $table->increments('id');
            $table->unsignedBigInteger('tenant_id');
            $table->boolean('is_replica')->default(false);
        });

        $calls = 0;
        app()->instance(IdGenerator::class, new class($calls) extends IdGenerator {
            public function __construct(public int &$calls)
            {
                parent::__construct(config('sharding'));
            }

            public function generate(Model|string $model): int
            {
                $this->calls++;

                return random_int(1, PHP_INT_MAX);
            }
        });

        // Ключ шардирования не задан, поэтому генератор вызывается на него.
        // Первичный ключ автоинкрементный, поэтому на него не должен: всего
        // ожидается ровно один вызов.
        $model = new NoteWithAutoIncrement();
        $model->save();

        $this->assertSame(1, $calls);
        $this->assertNotNull($model->tenant_id);
    }
}

class ParcelWithTenantShardKey extends Model
{
    use Shardable;

    public $incrementing = false;

    public $timestamps = false;

    protected $table = 'parcels';

    protected string $shardKey = 'tenant_id';

    protected $guarded = [];

    protected $casts = [
        'tenant_id' => 'int',
        'is_replica' => 'bool',
    ];
}

class NoteWithAutoIncrement extends Model
{
    use Shardable;

    public $timestamps = false;

    protected $table = 'notes';

    protected string $shardKey = 'tenant_id';

    protected $guarded = [];
}
