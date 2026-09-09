<?php

namespace Allnetru\Sharding\Strategies;

/**
 * Contract for strategies that expose an "after rebalance" hook.
 */
interface SupportsAfterRebalance
{
    /**
     * Hand the routing over once the rows have arrived.
     *
     * Called only when every row moved. A range strategy gives the range to
     * the new connection here, and doing that while rows are still on the old
     * one is what makes them unreachable — so a run that refused or failed on
     * any row skips this and raises `RebalanceIncomplete` instead.
     *
     * @param string $table
     * @param string $shardKey The column a slot is computed from, not the row's own key.
     * @param string|null $from
     * @param string|null $to
     * @param int|null $start
     * @param int|null $end
     * @param array $config
     * @return void
     */
    public function afterRebalance(string $table, string $shardKey, ?string $from, ?string $to, ?int $start, ?int $end, array $config): void;
}
