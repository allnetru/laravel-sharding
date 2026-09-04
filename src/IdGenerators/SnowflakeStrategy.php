<?php

namespace Allnetru\Sharding\IdGenerators;

use InvalidArgumentException;

/**
 * Generate identifiers using the Snowflake algorithm.
 *
 * The layout follows the original Twitter design and fits into a signed
 * 64-bit integer:
 *
 *     | 1 unused | 41 timestamp | 10 worker | 12 sequence |
 *
 * The previous implementation shifted the timestamp by 16 bits and filled the
 * low bits with `random_int()`. That carried no worker identity at all, so two
 * processes could mint the same id, and relied on 65 536 random values to keep
 * ids apart inside one millisecond. By the birthday bound the collision
 * probability reaches one percent at roughly thirty ids per millisecond and
 * one half at about three hundred, which a single import batch reaches easily.
 * On one shard that surfaces as a primary key violation; across shards it is a
 * silent duplicate that later breaks `find()` and rebalancing.
 *
 * The sequence counter is per process. In a deployment with several processes
 * minting ids you must give each one a distinct `worker_id`, otherwise two
 * processes share a counter space and can collide. When the option is absent
 * the worker id is derived from the hostname and the process id, which is
 * adequate for a single node but is a fallback, not a guarantee.
 */
class SnowflakeStrategy implements Strategy
{
    /**
     * Default epoch: 2020-01-01T00:00:00Z in milliseconds.
     *
     * A custom epoch buys lifetime: 41 bits cover about 69 years from it.
     */
    public const DEFAULT_EPOCH_MS = 1577836800000;

    /**
     * Bits reserved for the worker identifier.
     */
    protected const WORKER_BITS = 10;

    /**
     * Bits reserved for the per-millisecond sequence.
     */
    protected const SEQUENCE_BITS = 12;

    /**
     * Highest usable worker identifier.
     */
    protected const MAX_WORKER_ID = (1 << self::WORKER_BITS) - 1;

    /**
     * Highest usable sequence value before the millisecond is exhausted.
     */
    protected const MAX_SEQUENCE = (1 << self::SEQUENCE_BITS) - 1;

    /**
     * Timestamp of the previously issued identifier, in milliseconds.
     */
    protected static int $lastTimestamp = -1;

    /**
     * Sequence used inside the current millisecond.
     */
    protected static int $sequence = 0;

    /**
     * @inheritdoc
     */
    public function generate(array $config): int
    {
        $epoch = $this->resolveEpoch($config);
        $workerId = $this->resolveWorkerId($config);

        $timestamp = $this->currentTimestamp();

        // A backwards clock jump would re-use timestamps that already minted
        // ids. Waiting is preferable to handing out a duplicate.
        if ($timestamp < static::$lastTimestamp) {
            $timestamp = $this->waitUntil(static::$lastTimestamp);
        }

        if ($timestamp === static::$lastTimestamp) {
            static::$sequence = (static::$sequence + 1) & self::MAX_SEQUENCE;

            // Sequence wrapped, so this millisecond is spent: move to the next.
            if (static::$sequence === 0) {
                $timestamp = $this->waitUntil(static::$lastTimestamp + 1);
            }
        } else {
            static::$sequence = 0;
        }

        static::$lastTimestamp = $timestamp;

        $elapsed = $timestamp - $epoch;

        if ($elapsed < 0) {
            throw new InvalidArgumentException(
                'Snowflake epoch is in the future, ids would be negative.'
            );
        }

        return ($elapsed << (self::WORKER_BITS + self::SEQUENCE_BITS))
            | ($workerId << self::SEQUENCE_BITS)
            | static::$sequence;
    }

    /**
     * Reset the process-local counters.
     *
     * Intended for tests: production code has no reason to rewind the clock.
     *
     * @return void
     */
    public static function reset(): void
    {
        static::$lastTimestamp = -1;
        static::$sequence = 0;
    }

    /**
     * Resolve the configured epoch in milliseconds.
     *
     * @param array<string, mixed> $config
     * @return int
     */
    protected function resolveEpoch(array $config): int
    {
        return (int) ($config['epoch_ms'] ?? self::DEFAULT_EPOCH_MS);
    }

    /**
     * Resolve the worker identifier, falling back to a host derived value.
     *
     * @param array<string, mixed> $config
     * @return int
     */
    protected function resolveWorkerId(array $config): int
    {
        $configured = $config['worker_id'] ?? null;

        if ($configured === null) {
            return $this->deriveWorkerId();
        }

        $workerId = (int) $configured;

        if ($workerId < 0 || $workerId > self::MAX_WORKER_ID) {
            throw new InvalidArgumentException(sprintf(
                'Snowflake worker_id must be between 0 and %d, got %d.',
                self::MAX_WORKER_ID,
                $workerId
            ));
        }

        return $workerId;
    }

    /**
     * Derive a worker identifier from the hostname and process id.
     *
     * A fallback for single node setups. Distinct processes get distinct
     * values only by chance, so multi node deployments must configure
     * `worker_id` explicitly.
     *
     * @return int
     */
    protected function deriveWorkerId(): int
    {
        $seed = (string) gethostname() . ':' . getmypid();

        return (int) (hexdec(substr(md5($seed), 0, 8)) & self::MAX_WORKER_ID);
    }

    /**
     * Current time in milliseconds.
     *
     * @return int
     */
    protected function currentTimestamp(): int
    {
        return (int) (microtime(true) * 1000);
    }

    /**
     * Spin until the clock reaches the given millisecond.
     *
     * @param int $target Millisecond to reach.
     * @return int
     */
    protected function waitUntil(int $target): int
    {
        $timestamp = $this->currentTimestamp();

        while ($timestamp < $target) {
            usleep(200);
            $timestamp = $this->currentTimestamp();
        }

        return $timestamp;
    }
}
