<?php

namespace Allnetru\Sharding\Tests\Unit;

use Allnetru\Sharding\Models\Concerns\Shardable;
use Allnetru\Sharding\ShardingManager;
use Allnetru\Sharding\Tests\TestCase;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Global scopes over a query that fans out.
 *
 * They live on the builder and are applied lazily, so the per-shard copy —
 * built by cloning the query — used to get none of them. Every read then
 * returned exactly the rows the scope existed to hide.
 */
class ShardGlobalScopeTest extends TestCase
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
                $table->integer('value');
                $table->boolean('is_replica')->default(false);
                $table->timestamp('deleted_at')->nullable();
            });
        }

        // one live and one trashed row on each shard, so neither shard alone
        // can produce a right answer or a wrong one
        DB::connection('shard_1')->table('notes')->insert([
            ['id' => 1, 'value' => 1, 'is_replica' => false, 'deleted_at' => null],
            ['id' => 3, 'value' => 3, 'is_replica' => false, 'deleted_at' => '2020-01-01 00:00:00'],
        ]);

        DB::connection('shard_2')->table('notes')->insert([
            ['id' => 2, 'value' => 2, 'is_replica' => false, 'deleted_at' => null],
            ['id' => 4, 'value' => 4, 'is_replica' => false, 'deleted_at' => '2020-01-01 00:00:00'],
        ]);
    }

    public function testSoftDeletedRowsStayHiddenFromAFannedOutGet(): void
    {
        $this->assertSame([1, 2], Note::orderBy('id')->get()->pluck('id')->all());
    }

    public function testSoftDeletedRowsAreNotCountedOrSummed(): void
    {
        $this->assertSame(2, Note::count());
        $this->assertSame(3, (int) Note::sum('value'));
        $this->assertSame(2, (int) Note::max('value'));
    }

    public function testSoftDeletedRowsStayHiddenFromAPaginatedRead(): void
    {
        $page = Note::orderBy('id')->paginate(10);

        $this->assertSame([1, 2], $page->pluck('id')->all());
        $this->assertSame(2, $page->total());
    }

    public function testSoftDeletedRowsStayHiddenFromChunking(): void
    {
        $seen = [];

        Note::orderBy('id')->chunk(10, function ($rows) use (&$seen): void {
            foreach ($rows as $row) {
                $seen[] = $row->id;
            }
        });

        sort($seen);

        $this->assertSame([1, 2], $seen);
    }

    public function testARemovedScopeStaysRemovedOnEveryShard(): void
    {
        $this->assertSame([1, 2, 3, 4], Note::withTrashed()->orderBy('id')->get()->pluck('id')->all());
        $this->assertSame(4, Note::withTrashed()->count());
        $this->assertSame([3, 4], Note::onlyTrashed()->orderBy('id')->get()->pluck('id')->all());
    }

    public function testAnOrdinaryGlobalScopeIsCarriedToo(): void
    {
        // not SoftDeletes: the fix has to work for any scope, and this one
        // constrains a column the package knows nothing about. Rows 2 and 4
        // hold even values and sit on different shards; 4 is also trashed
        $this->assertSame([2], Even::orderBy('id')->get()->pluck('id')->all());
        $this->assertSame(1, Even::count());

        // the two scopes stack, and removing one leaves the other standing
        $this->assertSame([2, 4], Even::withTrashed()->orderBy('id')->get()->pluck('id')->all());
        $this->assertSame(2, Even::withTrashed()->count());
    }
}

class Note extends Model
{
    use Shardable, SoftDeletes;

    protected $table = 'notes';
    public $timestamps = false;
    public $incrementing = false;
    protected $guarded = [];
}

class Even extends Note
{
    protected $table = 'notes';

    protected static function booted(): void
    {
        static::addGlobalScope(new EvenValuesOnly());
    }
}

class EvenValuesOnly implements Scope
{
    public function apply(Builder $builder, Model $model): void
    {
        $builder->whereRaw('value % 2 = 0');
    }
}
