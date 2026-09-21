<?php

namespace Allnetru\Sharding\Tests\Unit;

use Allnetru\Sharding\Models\Concerns\Shardable;
use Allnetru\Sharding\ShardingManager;
use Allnetru\Sharding\ShardMover;
use Allnetru\Sharding\Tests\TestCase;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Moving what one key owns to another key.
 *
 * The key decides the shard, so this is an update when both keys name the
 * same one and a copy followed by a delete when they do not.
 */
class ShardMoverTest extends TestCase
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
                $table->unsignedBigInteger('folder_id');
                $table->string('body');
                $table->boolean('is_replica')->default(false);
            });
        }
    }

    public function testRowsMoveToTheOtherShard(): void
    {
        [$from, $to] = $this->twoKeysOnDifferentShards();

        $source = $this->shardOf($from);
        $target = $this->shardOf($to);

        DB::connection($source)->table('notes')->insert([
            ['id' => 1, 'tenant_id' => $from, 'folder_id' => 5, 'body' => 'moves'],
            ['id' => 2, 'tenant_id' => $from, 'folder_id' => 9, 'body' => 'stays'],
        ]);

        $moved = app(ShardMover::class)->move(
            new MovableNote(),
            ['notes'],
            $from,
            $to,
            static fn ($query) => $query->where('folder_id', 5),
        );

        $this->assertSame(1, $moved);

        // gone from where it was, present where it went, and carrying the
        // new key rather than the old one
        $this->assertSame(1, DB::connection($source)->table('notes')->count());
        $this->assertSame('stays', DB::connection($source)->table('notes')->value('body'));

        $arrived = DB::connection($target)->table('notes')->where('body', 'moves')->first();

        $this->assertNotNull($arrived);
        $this->assertSame($to, (int) $arrived->tenant_id);
    }

    public function testRowsOnOneShardAreJustRekeyed(): void
    {
        [$first, $second] = $this->twoKeysOnTheSameShard();

        $shard = $this->shardOf($first);

        DB::connection($shard)->table('notes')->insert([
            ['id' => 1, 'tenant_id' => $first, 'folder_id' => 5, 'body' => 'moves'],
        ]);

        $moved = app(ShardMover::class)->move(new MovableNote(), ['notes'], $first, $second);

        $this->assertSame(1, $moved);
        $this->assertSame(1, DB::connection($shard)->table('notes')->count());
        $this->assertSame($second, (int) DB::connection($shard)->table('notes')->value('tenant_id'));
    }

    public function testMovingToTheSameKeyDoesNothing(): void
    {
        $this->assertSame(0, app(ShardMover::class)->move(new MovableNote(), ['notes'], 7, 7));
    }

    public function testTheModelsOwnShardKeyIsUsed(): void
    {
        [$from, $to] = $this->twoKeysOnDifferentShards();

        DB::connection($this->shardOf($from))->table('notes')->insert([
            ['id' => 1, 'tenant_id' => $from, 'folder_id' => 5, 'body' => 'moves'],
        ]);

        app(ShardMover::class)->move(new MovableNote(), ['notes'], $from, $to);

        $this->assertSame(
            $to,
            (int) DB::connection($this->shardOf($to))->table('notes')->value('tenant_id'),
        );
    }

    /**
     * @return array{0: int, 1: int}
     */
    protected function twoKeysOnDifferentShards(): array
    {
        for ($key = 1; $key < 200; $key++) {
            for ($other = $key + 1; $other < 200; $other++) {
                if ($this->shardOf($key) !== $this->shardOf($other)) {
                    return [$key, $other];
                }
            }
        }

        $this->fail('no two keys land on different shards');
    }

    /**
     * @return array{0: int, 1: int}
     */
    protected function twoKeysOnTheSameShard(): array
    {
        for ($key = 1; $key < 200; $key++) {
            for ($other = $key + 1; $other < 200; $other++) {
                if ($this->shardOf($key) === $this->shardOf($other)) {
                    return [$key, $other];
                }
            }
        }

        $this->fail('no two keys land on one shard');
    }

    protected function shardOf(int $key): string
    {
        return app(ShardingManager::class)->connectionFor(new MovableNote(), $key)[0];
    }
}

class MovableNote extends Model
{
    use Shardable;

    protected $table = 'notes';

    protected $guarded = [];

    public $timestamps = false;

    protected string $shardKey = 'tenant_id';
}
