<?php

namespace Allnetru\Sharding\Strategies;

/**
 * Contract for strategies that perform actions after moving records.
 */
interface RowMoveAware
{
    /**
     * Handle post-processing after a record is moved to a new shard.
     */
    public function rowMoved(int|string $id, string $connection, array $config): void;
}
