<?php

namespace Allnetru\Sharding\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Sequence tracker for table-specific identifiers.
 *
 * @property string $table
 * @property int $last_id
 */
class ShardSequence extends Model
{
    protected $table = 'shard_sequences';

    protected $primaryKey = 'table';

    public $incrementing = false;

    protected $keyType = 'string';

    public $timestamps = false;

    protected $guarded = [];

    protected $casts = [
        'last_id' => 'int',
    ];
}
