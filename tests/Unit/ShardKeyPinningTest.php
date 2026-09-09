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
 * Reading only the shards a query's own key can be on.
 *
 * Until this existed the builder never looked at a query: a read that named
 * its shard key exactly still opened a cursor on every shard and merged the
 * results. Correct, and N times the work — which made «a query has to know its
 * key» a rule that bought the shape of the schema and nothing else.
 *
 * **The soundness argument is one sentence,** and every test here is a face of
 * it: with no `or` at the top level the predicate is a conjunction, and a
 * conjunction containing `key = value` can only match rows whose key is that
 * value. Anything else ANDed beside it narrows the result further and can
 * never add a row from another shard.
 *
 * The failure mode this guards against is the expensive one: a query pinned
 * when it should have fanned out does not answer slowly, it answers
 * **incompletely**, with no error. So the tests below check both directions —
 * that the right shard is asked, and that the wrong query is left alone.
 */
class ShardKeyPinningTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config([
            'database.connections.shard_1' => ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => ''],
            'database.connections.shard_2' => ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => ''],
            'sharding.connections' => [
                'shard_1' => ['weight' => 1],
                'shard_2' => ['weight' => 1],
            ],
            // deterministic and needing no meta tables, so the test asserts
            // the builder's decision rather than a slot table's contents
            'sharding.tables' => [
                // hash, so the mapping is deterministic and needs no meta
                // tables: the test asserts the builder's decision rather than
                // the contents of a slot table.
                //
                // No replicas, and that is not tidiness: with two connections
                // and one replica the copy lands on the connection that is
                // some other row's primary, and the fixture collides with
                // itself on the primary key.
                'notes' => ['strategy' => 'hash', 'replica_count' => 0],
                'lines' => ['strategy' => 'hash', 'replica_count' => 0],
            ],
        ]);

        app()->singleton(ShardingManager::class, fn () => new ShardingManager(config('sharding')));

        foreach (['shard_1', 'shard_2'] as $connection) {
            Schema::connection($connection)->create('notes', function (Blueprint $table): void {
                $table->unsignedBigInteger('id')->primary();
                $table->integer('value');
                $table->boolean('is_replica')->default(false);
            });

            // a second table with a column of the same name, for the join
            // that must not be mistaken for our own key
            Schema::connection($connection)->create('others', function (Blueprint $table): void {
                $table->unsignedBigInteger('id')->primary();
            });

            Schema::connection($connection)->create('lines', function (Blueprint $table): void {
                $table->unsignedBigInteger('id')->primary();
                $table->unsignedBigInteger('tenant_id');
                $table->integer('value');
                $table->boolean('is_replica')->default(false);
            });
        }
    }

    /**
     * A read that names its key asks that shard and no other.
     *
     * @return void
     */
    public function testAKeyedReadAsksOnlyItsOwnShard(): void
    {
        $this->seedNotes();

        [$mine, $theirs] = $this->shardsFor(PinNote::class, 1);

        $this->watch();
        $found = PinNote::query()->where('id', 1)->first();

        $this->assertNotNull($found);
        $this->assertSame(1, (int) $found->id);
        $this->assertGreaterThan(0, $this->queriesOn($mine), 'its own shard was not asked');
        $this->assertSame(0, $this->queriesOn($theirs), 'the other shard was asked anyway');
    }

    /**
     * An `or` at the top level is not a conjunction, so nothing is pinned.
     *
     * The row on the second shard is what makes this test worth having: pin
     * here and the query answers with half its rows and no error.
     *
     * @return void
     */
    public function testATopLevelOrStillAsksEveryShard(): void
    {
        $this->seedNotes();

        $this->watch();
        $found = PinNote::query()->where('id', 1)->orWhere('id', 2)->get();

        $this->assertCount(2, $found);
        $this->assertGreaterThan(0, $this->queriesOn('shard_1'));
        $this->assertGreaterThan(0, $this->queriesOn('shard_2'));
    }

    /**
     * An `or` **inside** an ANDed group is still a conjunction outside it.
     *
     * The case that would be lost by refusing anything with an `or` in it, and
     * it is the common one: a keyed read with a group of alternatives on some
     * other column. `id = 1 AND (value = 10 OR value = 20)` cannot match a row
     * whose id is not 1, wherever it lives.
     *
     * @return void
     */
    public function testAnOrNestedInsideAnAndDoesNotPreventPinning(): void
    {
        $this->seedNotes();

        [$mine, $theirs] = $this->shardsFor(PinNote::class, 1);

        $this->watch();
        $found = PinNote::query()
            ->where('id', 1)
            ->where(function ($query): void {
                $query->where('value', 10)->orWhere('value', 20);
            })
            ->first();

        $this->assertNotNull($found);
        $this->assertSame(0, $this->queriesOn($theirs));
        $this->assertGreaterThan(0, $this->queriesOn($mine));
    }

    /**
     * A `whereIn` on the key asks the shards it names and no others.
     *
     * @return void
     */
    public function testAWhereInAsksOnlyTheShardsItNames(): void
    {
        $this->seedNotes();

        // both identifiers deliberately resolved to the same shard, so «only
        // the shards it names» is a statement with something to exclude
        $ids = $this->idsOnOneShard(PinNote::class, 2);

        [$mine, $theirs] = $this->shardsFor(PinNote::class, $ids[0]);

        $this->watch();
        $found = PinNote::query()->whereIn('id', $ids)->get();

        $this->assertCount(2, $found);
        $this->assertGreaterThan(0, $this->queriesOn($mine));
        $this->assertSame(0, $this->queriesOn($theirs));
    }

    /**
     * A query that names no key reads everything, as it always did.
     *
     * @return void
     */
    public function testAQueryWithoutItsKeyStillFansOut(): void
    {
        $this->seedNotes();

        $this->watch();
        $found = PinNote::query()->where('value', '>', 0)->get();

        $this->assertCount(4, $found);
        $this->assertGreaterThan(0, $this->queriesOn('shard_1'));
        $this->assertGreaterThan(0, $this->queriesOn('shard_2'));
    }

    /**
     * A colocated table is pinned by its group's key, not by its own id.
     *
     * The case the whole feature exists for in an application: a child table
     * sharded by `tenant_id` is read as «this tenant's rows», and that is a
     * key it names on every single query.
     *
     * @return void
     */
    public function testAColocatedTableIsPinnedByItsGroupKey(): void
    {
        foreach ([1, 2] as $tenantId) {
            foreach ([1, 2] as $n) {
                PinLine::create(['id' => $tenantId * 10 + $n, 'tenant_id' => $tenantId, 'value' => $n]);
            }
        }

        [$mine, $theirs] = $this->shardsFor(PinLine::class, 1);

        $this->watch();
        $found = PinLine::query()->where('tenant_id', 1)->get();

        $this->assertCount(2, $found);
        $this->assertGreaterThan(0, $this->queriesOn($mine));
        $this->assertSame(0, $this->queriesOn($theirs));
    }

    /**
     * A column of another table is not our shard key, however it is named.
     *
     * A join can bring a table with a column of the same name, and reading its
     * value as our key would pin the query to the wrong shard — the silent
     * failure this whole guard exists to avoid.
     *
     * @return void
     */
    public function testAQualifiedColumnOfAnotherTableDoesNotPin(): void
    {
        $this->seedNotes();

        foreach (['shard_1', 'shard_2'] as $connection) {
            DB::connection($connection)->table('others')->insert(['id' => 1]);
        }

        $this->watch();

        // a join brings a table whose `id` is not our shard key. Reading its
        // value as one would pin the query to whichever shard that number
        // happens to hash to, and the rows would be looked for in the wrong
        // place — with no error to show for it
        PinNote::query()
            ->join('others', 'others.id', '=', 'notes.id')
            ->where('others.id', 1)
            ->get();

        $this->assertGreaterThan(0, $this->queriesOn('shard_1'));
        $this->assertGreaterThan(0, $this->queriesOn('shard_2'));
    }

    /**
     * A keyed delete touches one shard.
     *
     * Writes narrow for the same reason reads do, and it matters more here: a
     * fanned-out delete runs a statement on every shard, and until the fan-out
     * was fixed it reported a count from one of them.
     *
     * @return void
     */
    public function testAKeyedDeleteTouchesOneShard(): void
    {
        $this->seedNotes();

        [$mine, $theirs] = $this->shardsFor(PinNote::class, 1);

        $this->watch();
        $deleted = PinNote::query()->where('id', 1)->delete();

        $this->assertSame(1, $deleted);
        $this->assertGreaterThan(0, $this->queriesOn($mine));
        $this->assertSame(0, $this->queriesOn($theirs));
    }

    /**
     * The switch turns it off, for the one window that needs the fan-out.
     *
     * `shards:rebalance` moves rows and updates slots without atomicity
     * between the two, so for the length of a move a row can sit on one
     * connection while its slot names another. A fan-out finds it either way.
     *
     * @return void
     */
    public function testTheSwitchTurnsPinningOff(): void
    {
        $this->seedNotes();

        config(['sharding.pin_by_key' => false]);

        $this->watch();
        PinNote::query()->where('id', 1)->first();

        $this->assertGreaterThan(0, $this->queriesOn('shard_1'));
        $this->assertGreaterThan(0, $this->queriesOn('shard_2'));
    }

    /**
     * A negated equality on the key is not an equality on the key.
     *
     * The clause that reads most like the one it is safe to trust, and the
     * most dangerous: Laravel writes `whereNot('id', 1)` as an ordinary
     * `Basic` equality whose boolean is «and not». Read as `id = 1` it pins
     * the query to that key's shard, while the predicate it runs matches every
     * **other** identifier — all of which live elsewhere. Found in review of
     * the change that introduced pinning.
     *
     * @return void
     */
    public function testANegatedKeyStillAsksEveryShard(): void
    {
        $this->seedNotes();

        $this->watch();
        $found = PinNote::query()->whereNot('id', 1)->get();

        $this->assertCount(3, $found, 'rows on other shards were lost');
        $this->assertGreaterThan(0, $this->queriesOn('shard_1'));
        $this->assertGreaterThan(0, $this->queriesOn('shard_2'));
    }

    /**
     * A union is a second predicate, and it is not read here.
     *
     * `where('id', 1)->union(where('id', 2))` pinned to the first key's shard
     * would run the union arm on that same connection and simply not find the
     * second row.
     *
     * @return void
     */
    public function testAUnionStillAsksEveryShard(): void
    {
        $this->seedNotes();

        $this->watch();
        PinNote::query()
            ->where('id', 1)
            ->union(PinNote::query()->where('id', 2))
            ->get();

        $this->assertGreaterThan(0, $this->queriesOn('shard_1'));
        $this->assertGreaterThan(0, $this->queriesOn('shard_2'));
    }

    /**
     * A global scope that widens the predicate is part of the predicate.
     *
     * The scopes are applied to each per-shard copy rather than to the builder
     * the routing decision is made on, so a scope adding a top-level
     * alternative was invisible to it: the query would be pinned on a
     * predicate narrower than the one executed, and the rows the scope existed
     * to admit would be lost.
     *
     * @return void
     */
    public function testAGlobalScopeThatWidensThePredicateStopsPinning(): void
    {
        foreach ([1, 2, 3, 4] as $id) {
            WidenedNote::create(['id' => $id, 'value' => $id * 10]);
        }

        $this->watch();
        WidenedNote::query()->where('id', 1)->get();

        $this->assertGreaterThan(0, $this->queriesOn('shard_1'));
        $this->assertGreaterThan(0, $this->queriesOn('shard_2'));
    }

    /**
     * A keyed read touches the primary, not its replicas.
     *
     * `connectionFor()` answers with the primary followed by its replicas, and
     * a replica cannot contribute a row to an ordinary read: they all carry
     * `is_replica = true` and the model's scope filters those out. Reading
     * them anyway would cost a round trip for nothing — and with the package's
     * default of one replica, a keyed read would touch two connections, which
     * in a two-shard deployment is every shard there is.
     *
     * @return void
     */
    public function testAKeyedReadDoesNotVisitTheReplicas(): void
    {
        config(['sharding.tables.copies' => ['strategy' => 'hash', 'replica_count' => 1]]);
        app()->singleton(ShardingManager::class, fn () => new ShardingManager(config('sharding')));

        foreach (['shard_1', 'shard_2'] as $connection) {
            Schema::connection($connection)->create('copies', function (Blueprint $table): void {
                $table->unsignedBigInteger('id')->primary();
                $table->boolean('is_replica')->default(false);
            });
        }

        CopiedNote::create(['id' => 1]);

        $resolved = app(ShardingManager::class)->connectionFor(new CopiedNote(), 1);

        $this->assertCount(2, $resolved, 'the fixture has no replica to avoid');

        $this->watch();
        $found = CopiedNote::query()->where('id', 1)->get();

        $this->assertCount(1, $found);
        $this->assertGreaterThan(0, $this->queriesOn($resolved[0]));
        $this->assertSame(0, $this->queriesOn($resolved[1]), 'the replica was read for nothing');
    }

    /**
     * Two rows on each shard, addressed by identifier.
     *
     * @return void
     */
    protected function seedNotes(): void
    {
        foreach ([1, 2, 3, 4] as $id) {
            PinNote::create(['id' => $id, 'value' => $id * 10]);
        }
    }

    /**
     * Two identifiers that land on the same shard.
     *
     * @param class-string<Model> $model The model.
     * @param int $count How many are needed.
     *
     * @return list<int>
     */
    protected function idsOnOneShard(string $model, int $count): array
    {
        $manager = app(ShardingManager::class);
        $groups = [];

        foreach ([1, 2, 3, 4] as $id) {
            $connection = $manager->connectionFor(new $model(), $id)[0] ?? '';
            $groups[$connection][] = $id;

            if (count($groups[$connection]) === $count) {
                return $groups[$connection];
            }
        }

        $this->fail('the fixture has no two identifiers on one shard');
    }

    /**
     * The shard a key lives on, and one that it does not.
     *
     * Asked of the manager rather than written down, so the test states the
     * behaviour instead of restating the hash function.
     *
     * @param class-string<Model> $model The model.
     * @param int|string $key The shard key value.
     *
     * @return array{0: string, 1: string}
     */
    protected function shardsFor(string $model, int|string $key): array
    {
        $mine = app(ShardingManager::class)->connectionFor(new $model(), $key)[0] ?? '';

        $this->assertNotSame('', $mine, 'the strategy named no connection');

        return [$mine, $mine === 'shard_1' ? 'shard_2' : 'shard_1'];
    }

    /**
     * Start counting queries on both shards.
     *
     * @return void
     */
    protected function watch(): void
    {
        foreach (['shard_1', 'shard_2'] as $connection) {
            DB::connection($connection)->flushQueryLog();
            DB::connection($connection)->enableQueryLog();
        }
    }

    /**
     * How many queries one shard has been asked since `watch()`.
     *
     * @param string $connection The shard.
     *
     * @return int
     */
    protected function queriesOn(string $connection): int
    {
        return count(DB::connection($connection)->getQueryLog());
    }
}

class PinNote extends Model
{
    use Shardable;

    protected $table = 'notes';

    public $timestamps = false;

    protected $guarded = [];

    protected $casts = ['is_replica' => 'bool'];
}

class PinLine extends Model
{
    use Shardable;

    protected $table = 'lines';

    protected string $shardKey = 'tenant_id';

    public $timestamps = false;

    protected $guarded = [];

    protected $casts = ['is_replica' => 'bool'];
}

class WidenedNote extends Model
{
    use Shardable;

    protected $table = 'notes';

    public $timestamps = false;

    protected $guarded = [];

    protected $casts = ['is_replica' => 'bool'];

    /**
     * A scope that admits a row the query did not ask for.
     *
     * Contrived on purpose — it is the smallest thing that widens a predicate
     * at the top level, which is the shape the routing has to notice.
     *
     * @return void
     */
    protected static function booted(): void
    {
        static::addGlobalScope('widened', function ($builder): void {
            $builder->orWhere('value', 20);
        });
    }
}

class CopiedNote extends Model
{
    use Shardable;

    protected $table = 'copies';

    public $incrementing = false;

    public $timestamps = false;

    protected $guarded = [];

    protected $casts = ['is_replica' => 'bool'];
}
