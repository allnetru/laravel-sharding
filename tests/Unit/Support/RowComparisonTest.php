<?php

namespace Allnetru\Sharding\Tests\Unit\Support;

use Allnetru\Sharding\Support\RowComparison;
use PHPUnit\Framework\TestCase;

class RowComparisonTest extends TestCase
{
    public function testTheSameRowReadBackInAnotherColumnOrderIsTheSameRow(): void
    {
        $this->assertTrue(RowComparison::same(
            ['id' => 1, 'name' => 'one', 'tenant_id' => 7],
            ['tenant_id' => '7', 'id' => '1', 'name' => 'one'],
        ));
    }

    public function testWhichCopyIsThePrimaryIsNotPartOfTheRow(): void
    {
        $this->assertTrue(RowComparison::same(
            ['id' => 1, 'name' => 'one', 'is_replica' => 1],
            ['id' => 1, 'name' => 'one', 'is_replica' => 0],
        ));
    }

    public function testADifferingValueOrColumnAnswersNo(): void
    {
        $this->assertFalse(RowComparison::same(['id' => 1, 'name' => 'one'], ['id' => 1, 'name' => 'two']));
        $this->assertFalse(RowComparison::same(['id' => 1, 'name' => 'one'], ['id' => 1]));
        $this->assertFalse(RowComparison::same(['id' => 1, 'name' => null], ['id' => 1, 'name' => '']));
    }
}
