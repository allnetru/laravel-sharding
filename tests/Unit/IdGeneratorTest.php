<?php

namespace Allnetru\Sharding\Tests\Unit;

use Allnetru\Sharding\IdGenerator;
use Allnetru\Sharding\Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;

class IdGeneratorTest extends TestCase
{
    use RefreshDatabase;

    public function test_sequence_generator_increments_without_duplicates(): void
    {
        config()->set('sharding.id_generator.default', 'sequence');

        $generator = app(IdGenerator::class);

        $first = $generator->generate('test_table');
        $second = $generator->generate('test_table');

        $this->assertSame($first + 1, $second);
    }
}
