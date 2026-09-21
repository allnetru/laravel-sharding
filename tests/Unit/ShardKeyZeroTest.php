<?php

namespace Allnetru\Sharding\Tests\Unit;

use Allnetru\Sharding\Models\Concerns\Shardable;
use Allnetru\Sharding\ShardingManager;
use Allnetru\Sharding\Tests\TestCase;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Zero is a shard key like any other, and null is the absent one.
 *
 * The two used to be told apart with `!$key`, which is true for both. A table
 * whose key column has a meaningful zero — a platform-wide row standing beside
 * per-tenant ones — therefore had an identifier invented for it on every
 * insert.
 *
 * That failure is silent and it compounds: the row lands on a shard nothing
 * will look for it on, and the next write of the same logical row invents a
 * different key again, so a table meant to hold one row per subject grows a
 * copy per save. `transaction()` already made the distinction correctly; this
 * is the other two places making it too.
 */
class ShardKeyZeroTest extends TestCase
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
            Schema::connection($connection)->create('notes', function (Blueprint $table): void {
                $table->unsignedBigInteger('id')->primary();
                $table->unsignedBigInteger('tenant_id');
                $table->string('body');
                $table->boolean('is_replica')->default(false);
            });
        }
    }

    /**
     * A row keyed by zero keeps the zero.
     *
     * @return void
     */
    public function testZeroIsKeptAsTheShardKey(): void
    {
        $note = new ZeroKeyedNote(['tenant_id' => 0, 'body' => 'first']);
        $note->save();

        $this->assertSame(0, (int) $note->tenant_id);
    }

    /**
     * Two writes of one key land on one shard and stay one row.
     *
     * @return void
     */
    public function testZeroRoutesConsistently(): void
    {
        $first = new ZeroKeyedNote(['tenant_id' => 0, 'body' => 'first']);
        $first->save();

        $second = new ZeroKeyedNote(['tenant_id' => 0, 'body' => 'second']);
        $second->save();

        $this->assertSame($first->getConnectionName(), $second->getConnectionName());
        $this->assertSame(0, (int) $second->tenant_id);
    }

    /**
     * An empty string is a missing key, not a value.
     *
     * The one case this differs from «null only» on, and the reason it does:
     * `''` reaches the strategies as a key nothing refuses — `crc32('')` is 0,
     * so every row carrying one would pile onto the first shard rather than
     * fail. `transaction()` has drawn the line here since it was written.
     *
     * @return void
     */
    public function testAnEmptyStringIsTreatedAsMissing(): void
    {
        $note = new ZeroKeyedNote(['tenant_id' => '', 'body' => 'empty']);
        $note->save();

        $this->assertNotSame('', $note->tenant_id);
        $this->assertNotNull($note->tenant_id);
    }

    /**
     * Zero written as a string is still zero, not an absent key.
     *
     * The regression this guards against is the obvious over-correction: a
     * check that swept up «falsy strings» would take `'0'` with it, and a key
     * arriving from a form or a driver as text is the ordinary case.
     *
     * @return void
     */
    public function testZeroAsAStringIsKept(): void
    {
        $note = new ZeroKeyedNote(['tenant_id' => '0', 'body' => 'zero as text']);
        $note->save();

        $this->assertSame(0, (int) $note->tenant_id);
    }

    /**
     * A missing key is still filled in.
     *
     * The other half of the distinction: null means nobody said, and the
     * package's answer to that is unchanged.
     *
     * @return void
     */
    public function testNullIsStillGivenAKey(): void
    {
        $note = new ZeroKeyedNote(['body' => 'without a key']);
        $note->save();

        $this->assertNotNull($note->tenant_id);
        $this->assertNotSame(0, (int) $note->tenant_id);
    }
}

/**
 * A row keyed by a column where zero is a real value.
 */
class ZeroKeyedNote extends Model
{
    use Shardable;

    public $incrementing = false;

    protected $table = 'notes';

    protected string $shardKey = 'tenant_id';

    protected $fillable = ['id', 'tenant_id', 'body'];

    public $timestamps = false;
}
