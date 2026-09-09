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
 * A row lives on the shard its own key names.
 *
 * The package's whole promise, and until these tests existed nothing checked
 * it: every one of them failed before the placement moved out of the
 * `creating` hook. `Model::save()` builds its query and only then fires that
 * hook, so the connection it chose was always too late — the statement had
 * already been aimed.
 *
 * Two paths made it visible. `Model::create()` copies the connection of the
 * blank model its builder was made from, and that model had been routed by a
 * generated throwaway key. And a model whose key is a snowflake had no key at
 * query-build time at all.
 *
 * **Nothing failed while it was broken**, which is why it lasted: every read
 * fans out across all shards and merges, so a row on the wrong one is found
 * anyway. It surfaces the moment anything trusts the key — a read pinned to
 * one shard, `shards:distribute` deciding a row is already in place, a
 * rebalance moving it by a slot it does not match.
 *
 * The assertions here are deliberately about **where the bytes are**, read
 * through a plain connection rather than through the model: asking the model
 * would ask the same code that decides the answer.
 */
class ShardPlacementTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config([
            'database.connections.shard_1' => ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => ''],
            'database.connections.shard_2' => ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => ''],
            'sharding.connections' => ['shard_1' => ['weight' => 1], 'shard_2' => ['weight' => 1]],
            'sharding.tables' => [
                'placed' => ['strategy' => 'hash', 'replica_count' => 0],
                'placed_colo' => ['strategy' => 'hash', 'replica_count' => 0],
            ],
        ]);

        app()->singleton(ShardingManager::class, fn () => new ShardingManager(config('sharding')));

        foreach (['shard_1', 'shard_2'] as $connection) {
            Schema::connection($connection)->create('placed', function (Blueprint $table): void {
                $table->unsignedBigInteger('id')->primary();
                $table->integer('value');
                $table->boolean('is_replica')->default(false);
            });

            Schema::connection($connection)->create('placed_colo', function (Blueprint $table): void {
                $table->unsignedBigInteger('id')->primary();
                $table->unsignedBigInteger('tenant_id');
                $table->boolean('is_replica')->default(false);
            });
        }
    }

    /**
     * A key given by hand decides where the row goes, through `create()`.
     *
     * @return void
     */
    public function testCreateWithAGivenKeyLandsOnThatKeysShard(): void
    {
        foreach ([1, 2, 3, 4] as $id) {
            $row = PlacedRow::create(['id' => $id, 'value' => $id]);

            $this->assertPlacedByItsKey(PlacedRow::class, 'placed', $id, (int) $row->id);
        }
    }

    /**
     * A generated key decides too, and this is the common case.
     *
     * Nothing in an application sets a snowflake by hand; the package
     * generates it. Before the fix the query had already been built by then,
     * so the row went wherever the instance happened to point.
     *
     * @return void
     */
    public function testCreateWithAGeneratedKeyLandsOnThatKeysShard(): void
    {
        for ($i = 0; $i < 8; $i++) {
            $row = PlacedRow::create(['value' => $i]);

            $this->assertNotNull($row->id, 'no key was generated');
            $this->assertPlacedByItsKey(PlacedRow::class, 'placed', (int) $row->id, (int) $row->id);
        }
    }

    /**
     * A colocated row goes to its group's shard, not to its own identifier's.
     *
     * The case an application depends on most: a table sharded by `tenant_id`
     * has to sit with everything else of that tenant, or colocation buys
     * nothing and a join inside one shard finds half its rows.
     *
     * @return void
     */
    public function testAColocatedRowLandsWithItsGroup(): void
    {
        foreach ([1, 2, 3, 4] as $tenantId) {
            $row = PlacedColoRow::create(['tenant_id' => $tenantId]);

            $this->assertPlacedByItsKey(PlacedColoRow::class, 'placed_colo', $tenantId, (int) $row->id);
        }
    }

    /**
     * Saving a model built by hand places it the same way.
     *
     * This path was already right, and the test is here so it stays right:
     * the placement moved to a different seam, and a seam that fixes one path
     * by breaking another is not a fix.
     *
     * @return void
     */
    public function testSavingAModelBuiltByHandPlacesItToo(): void
    {
        foreach ([11, 12, 13] as $id) {
            $row = new PlacedRow();
            $row->id = $id;
            $row->value = $id;
            $row->save();

            $this->assertPlacedByItsKey(PlacedRow::class, 'placed', $id, $id);
        }
    }

    /**
     * The row with this identifier sits on the shard its key names.
     *
     * @param class-string<Model> $model The model.
     * @param string $table Its table.
     * @param int $key The shard key value.
     * @param int $id The row's identifier.
     *
     * @return void
     */
    protected function assertPlacedByItsKey(string $model, string $table, int $key, int $id): void
    {
        $expected = app(ShardingManager::class)->connectionFor(new $model(), $key)[0] ?? '';

        $this->assertNotSame('', $expected, 'the strategy named no connection');

        $found = [];

        foreach (['shard_1', 'shard_2'] as $connection) {
            if (DB::connection($connection)->table($table)->where('id', $id)->exists()) {
                $found[] = $connection;
            }
        }

        $this->assertSame(
            [$expected],
            $found,
            sprintf('the key %d names %s, and the row is on %s', $key, $expected, implode(',', $found) ?: 'nothing'),
        );
    }
}

class PlacedRow extends Model
{
    use Shardable;

    protected $table = 'placed';

    public $incrementing = false;

    public $timestamps = false;

    protected $guarded = [];

    protected $casts = ['is_replica' => 'bool'];
}

class PlacedColoRow extends Model
{
    use Shardable;

    protected $table = 'placed_colo';

    protected string $shardKey = 'tenant_id';

    public $incrementing = false;

    public $timestamps = false;

    protected $guarded = [];

    protected $casts = ['is_replica' => 'bool'];
}
