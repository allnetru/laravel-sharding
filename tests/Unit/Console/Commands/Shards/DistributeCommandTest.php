<?php

declare(strict_types=1);

namespace Allnetru\Sharding\Tests\Unit\Console\Commands\Shards;

use Allnetru\Sharding\Console\Commands\Shards\Distribute;
use Allnetru\Sharding\Tests\TestCase;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

final class DistributeCommandTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        DB::connection('sqlite')->statement('PRAGMA foreign_keys = ON');
    }

    protected function tearDown(): void
    {
        Schema::dropIfExists('fk_children');
        Schema::dropIfExists('fk_parents');
        Schema::dropIfExists('plain_tables');

        parent::tearDown();
    }

    public function testHasForeignKeysDetectsOutgoingConstraintsOnSqlite(): void
    {
        Schema::create('fk_parents', function (Blueprint $table): void {
            $table->id();
        });

        Schema::create('fk_children', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('parent_id')->constrained('fk_parents');
        });

        $command = new class extends Distribute {
            public function check(string $connection, string $table): bool
            {
                return $this->hasForeignKeys($connection, $table);
            }
        };

        $this->assertTrue($command->check('sqlite', 'fk_children'));
    }

    public function testHasForeignKeysDetectsInboundConstraintsOnSqlite(): void
    {
        Schema::create('fk_parents', function (Blueprint $table): void {
            $table->id();
        });

        Schema::create('fk_children', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('parent_id')->constrained('fk_parents');
        });

        $command = new class extends Distribute {
            public function check(string $connection, string $table): bool
            {
                return $this->hasForeignKeys($connection, $table);
            }
        };

        $this->assertTrue($command->check('sqlite', 'fk_parents'));
    }

    public function testHasForeignKeysReturnsFalseWhenNoConstraintsExist(): void
    {
        Schema::create('plain_tables', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
        });

        $command = new class extends Distribute {
            public function check(string $connection, string $table): bool
            {
                return $this->hasForeignKeys($connection, $table);
            }
        };

        $this->assertFalse($command->check('sqlite', 'plain_tables'));
    }
}
