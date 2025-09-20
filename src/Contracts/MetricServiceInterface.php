<?php

namespace Allnetru\Sharding\Contracts;

/**
 * Contract for metric collection implementations.
 */
interface MetricServiceInterface
{
    /**
     * Increment a metric.
     *
     * @param  string  $key
     * @param  int  $value
     * @return void
     */
    public function increment(string $key, int $value = 1): void;
}
