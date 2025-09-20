<?php

namespace Allnetru\Sharding\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Persistent hash slot assignment for shard strategy.
 */
class ShardSlot extends Model
{
    protected $table = 'shard_slots';

    protected $guarded = [];

    protected $casts = [
        'replicas' => 'array',
    ];
}
