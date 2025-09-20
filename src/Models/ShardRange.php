<?php

namespace Allnetru\Sharding\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Metadata model storing shard range assignments.
 *
 * @property string $table
 * @property int $start
 * @property int|null $end
 * @property string $connection
 * @property array<int, string>|null $replicas
 */
class ShardRange extends Model
{
    protected $table = 'shard_ranges';

    protected $guarded = [];

    protected $casts = [
        'replicas' => 'array',
    ];
}
