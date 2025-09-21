<?php

namespace Allnetru\Sharding\Support\Swoole;

/**
 * Lightweight channel abstraction for coroutine runtimes.
 */
interface CoroutineChannel
{
    /**
     * Push a value into the channel.
     */
    public function push(mixed $value): bool;

    /**
     * Pop a value from the channel.
     */
    public function pop(): mixed;
}
