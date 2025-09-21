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
     *
     * @return bool
     */
    public function isSupported(): bool;

    /**
     * Determine if the current execution already runs inside a coroutine.
     *
     * @return bool
     */
    public function inCoroutine(): bool;

    /**
     * Spawn a new coroutine for the given task.
     *
     * @param Closure $task
     * @return void
     */
    public function create(Closure $task): void;

    /**
     * Run the given callback inside a coroutine scheduler.
     *
     * @param Closure $callback
     * @return bool
     */
    public function run(Closure $callback): bool;

    /**
     * Create a channel with the given capacity.
     *
     * @param int $capacity
     * @return CoroutineChannel
     */
    public function makeChannel(int $capacity): CoroutineChannel;
}
