<?php

namespace Allnetru\Sharding\Support\Swoole;

use Throwable;

/**
 * Dispatch shard operations using Swoole coroutines when available.
 */
final class CoroutineDispatcher
{
    private static ?CoroutineDriver $driver = null;

    /**
     * Determine whether the runtime is currently inside a Swoole coroutine.
     */
    public static function inCoroutine(): bool
    {
        return self::driver()->inCoroutine();
    }

    /**
     * Run the given tasks concurrently when Swoole coroutines are available.
     *
     * @template TKey of array-key
     * @template TValue
     *
     * @param array<TKey, callable(): TValue> $tasks
     * @return array<TKey, TValue>
     */
    public static function run(array $tasks): array
    {
        if ($tasks === []) {
            return [];
        }

        $driver = self::driver();

        if (!$driver->isSupported()) {
            return self::runSequentially($tasks);
        }

        if ($driver->inCoroutine()) {
            return self::dispatchInCoroutine($tasks, $driver);
        }

        $hasResult = false;
        $result = null;
        $error = null;

        if ($driver->run(function () use ($tasks, $driver, &$result, &$hasResult, &$error): void {
            try {
                $result = self::dispatchInCoroutine($tasks, $driver);
                $hasResult = true;
            } catch (Throwable $throwable) {
                $error = $throwable;
            }
        })) {
            if ($error instanceof Throwable) {
                throw $error;
            }

            if ($hasResult) {
                /** @var array<TKey, TValue> $result */
                return $result;
            }
        }

        return self::runSequentially($tasks);
    }

    /**
     * Swap the coroutine driver. Primarily used for testing.
     */
    public static function useDriver(?CoroutineDriver $driver): void
    {
        self::$driver = $driver;
    }

    private static function driver(): CoroutineDriver
    {
        return self::$driver ??= new SwooleCoroutineDriver();
    }

    /**
     * @template TKey of array-key
     * @template TValue
     *
     * @param array<TKey, callable(): TValue> $tasks
     * @return array<TKey, TValue>
     */
    private static function runSequentially(array $tasks): array
    {
        $results = [];

        foreach ($tasks as $key => $task) {
            $results[$key] = $task();
        }

        return $results;
    }

    /**
     * @template TKey of array-key
     * @template TValue
     *
     * @param array<TKey, callable(): TValue> $tasks
     * @param CoroutineDriver $driver
     * @return array<TKey, TValue>
     */
    private static function dispatchInCoroutine(array $tasks, CoroutineDriver $driver): array
    {
        $order = array_keys($tasks);
        $channel = $driver->makeChannel(count($tasks));

        foreach ($tasks as $key => $task) {
            $driver->create(function () use ($channel, $key, $task): void {
                try {
                    $channel->push([
                        'key' => $key,
                        'result' => $task(),
                        'error' => null,
                    ]);
                } catch (Throwable $exception) {
                    $channel->push([
                        'key' => $key,
                        'result' => null,
                        'error' => $exception,
                    ]);
                }
            });
        }

        $results = [];
        $errors = [];
        $count = count($tasks);

        for ($i = 0; $i < $count; $i++) {
            $payload = $channel->pop();

            if ($payload === false || !is_array($payload) || !array_key_exists('key', $payload)) {
                continue;
            }

            $key = $payload['key'];

            if (($payload['error'] ?? null) instanceof Throwable) {
                /** @var Throwable $error */
                $error = $payload['error'];
                $errors[$key] = $error;

                continue;
            }

            $results[$key] = $payload['result'] ?? null;
        }

        if ($errors) {
            /** @var Throwable $first */
            $first = array_shift($errors);

            throw $first;
        }

        $ordered = [];

        foreach ($order as $key) {
            if (array_key_exists($key, $results)) {
                $ordered[$key] = $results[$key];
            }
        }

        /** @var array<TKey, TValue> $ordered */
        return $ordered;
    }
}
