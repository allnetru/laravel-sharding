<?php

namespace Allnetru\Sharding\Support\Swoole;

final class SwooleCoroutineChannel implements CoroutineChannel
{
    public function __construct(private \Swoole\Coroutine\Channel $channel)
    {
    }

    public function push(mixed $value): bool
    {
        return $this->channel->push($value);
    }

    public function pop(): mixed
    {
        return $this->channel->pop();
    }
}
