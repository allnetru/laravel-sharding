<?php

namespace Allnetru\Sharding\Console\Commands\Shards;

use Allnetru\Sharding\ShardingManager;
use Illuminate\Console\Command;

/**
 * Demonstrate shard resolution and queries for a model.
 */
class Example extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'shards:example {table} {id}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Demonstrate shard resolution and model queries for a given table and id';

    /**
     * Execute the console command.
     *
     * @param  ShardingManager  $manager
     * @return int
     */
    public function handle(ShardingManager $manager): int
    {
        $table = $this->argument('table');
        $id = $this->argument('id');
        $connections = $manager->connectionFor($table, $id);
        $group = $manager->groupFor($table);

        $this->info("Record {$id} of table {$table} should use connection: {$connections[0]}");
        if ($group) {
            $this->line("Table {$table} belongs to group {$group}.");
        }

        $this->line('Examples:');
        $this->line('  User::find(15);');
        $this->line('  Organization::where(\'status\', OrganizationStatus::partner)->paginate(50);');

        // Avoid executing queries here; these are illustrative only

        return self::SUCCESS;
    }
}
