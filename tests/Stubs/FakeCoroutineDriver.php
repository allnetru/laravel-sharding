<?php

namespace Allnetru\Sharding\Tests\Stubs;

use Allnetru\Sharding\Support\Coroutine\CoroutineChannel;
use Allnetru\Sharding\Support\Coroutine\CoroutineDriver;
use Closure;

/**
 * Test double that simulates coroutine driver behaviour without Swoole.
 */
final class FakeCoroutineDriver implements CoroutineDriver
{
    /**
     * Number of times the run method was invoked.
     */
    public int $runCalls = 0;

    /**
     * Number of coroutine creations requested via the driver.
     */
    public int $createCalls = 0;

    /**
     * Flag indicating if the fake is currently within a coroutine scope.
     */
    private bool $inCoroutine = false;

    /**
     * @inheritDoc
     */
    public function isSupported(): bool
    {
        return true;
    }

    /**
     * @inheritDoc
     */
    public function inCoroutine(): bool
    {
        return $this->inCoroutine;
    }

    /**
     * @inheritDoc
     */
    public function create(Closure $task): void
    {
        $this->createCalls++;
        $task();
    }

    /**
     * @inheritDoc
     */
    public function run(Closure $callback): bool
    {
        $this->runCalls++;
        $this->inCoroutine = true;

        try {
            $callback();
        } finally {
            $this->inCoroutine = false;
        }

        return true;
    }

    /**
     * @inheritDoc
     */
    public function makeChannel(int $capacity): CoroutineChannel
    {
        return new FakeCoroutineChannel();
    }
}
