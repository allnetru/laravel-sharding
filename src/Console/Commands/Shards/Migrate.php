<?php

namespace Allnetru\Sharding\Console\Commands\Shards;

use Illuminate\Console\Command;

/**
 * Run migrations on the default and shard connections.
 */
class Migrate extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'shards:migrate {--pretend} {--force} {--path=database/migrations/shards}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Run migrations on all shard connections.';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $path = $this->option('path');

        foreach ($this->connections() as $connection) {
            $this->info("Migrating on {$connection}");
            $this->call('migrate', [
                '--database' => $connection,
                '--path' => $path,
                '--pretend' => $this->option('pretend'),
                '--force' => $this->option('force'),
            ]);
        }

        return self::SUCCESS;
    }

    /**
     * Get all shard connection names.
     *
     * @return array<int, string>
     */
    protected function connections(): array
    {
        $connections = array_keys(config('sharding.connections', []));

        foreach (config('sharding.tables', []) as $table) {
            $connections = array_merge($connections, array_keys($table['connections'] ?? []));
        }

        return array_values(array_unique($connections));
    }
}
