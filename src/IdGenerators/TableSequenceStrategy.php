<?php

namespace Allnetru\Sharding\IdGenerators;

use Allnetru\Sharding\Models\ShardSequence;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/**
 * ID generator that increments values in a database table.
 */
class TableSequenceStrategy implements Strategy
{
    /**
     * Generate the next sequence value for a table.
     *
     * @param array<string, mixed> $config
     * @return int
     */
    public function generate(array $config): int
    {
        $connection = $config['meta_connection'] ?? config('database.default');
        $sequenceTable = $config['sequence_table'] ?? 'shard_sequences';
        $table = $config['table'];

        return DB::connection($connection)->transaction(function () use ($sequenceTable, $table, $connection) {
            $sequence = ShardSequence::on($connection)
                ->from($sequenceTable)
                ->where('table', $table)
                ->lockForUpdate()
                ->first();

            if ($sequence) {
                $sequence->setTable($sequenceTable);
                $sequence->last_id++;
                $sequence->save();

                return $sequence->last_id;
            }

            try {
                $sequence = new ShardSequence(['table' => $table, 'last_id' => 1]);
                $sequence->setConnection($connection);
                $sequence->setTable($sequenceTable);
                $sequence->save();

                return 1;
            } catch (QueryException $e) {
                if ($e->getCode() !== '23000') {
                    throw $e;
                }

                $sequence = ShardSequence::on($connection)
                    ->from($sequenceTable)
                    ->where('table', $table)
                    ->lockForUpdate()
                    ->firstOrFail();
                $sequence->setTable($sequenceTable);
                $sequence->last_id++;
                $sequence->save();

                return $sequence->last_id;
            }
        });
    }
}
