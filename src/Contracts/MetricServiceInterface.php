<?php

namespace Allnetru\Sharding\Contracts;

/**
 * Contract for metric collection implementations.
 */
interface MetricServiceInterface
{
    /**
     * Increment a metric.
     */
    public function increment(string $key, int $value = 1): void;
}
