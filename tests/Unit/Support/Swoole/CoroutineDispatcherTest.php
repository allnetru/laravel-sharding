<?php

namespace Allnetru\Sharding\Tests\Unit\Support\Swoole;

use Allnetru\Sharding\Support\Swoole\CoroutineDispatcher;
use Allnetru\Sharding\Tests\Stubs\FakeCoroutineDriver;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * @covers \Allnetru\Sharding\Support\Swoole\CoroutineDispatcher
 */
class CoroutineDispatcherTest extends TestCase
{
    /**
     * Reset the dispatcher after each test run.
     */
    protected function tearDown(): void
    {
        CoroutineDispatcher::useDriver(null);

        parent::tearDown();
    }

    /**
     * Ensure tasks execute sequentially when no coroutine driver exists.
     */
    public function testRunExecutesTasksSequentiallyWhenCoroutineUnavailable(): void
    {
        $order = [];

        $results = CoroutineDispatcher::run([
            'first' => function () use (&$order): int {
                $order[] = 'first';

                return 1;
            },
            'second' => function () use (&$order): int {
                $order[] = 'second';

                return 2;
            },
        ]);

        $this->assertSame(['first' => 1, 'second' => 2], $results);
        $this->assertSame(['first', 'second'], $order);
    }

    /**
     * Ensure exceptions thrown within tasks bubble up to the caller.
     */
    public function testRunPropagatesErrorsFromTasks(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('boom');

        CoroutineDispatcher::run([
            'first' => function (): void {
                throw new RuntimeException('boom');
            },
        ]);
    }

    /**
     * Ensure the dispatcher boots a coroutine scheduler when possible.
     */
    public function testRunUsesCoroutineDriverOutsideExistingCoroutine(): void
    {
        $driver = new FakeCoroutineDriver();
        CoroutineDispatcher::useDriver($driver);

        $order = [];

        $results = CoroutineDispatcher::run([
            'first' => function () use (&$order): int {
                $order[] = 'first';

                return 1;
            },
            'second' => function () use (&$order): int {
                $order[] = 'second';

                return 2;
            },
        ]);

        $this->assertSame(['first' => 1, 'second' => 2], $results);
        $this->assertSame(['first', 'second'], $order);
        $this->assertSame(1, $driver->runCalls);
        $this->assertSame(2, $driver->createCalls);
    }
}
