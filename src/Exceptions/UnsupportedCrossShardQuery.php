<?php

namespace Allnetru\Sharding\Exceptions;

use RuntimeException;

/**
 * A query whose answer cannot be assembled from the shards.
 *
 * Most of what Eloquent does survives being split: rows merge, counts add up,
 * the smallest of the smallest is the smallest. What does not survive is
 * anything whose result depends on seeing every row at once — a grouped
 * aggregate, a distinct count, a join across connections.
 *
 * Those throw rather than answer. A wrong number is worse than an error,
 * because a number gets believed: this package has already shipped a release
 * where count() returned 0 over an existing row and nothing failed.
 */
class UnsupportedCrossShardQuery extends RuntimeException
{
}
