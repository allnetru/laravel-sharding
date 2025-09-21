<?php

namespace Allnetru\Sharding\Tests\Stubs;

use Allnetru\Sharding\Support\Swoole\CoroutineChannel;

final class FakeCoroutineChannel implements CoroutineChannel
{
    /**
     * @param list<mixed> $buffer
     */
    public function __construct(private array $buffer = [])
    {
    }

    public function push(mixed $value): bool
    {
        $this->buffer[] = $value;

        return true;
    }

    public function pop(): mixed
    {
        if ($this->buffer === []) {
            return false;
        }

        return array_shift($this->buffer);
    }
}
