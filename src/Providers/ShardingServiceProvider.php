<?php

namespace Allnetru\Sharding\Providers;

use Allnetru\Sharding\Console\Commands\Shards\Distribute;
use Allnetru\Sharding\Console\Commands\Shards\Example;
use Allnetru\Sharding\Console\Commands\Shards\Migrate;
use Allnetru\Sharding\Console\Commands\Shards\Rebalance;
use Allnetru\Sharding\IdGenerator;
use Allnetru\Sharding\ShardingManager;
use Allnetru\Sharding\Support\RoutingMemo;
use Illuminate\Support\ServiceProvider;

/**
 * Bind sharding services, publish assets and register console commands.
 */
class ShardingServiceProvider extends ServiceProvider
{
    /**
     * Register services.
     *
     * @return void
     */
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__ . '/../../config/sharding.php', 'sharding');

        $this->app->singleton(ShardingManager::class, function () {
            return new ShardingManager(config('sharding'));
        });

        $this->app->singleton(IdGenerator::class, function () {
            return new IdGenerator(config('sharding'));
        });

        // a request's routing lookups, forgotten between requests and between queued jobs
        $this->app->scoped(RoutingMemo::class);
    }

    /**
     * Bootstrap package services.
     *
     * @return void
     */
    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__ . '/../../config/sharding.php' => config_path('sharding.php'),
            ], 'laravel-sharding-config');

            $this->publishes([
                __DIR__ . '/../../database/migrations/' => database_path('migrations'),
            ], 'laravel-sharding-migrations');

            $this->commands([
                Distribute::class,
                Example::class,
                Migrate::class,
                Rebalance::class,
            ]);
        }

        $this->loadMigrationsFrom(__DIR__ . '/../../database/migrations');
    }
}
