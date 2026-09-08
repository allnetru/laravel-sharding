<?php

namespace Allnetru\Sharding\Tests\Unit\Support\Coroutine;

use Allnetru\Sharding\Support\Coroutine\CoroutineDispatcher;
use Allnetru\Sharding\Tests\Stubs\FakeCoroutineDriver;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * @covers \Allnetru\Sharding\Support\Coroutine\CoroutineDispatcher
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
     * Outside a coroutine the tasks run sequentially and no scheduler starts.
     *
     * This asserted the opposite until v0.3.13, and the reversal is the point
     * of that release: booting a scheduler from a console process left it with
     * an event loop it did not reliably leave, and `php artisan tinker` doing
     * one `User::count()` across two shards returned the right number and then
     * hung. Concurrency on the path that serves people is kept by the branch
     * above this one — Octane runs a request inside a coroutine already.
     */
    public function testRunStaysSequentialOutsideAnExistingCoroutine(): void
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
        // no scheduler was started, and no coroutine was created
        $this->assertSame(0, $driver->runCalls);
        $this->assertSame(0, $driver->createCalls);
    }

    /**
     * Inside a coroutine the tasks are still dispatched concurrently.
     *
     * The half that must not be lost: this is the Octane request path.
     */
    public function testRunDispatchesConcurrentlyInsideACoroutine(): void
    {
        $driver = new FakeCoroutineDriver();
        $driver->inCoroutine = true;
        CoroutineDispatcher::useDriver($driver);

        $results = CoroutineDispatcher::run([
            'first' => fn (): int => 1,
            'second' => fn (): int => 2,
        ]);

        $this->assertSame(['first' => 1, 'second' => 2], $results);
        $this->assertSame(0, $driver->runCalls);
        $this->assertSame(2, $driver->createCalls);
    }
}
