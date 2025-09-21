<?php

namespace Allnetru\Sharding\Support\Coroutine\Drivers;

use Allnetru\Sharding\Support\Coroutine\CoroutineChannel;

/**
 * Thin wrapper around the native Swoole coroutine channel implementation.
 */
final class SwooleCoroutineChannel implements CoroutineChannel
{
    /**
     * Create a new channel wrapper instance.
     *
     * @param \Swoole\Coroutine\Channel $channel
     */
    public function __construct(private \Swoole\Coroutine\Channel $channel)
    {
    }

    /**
     * @inheritDoc
     */
    public function push(mixed $value): bool
    {
        return $this->channel->push($value);
    }

    /**
     * @inheritDoc
     */
    public function pop(): mixed
    {
        return $this->channel->pop();
    }
}
