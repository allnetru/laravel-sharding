<?php

namespace Allnetru\Sharding\Support\Coroutine\Drivers;

use Allnetru\Sharding\Support\Coroutine\CoroutineChannel;
use Allnetru\Sharding\Support\Coroutine\CoroutineDriver;
use Closure;

/**
 * Coroutine driver that keeps execution synchronous.
 */
final class SyncCoroutineDriver implements CoroutineDriver
{
    /**
     * @inheritDoc
     */
    public function isSupported(): bool
    {
        return false;
    }

    /**
     * @inheritDoc
     */
    public function inCoroutine(): bool
    {
        return false;
    }

    /**
     * @inheritDoc
     */
    public function create(Closure $task): void
    {
        $task();
    }

    /**
     * @inheritDoc
     */
    public function run(Closure $callback): bool
    {
        $callback();

        return true;
    }

    /**
     * @inheritDoc
     */
    public function makeChannel(int $capacity): CoroutineChannel
    {
        return new class implements CoroutineChannel {
            public function push(mixed $value): bool
            {
                return false;
            }

            public function pop(): mixed
            {
                return false;
            }
        };
    }
}
