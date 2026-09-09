<?php

namespace Allnetru\Sharding\Tests\Unit;

use Allnetru\Sharding\Models\Concerns\Shardable;
use Allnetru\Sharding\ShardingManager;
use Allnetru\Sharding\Tests\TestCase;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * A morphTo on a sharded model, including before it points anywhere.
 *
 * The relation resolves the related model in `addConstraints()` so it can pick
 * the shard, which is earlier than stock Eloquent resolves anything. That is
 * what makes a colocated morph read one shard — and it means the method has to
 * survive states stock code never resolves a model in.
 */
class ShardMorphToTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config([
            'database.connections.shard_1' => ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => ''],
            'database.connections.shard_2' => ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => ''],
            'sharding.connections' => ['shard_1' => ['weight' => 1], 'shard_2' => ['weight' => 1]],
            'sharding.default' => 'hash',
            'sharding.tables' => [],
        ]);

        app()->singleton(ShardingManager::class, fn () => new ShardingManager(config('sharding')));

        foreach (['shard_1', 'shard_2'] as $connection) {
            Schema::connection($connection)->create('mt_notes', function (Blueprint $table): void {
                $table->unsignedBigInteger('id')->primary();
                $table->string('subject_type')->nullable();
                $table->unsignedBigInteger('subject_id')->nullable();
                $table->boolean('is_replica')->default(false);
            });

            Schema::connection($connection)->create('mt_topics', function (Blueprint $table): void {
                $table->unsignedBigInteger('id')->primary();
                $table->string('name');
                $table->boolean('is_replica')->default(false);
            });
        }
    }

    /**
     * Reading the relation before the type is set answers null, not a fatal.
     *
     * The case that made every logged save die:
     * `spatie/laravel-activitylog` builds an entry and reads `$entry->subject`
     * before assigning `subject_type`, and this used to call
     * `createModelByType(null)` — «Class name must be a valid object or a
     * string», from inside a model event, on an ordinary save.
     *
     * @return void
     */
    public function testAMorphWithNoTypeYetAnswersNull(): void
    {
        $note = new MtNote();

        $this->assertNull($note->subject);
        $this->assertInstanceOf(MorphTo::class, $note->subject());
    }

    /**
     * A saved row with no morph is the same case, one step later.
     *
     * @return void
     */
    public function testAStoredRowWithoutAMorphAnswersNull(): void
    {
        /*
        | Inserted on the connection the key names, and asked of the manager
        | rather than written down. A row placed by hand on any other shard is
        | a row whose key points elsewhere — findable only by a fan-out, which
        | is exactly the state v0.4.0 stopped covering for. The subject of this
        | test is a morph without a type, not misplacement.
        */
        $connection = app(ShardingManager::class)->connectionFor(new MtNote(), 1)[0];

        DB::connection($connection)->table('mt_notes')->insert([
            'id' => 1,
            'subject_type' => null,
            'subject_id' => null,
            'is_replica' => false,
        ]);

        $note = MtNote::query()->find(1);

        $this->assertNotNull($note);
        $this->assertNull($note->subject);
    }

    /**
     * And with a type it still finds the row, wherever it lives.
     *
     * The half that must not be lost to the guard above.
     *
     * @return void
     */
    public function testAMorphWithATypeStillResolves(): void
    {
        $topic = new MtTopic();
        $topic->name = 'Тема';
        $topic->save();

        $note = new MtNote();
        $note->subject_type = MtTopic::class;
        $note->subject_id = $topic->getKey();
        $note->save();

        $found = MtNote::query()->find($note->getKey());

        $this->assertNotNull($found);
        $this->assertNotNull($found->subject);
        $this->assertSame('Тема', $found->subject->name);
    }
}

class MtNote extends Model
{
    use Shardable;

    protected $table = 'mt_notes';
    public $timestamps = false;
    public $incrementing = false;
    protected $guarded = [];

    public function subject(): MorphTo
    {
        return $this->morphTo();
    }
}

class MtTopic extends Model
{
    use Shardable;

    protected $table = 'mt_topics';
    public $timestamps = false;
    public $incrementing = false;
    protected $guarded = [];
}
