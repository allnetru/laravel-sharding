<?php

namespace Allnetru\Sharding\Support\Swoole;

use Closure;

final class SwooleCoroutineDriver implements CoroutineDriver
{
    public function isSupported(): bool
    {
        return extension_loaded('swoole')
            && class_exists(\Swoole\Coroutine::class)
            && class_exists(\Swoole\Coroutine\Channel::class);
    }

    public function inCoroutine(): bool
    {
        if (!$this->isSupported()) {
            return false;
        }

        return \Swoole\Coroutine::getCid() > 0;
    }

    public function create(Closure $task): void
    {
        \Swoole\Coroutine::create($task);
    }

    public function run(Closure $callback): bool
    {
        if (!$this->isSupported() || !method_exists(\Swoole\Coroutine::class, 'run')) {
            return false;
        }

        \Swoole\Coroutine::run($callback);

        return true;
    }

    public function makeChannel(int $capacity): CoroutineChannel
    {
        return new SwooleCoroutineChannel(new \Swoole\Coroutine\Channel($capacity));
    }
}
