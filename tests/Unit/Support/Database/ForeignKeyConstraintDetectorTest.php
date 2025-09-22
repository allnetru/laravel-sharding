<?php

declare(strict_types=1);

namespace Allnetru\Sharding\Tests\Unit\Support\Database;

use Allnetru\Sharding\Support\Database\ForeignKeyConstraintDetector;
use Allnetru\Sharding\Tests\TestCase;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

final class ForeignKeyConstraintDetectorTest extends TestCase
{
    private ForeignKeyConstraintDetector $detector;

    protected function setUp(): void
    {
        parent::setUp();

        $this->detector = new ForeignKeyConstraintDetector();
        DB::connection('sqlite')->statement('PRAGMA foreign_keys = ON');
    }

    protected function tearDown(): void
    {
        Schema::dropIfExists('fk_children');
        Schema::dropIfExists('fk_parents');
        Schema::dropIfExists('plain_tables');

        parent::tearDown();
    }

    public function testDetectsOutgoingConstraintsOnSqlite(): void
    {
        Schema::create('fk_parents', function (Blueprint $table): void {
            $table->id();
        });

        Schema::create('fk_children', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('parent_id')->constrained('fk_parents');
        });

        $this->assertTrue($this->detector->hasForeignKeys('sqlite', 'fk_children'));
    }

    public function testDetectsInboundConstraintsOnSqlite(): void
    {
        Schema::create('fk_parents', function (Blueprint $table): void {
            $table->id();
        });

        Schema::create('fk_children', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('parent_id')->constrained('fk_parents');
        });

        $this->assertTrue($this->detector->hasForeignKeys('sqlite', 'fk_parents'));
    }

    public function testReturnsFalseWhenNoConstraintsExist(): void
    {
        Schema::create('plain_tables', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
        });

        $this->assertFalse($this->detector->hasForeignKeys('sqlite', 'plain_tables'));
    }
}
