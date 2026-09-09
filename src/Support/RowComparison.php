<?php

namespace Allnetru\Sharding\Support;

/**
 * Whether two copies of a primary key hold the same row.
 *
 * Asked wherever a row is about to be written over another one that already
 * carries its identifier, which is every repair this package performs.
 * `shards:distribute` and the rebalance both need it, and two copies of a
 * check that decides whether data may be deleted is one copy too many.
 *
 * The primary key alone does not settle it. Shards that allocated their
 * identifiers independently hold different rows under the same number — which
 * is exactly what adopting sharding over such databases looks like — and both
 * of them are real data.
 */
final class RowComparison
{
    /**
     * Whether the two rows are the same row.
     *
     * Compared as strings, because the two connections are two drivers'
     * opinions about what a column reads back as, and leniently in that
     * respect only: a missing column, a differing null, or any differing value
     * answers no. The answer decides whether a row may be deleted, so it errs
     * towards keeping both.
     *
     * `is_replica` is left out of it — which copy is primary is what is being
     * decided, not evidence about which row this is.
     *
     * @param array<string, mixed> $target The row already there.
     * @param array<string, mixed> $source The row being moved.
     * @return bool
     */
    public static function same(array $target, array $source): bool
    {
        unset($target['is_replica'], $source['is_replica']);

        if (array_keys($target) !== array_keys($source)) {
            return false;
        }

        foreach ($source as $column => $value) {
            $other = $target[$column];

            if ($value === null || $other === null) {
                if ($value !== $other) {
                    return false;
                }

                continue;
            }

            if ((string) $other !== (string) $value) {
                return false;
            }
        }

        return true;
    }
}
