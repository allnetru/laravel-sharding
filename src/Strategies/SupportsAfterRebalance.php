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
     * Called only when every row of every table moved. A range strategy gives
     * the range to the new connection here, and doing that while rows are
     * still on the old one is what makes them unreachable — so a run that
     * refused or failed on any row skips this and raises `RebalanceIncomplete`
     * instead.
     *
     * The first argument is the scope the routing lives under — the group
     * owner's table, which is `$config['table']` — rather than any one of the
     * tables whose rows moved. A rebalance covers a whole colocation group, and
     * the group has one routing.
     *
     * @param string $table The group owner's table: the scope the routing is written under.
     * @param string $shardKey The column a slot is computed from on the first table given.
     * @param string|null $from
     * @param string|null $to
     * @param int|null $start
     * @param int|null $end
     * @param array $config
     * @return void
     */
    public function afterRebalance(string $table, string $shardKey, ?string $from, ?string $to, ?int $start, ?int $end, array $config): void;
}
