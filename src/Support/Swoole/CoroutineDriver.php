<?php

namespace Allnetru\Sharding\Support\Swoole;

use Closure;

/**
 * Adapter for coroutine runtimes.
 */
interface CoroutineDriver
{
    /**
     * Whether the coroutine runtime is available.
     */
    public function isSupported(): bool;

    /**
     * Determine if the current execution already runs inside a coroutine.
     */
    public function inCoroutine(): bool;

    /**
     * Spawn a new coroutine for the given task.
     */
    public function create(Closure $task): void;

    /**
     * Run the given callback inside a coroutine scheduler.
     */
    public function run(Closure $callback): bool;

    /**
     * Create a channel with the given capacity.
     */
    public function makeChannel(int $capacity): CoroutineChannel;
}
