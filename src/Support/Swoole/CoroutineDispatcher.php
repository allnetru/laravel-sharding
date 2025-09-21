<?php

namespace Allnetru\Sharding\Support\Swoole;

use Throwable;

/**
 * Dispatch shard operations using Swoole coroutines when available.
 */
final class CoroutineDispatcher
{
    /**
     * Determine whether the runtime is currently inside a Swoole coroutine.
     */
    public static function inCoroutine(): bool
    {
        return extension_loaded('swoole')
            && class_exists(\Swoole\Coroutine::class)
            && class_exists(\Swoole\Coroutine\Channel::class)
            && \Swoole\Coroutine::getCid() > 0;
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

        if (!self::inCoroutine()) {
            $results = [];

            foreach ($tasks as $key => $task) {
                $results[$key] = $task();
            }

            return $results;
        }

        $order = array_keys($tasks);
        $channel = new \Swoole\Coroutine\Channel(count($tasks));

        foreach ($tasks as $key => $task) {
            \Swoole\Coroutine::create(function () use ($channel, $key, $task): void {
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

        return $ordered;
    }
}
