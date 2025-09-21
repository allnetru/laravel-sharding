<?php

namespace Allnetru\Sharding\Tests\Stubs;

use Allnetru\Sharding\Support\Swoole\CoroutineChannel;
use Allnetru\Sharding\Support\Swoole\CoroutineDriver;
use Closure;

final class FakeCoroutineDriver implements CoroutineDriver
{
    public int $runCalls = 0;
    public int $createCalls = 0;
    private bool $inCoroutine = false;

    public function isSupported(): bool
    {
        return true;
    }

    public function inCoroutine(): bool
    {
        return $this->inCoroutine;
    }

    public function create(Closure $task): void
    {
        $this->createCalls++;
        $task();
    }

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

    public function makeChannel(int $capacity): CoroutineChannel
    {
        return new FakeCoroutineChannel();
    }
}
