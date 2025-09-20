<?php

namespace Allnetru\Sharding\Strategies;

/**
 * Contract for strategies that expose an "after rebalance" hook.
 */
interface SupportsAfterRebalance
{
    /**
     * Handle post-processing after rows have been moved between shards.
     *
     * @param string $table
     * @param string $key
     * @param string|null $from
     * @param string|null $to
     * @param int|null $start
     * @param int|null $end
     * @param array $config
     * @return void
     */
    public function afterRebalance(string $table, string $key, ?string $from, ?string $to, ?int $start, ?int $end, array $config): void;
}
