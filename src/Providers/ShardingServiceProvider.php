<?php

namespace Allnetru\Sharding\Providers;

use Allnetru\Sharding\Console\Commands\Shards\Distribute;
use Allnetru\Sharding\Console\Commands\Shards\Example;
use Allnetru\Sharding\Console\Commands\Shards\Migrate;
use Allnetru\Sharding\Console\Commands\Shards\Rebalance;
use Allnetru\Sharding\IdGenerator;
use Allnetru\Sharding\ShardingManager;
use Illuminate\Support\ServiceProvider;

/**
 * Bind sharding services, publish assets and register console commands.
 */
class ShardingServiceProvider extends ServiceProvider
{
    /**
     * Register services.
     */
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../../config/sharding.php', 'sharding');

        $this->app->singleton(ShardingManager::class, function () {
            return new ShardingManager(config('sharding'));
        });

        $this->app->singleton(IdGenerator::class, function () {
            return new IdGenerator(config('sharding'));
        });
    }

    /**
     * Bootstrap package services.
     */
    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__.'/../../config/sharding.php' => config_path('sharding.php'),
            ], 'laravel-sharding-config');

            $this->publishes([
                __DIR__.'/../../database/migrations/' => database_path('migrations'),
            ], 'laravel-sharding-migrations');

            $this->commands([
                Distribute::class,
                Example::class,
                Migrate::class,
                Rebalance::class,
            ]);
        }

        $this->loadMigrationsFrom(__DIR__.'/../../database/migrations');
    }
}
