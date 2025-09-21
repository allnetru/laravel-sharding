<?php

namespace Allnetru\Sharding\Support\Coroutine\Drivers;

use Allnetru\Sharding\Support\Coroutine\CoroutineChannel;
use Allnetru\Sharding\Support\Coroutine\CoroutineDriver;
use Closure;

/**
 * Concrete coroutine driver backed by the Swoole extension.
 */
final class SwooleCoroutineDriver implements CoroutineDriver
{
    /**
     * @inheritDoc
     */
    public function isSupported(): bool
    {
        return extension_loaded('swoole')
            && class_exists(\Swoole\Coroutine::class)
            && class_exists(\Swoole\Coroutine\Channel::class);
    }

    /**
     * @inheritDoc
     */
    public function inCoroutine(): bool
    {
        if (!$this->isSupported()) {
            return false;
        }

        return \Swoole\Coroutine::getCid() > 0;
    }

    /**
     * @inheritDoc
     */
    public function create(Closure $task): void
    {
        \Swoole\Coroutine::create($task);
    }

    /**
     * @inheritDoc
     */
    public function run(Closure $callback): bool
    {
        if (!$this->isSupported() || !method_exists(\Swoole\Coroutine::class, 'run')) {
            return false;
        }

        \Swoole\Coroutine::run($callback);

        return true;
    }

    /**
     * @inheritDoc
     */
    public function makeChannel(int $capacity): CoroutineChannel
    {
        return new SwooleCoroutineChannel(new \Swoole\Coroutine\Channel($capacity));
    }
}
