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
    /**
     * @var array<string, string> Where each shard's database file is.
     */
    protected array $files = [];

    protected function setUp(): void
    {
        parent::setUp();

        /*
        | A file per shard rather than `:memory:`, which every connection of a
        | process shares: a move across shards would then insert into the
        | database it is reading from and fail on the primary key.
        */
        foreach (['shard_1', 'shard_2'] as $shard) {
            $this->files[$shard] = tempnam(sys_get_temp_dir(), 'shard-');
            config(["database.connections.{$shard}" => [
                'driver' => 'sqlite',
                'database' => $this->files[$shard],
                'prefix' => '',
            ]]);
        }

        /*
        | No replicas here. With two shards and the shipped `replica_count`
        | of one, every key names both connections and a move between them
        | is a re-key rather than a relocation — which is worth testing, but
        | not in the tests about rows crossing a shard.
        */
        config([
            'sharding.replica_count' => 0,
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

            Schema::connection($connection)->create('note_tags', function (Blueprint $table): void {
                $table->unsignedBigInteger('id')->primary();
                $table->unsignedBigInteger('note_owner_id');
                $table->string('label');
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

    public function testEveryCopyOfARowMovesWithIt(): void
    {
        // the shipped default: a key names its shard and one replica, and a
        // move that touched only the first would leave the replica's copy
        // behind under the old key
        config(['sharding.replica_count' => 1]);
        app()->singleton(ShardingManager::class, fn () => new ShardingManager(config('sharding')));

        $from = 7;
        $to = 8;

        foreach (app(ShardingManager::class)->connectionFor(new MovableNote(), $from) as $connection) {
            DB::connection($connection)->table('notes')->insert([
                ['id' => 1, 'tenant_id' => $from, 'folder_id' => 5, 'body' => 'moves'],
            ]);
        }

        app(ShardMover::class)->move(new MovableNote(), ['notes'], $from, $to);

        foreach (['shard_1', 'shard_2'] as $connection) {
            $this->assertSame(
                0,
                DB::connection($connection)->table('notes')->where('tenant_id', $from)->count(),
                sprintf('a copy stayed behind on %s', $connection),
            );
        }

        foreach (app(ShardingManager::class)->connectionFor(new MovableNote(), $to) as $connection) {
            $this->assertSame(
                1,
                DB::connection($connection)->table('notes')->where('tenant_id', $to)->count(),
                sprintf('%s did not get its copy', $connection),
            );
        }
    }

    public function testEachTableKeepsItsOwnShardKeyColumn(): void
    {
        [$from, $to] = $this->twoKeysOnDifferentShards();

        $source = $this->shardOf($from);

        DB::connection($source)->table('notes')->insert([
            ['id' => 1, 'tenant_id' => $from, 'folder_id' => 5, 'body' => 'a note'],
        ]);

        DB::connection($source)->table('note_tags')->insert([
            ['id' => 1, 'note_owner_id' => $from, 'label' => 'a tag'],
        ]);

        // one colocation group can key its tables differently — `users.id`
        // beside `user_roles.user_id` — so the column is given per table
        $moved = app(ShardMover::class)->move(
            new MovableNote(),
            ['notes' => 'tenant_id', 'note_tags' => 'note_owner_id'],
            $from,
            $to,
        );

        $this->assertSame(2, $moved);
        $this->assertSame(
            $to,
            (int) DB::connection($this->shardOf($to))->table('note_tags')->value('note_owner_id'),
        );
    }

    protected function tearDown(): void
    {
        foreach ($this->files as $file) {
            @unlink($file);
        }

        parent::tearDown();
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
