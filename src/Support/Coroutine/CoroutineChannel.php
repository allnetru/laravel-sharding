<?php

namespace Allnetru\Sharding\Support\Coroutine;

/**
 * Lightweight channel abstraction for coroutine runtimes.
 */
interface CoroutineChannel
{
    /**
     * Push a value into the channel.
     *
     * @param mixed $value
     * @return bool
     */
    public function push(mixed $value): bool;

    /**
     * Pop a value from the channel.
     *
     * @return mixed
     */
    public function pop(): mixed;
}
