<?php

namespace Allnetru\Sharding\Exceptions;

use RuntimeException;

/**
 * The rebalance left rows behind, so the routing must not be moved on.
 *
 * A rebalance is two things happening together: rows move, and the metadata
 * that says where a key lives moves with them. Doing the second while the
 * first only partly happened is what makes rows unreachable — a range strategy
 * hands the range to the new connection, the rows that were refused are still
 * on the old one, and retiring it loses them.
 *
 * So a run with any failure raises this instead of returning a count. Nothing
 * about the rows already moved is undone: they are on the shards their keys
 * name, which is where they belong either way. What is skipped is
 * `afterRebalance()`, and what the caller gets is a reason to look.
 */
class RebalanceIncomplete extends RuntimeException
{
    /**
     * @param string $table The table being rebalanced.
     * @param int $moved How many rows arrived.
     * @param int $failed How many were left behind.
     */
    public function __construct(
        public readonly string $table,
        public readonly int $moved,
        public readonly int $failed,
    ) {
        parent::__construct(sprintf(
            'Rebalancing %s left %d row(s) behind after moving %d. The routing metadata was not '
            . 'advanced, because moving it while rows are still on the old connection is what makes '
            . 'them unreachable. The log says which rows and why.',
            $table,
            $failed,
            $moved,
        ));
    }
}
