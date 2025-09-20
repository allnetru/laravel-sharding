<?php

namespace Allnetru\Sharding\Models;

use Allnetru\Sharding\Models\Concerns\Shardable;
use Illuminate\Database\Eloquent\Model;

/**
 * Example model used for sharding tests.
 */
class ShardTest extends Model
{
    use Shardable;

    protected $table = 'shard_tests';

    protected $guarded = [];

    protected $casts = [
        'is_replica' => 'bool',
    ];
}
