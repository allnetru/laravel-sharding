<?php

namespace Allnetru\Sharding\Support\Swoole;

use Closure;
use Illuminate\Container\Container as IlluminateContainer;
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
     *
     * Set to null to reload the configured driver on the next call.
     */
    public static function useDriver(?CoroutineDriver $driver): void
    {
        self::$driver = $driver;
    }

    private static function driver(): CoroutineDriver
    {
        if (self::$driver instanceof CoroutineDriver) {
            return self::$driver;
        }

        if (($configured = self::configuredDriver()) instanceof CoroutineDriver) {
            return self::$driver = $configured;
        }

        return self::$driver = new SwooleCoroutineDriver();
    }

    private static function configuredDriver(): ?CoroutineDriver
    {
        $container = class_exists(IlluminateContainer::class)
            ? IlluminateContainer::getInstance()
            : null;

        if ($container !== null && $container->bound('config')) {
            try {
                /** @var mixed $config */
                $config = $container->make('config')->get('sharding.coroutines');
            } catch (Throwable) {
                return null;
            }
        } elseif (function_exists('config')) {
            try {
                $config = config('sharding.coroutines');
            } catch (Throwable) {
                return null;
            }
        } else {
            return null;
        }

        if (!is_array($config)) {
            return null;
        }

        $name = $config['default'] ?? $config['driver'] ?? null;

        if (!is_string($name) || $name === '') {
            return null;
        }

        $registry = $config['drivers'] ?? [];

        if (!is_array($registry) || !array_key_exists($name, $registry)) {
            return null;
        }

        return self::resolveConfiguredDriver($registry[$name]);
    }

    private static function resolveConfiguredDriver(mixed $definition): ?CoroutineDriver
    {
        if ($definition instanceof CoroutineDriver) {
            return $definition;
        }

        $container = class_exists(IlluminateContainer::class)
            ? IlluminateContainer::getInstance()
            : null;

        if ($definition instanceof Closure) {
            if ($container !== null) {
                try {
                    return self::resolveConfiguredDriver($container->call($definition));
                } catch (Throwable) {
                    // Fallback to executing the closure without container injection.
                }
            }

            return self::resolveConfiguredDriver($definition());
        }

        if (is_string($definition) && $definition !== '') {
            if (function_exists('app')) {
                try {
                    $resolved = app($definition);

                    if ($resolved instanceof CoroutineDriver) {
                        return $resolved;
                    }
                } catch (Throwable) {
                    // Ignore and fallback to manual instantiation.
                }
            }

            if ($container !== null) {
                try {
                    $resolved = $container->make($definition);

                    if ($resolved instanceof CoroutineDriver) {
                        return $resolved;
                    }
                } catch (Throwable) {
                    // Ignore and fallback to manual instantiation.
                }
            }

            if (class_exists($definition)) {
                $resolved = new $definition();

                if ($resolved instanceof CoroutineDriver) {
                    return $resolved;
                }
            }
        }

        return null;
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
