<?php

namespace Allnetru\Sharding\Tests\Unit\Support\Swoole;

use Allnetru\Sharding\Support\Swoole\CoroutineDispatcher;
use PHPUnit\Framework\TestCase;
use RuntimeException;

class CoroutineDispatcherTest extends TestCase
{
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
}
