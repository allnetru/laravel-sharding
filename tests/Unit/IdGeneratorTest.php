<?php

namespace Allnetru\Sharding\Tests\Unit;

use Allnetru\Sharding\IdGenerator;
use Allnetru\Sharding\Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;

class IdGeneratorTest extends TestCase
{
    use RefreshDatabase;

    public function testSequenceGeneratorIncrementsWithoutDuplicates(): void
    {
        config()->set('sharding.id_generator.default', 'sequence');

        $generator = app(IdGenerator::class);

        $first = $generator->generate('test_table');
        $second = $generator->generate('test_table');

        $this->assertSame($first + 1, $second);
    }

    public function testGenerateThrowsWhenStrategyIsMissing(): void
    {
        $original = config('sharding.id_generator');
        config()->set('sharding.id_generator', [
            'default' => 'missing',
            'strategies' => [],
        ]);

        $generator = new IdGenerator(config('sharding'));

        try {
            $this->expectException(\RuntimeException::class);
            $this->expectExceptionMessage('ID generator strategy [missing] not configured.');

            $generator->generate('test_table');
        } finally {
            config()->set('sharding.id_generator', $original);
        }
    }

    public function testGenerateMergesTableConfigurationBeforeDelegating(): void
    {
        $strategy = new class() implements \Allnetru\Sharding\IdGenerators\Strategy {
            public array $receivedConfig = [];

            public function generate(array $config): int
            {
                $this->receivedConfig = $config;

                return 123;
            }
        };

        $originalIdConfig = config('sharding.id_generator');
        $originalTables = config('sharding.tables');

        config()->set('sharding.id_generator', [
            'default' => 'sequence',
            'strategies' => [
                'sequence' => \Allnetru\Sharding\IdGenerators\TableSequenceStrategy::class,
                'custom' => $strategy::class,
            ],
            'sequence_table' => 'global_sequences',
            'meta_connection' => 'primary',
        ]);

        config()->set('sharding.tables.orders', [
            'id_generator' => 'custom',
            'sequence_table' => 'order_sequences',
            'custom_flag' => true,
        ]);

        app()->instance($strategy::class, $strategy);

        try {
            $generator = new IdGenerator(config('sharding'));
            $generated = $generator->generate('orders');

            $this->assertSame(123, $generated);
            $this->assertSame('orders', $strategy->receivedConfig['table']);
            $this->assertSame('order_sequences', $strategy->receivedConfig['sequence_table']);
            $this->assertSame('primary', $strategy->receivedConfig['meta_connection']);
            $this->assertTrue($strategy->receivedConfig['custom_flag']);
            $this->assertArrayNotHasKey('strategies', $strategy->receivedConfig);
            $this->assertArrayNotHasKey('default', $strategy->receivedConfig);
        } finally {
            config()->set('sharding.id_generator', $originalIdConfig);
            config()->set('sharding.tables', $originalTables);
        }
    }
}
