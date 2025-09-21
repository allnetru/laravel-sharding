<?php

namespace Allnetru\Sharding\Tests\Unit;

use Allnetru\Sharding\IdGenerators\TableSequenceStrategy;
use Allnetru\Sharding\Models\ShardSequence;
use Allnetru\Sharding\Tests\TestCase;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class TableSequenceStrategyTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Schema::dropIfExists('shard_sequences');
        Schema::create('shard_sequences', function (Blueprint $table): void {
            $table->string('table')->primary();
            $table->unsignedBigInteger('last_id')->default(0);
        });
    }

    public function testGenerateCreatesSequenceWhenMissing(): void
    {
        $strategy = new TableSequenceStrategy();

        $next = $strategy->generate([
            'table' => 'widgets',
            'meta_connection' => 'sqlite',
            'sequence_table' => 'shard_sequences',
        ]);

        $this->assertSame(1, $next);
        $this->assertDatabaseHas('shard_sequences', ['table' => 'widgets', 'last_id' => 1]);
    }

    public function testGenerateIncrementsExistingSequence(): void
    {
        DB::table('shard_sequences')->insert([
            'table' => 'widgets',
            'last_id' => 41,
        ]);

        $strategy = new TableSequenceStrategy();

        $next = $strategy->generate([
            'table' => 'widgets',
            'meta_connection' => 'sqlite',
            'sequence_table' => 'shard_sequences',
        ]);

        $this->assertSame(42, $next);
        $this->assertDatabaseHas('shard_sequences', ['table' => 'widgets', 'last_id' => 42]);
    }

    public function testGenerateRetriesOnUniqueConstraintConflicts(): void
    {
        $strategy = new TableSequenceStrategy();
        $triggered = false;

        ShardSequence::flushEventListeners();
        ShardSequence::saving(function (ShardSequence $sequence) use (&$triggered): void {
            if ($triggered) {
                return;
            }

            $triggered = true;

            DB::table('shard_sequences')->insert([
                'table' => $sequence->getAttribute('table'),
                'last_id' => 10,
            ]);
        });

        try {
            $next = $strategy->generate([
                'table' => 'orders',
                'meta_connection' => 'sqlite',
                'sequence_table' => 'shard_sequences',
            ]);
        } finally {
            ShardSequence::flushEventListeners();
        }

        $this->assertSame(11, $next);
        $this->assertDatabaseHas('shard_sequences', ['table' => 'orders', 'last_id' => 11]);
    }
}
