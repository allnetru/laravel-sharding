<?php

namespace Allnetru\Sharding\Strategies;

/**
 * Contract for strategies that perform actions after moving records.
 */
interface RowMoveAware
{
    /**
     * Redirect a shard key once every row it covers has arrived.
     *
     * The value is the **shard key**, not the row's own identifier, and it is
     * why this is called after the run rather than per row: on a colocated
     * one-to-many table one key covers several rows, so redirecting it when
     * the first of them lands points the routing away from the siblings still
     * on the source. A run that leaves any row behind does not call this at
     * all — it raises `RebalanceIncomplete` instead.
     *
     * Called once per distinct key, whatever the number of rows behind it.
     *
     * @param int|string $key The shard key whose rows have moved.
     * @param string $connection Where they now live.
     * @param array $config
     * @return void
     */
    public function rowMoved(int|string $key, string $connection, array $config): void;
}
