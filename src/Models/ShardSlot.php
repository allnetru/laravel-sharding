<?php

namespace Allnetru\Sharding\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Persistent hash slot assignment for shard strategy.
 *
 * @property string $table
 * @property int $slot
 * @property string $connection
 * @property array<int, string>|null $replicas
 */
class ShardSlot extends Model
{
    protected $table = 'shard_slots';

    protected $guarded = [];

    protected $casts = [
        'replicas' => 'array',
    ];
}
