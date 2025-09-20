<?php

namespace Allnetru\Sharding\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Metadata model storing shard range assignments.
 */
class ShardRange extends Model
{
    protected $table = 'shard_ranges';

    protected $guarded = [];

    protected $casts = [
        'replicas' => 'array',
    ];
}
