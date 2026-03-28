<?php

namespace PTeal79\MobileFileCache;

use Illuminate\Support\ServiceProvider;
use PTeal79\MobileFileCache\Console\Commands\CacheStatsCommand;
use PTeal79\MobileFileCache\Console\Commands\PurgeCacheCommand;
use PTeal79\MobileFileCache\Console\Commands\PurgeOldCacheCommand;
use PTeal79\MobileFileCache\Services\FileDownloadService;
use PTeal79\MobileFileCache\Services\MobileFileCacheService;

class MobileFileCacheServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(
            __DIR__ . '/../config/mobile-file-cache.php',
            'mobile-file-cache'
        );

        $this->app->singleton(FileDownloadService::class);

        $this->app->singleton(MobileFileCacheService::class, function ($app) {
            return new MobileFileCacheService(
                $app->make(FileDownloadService::class)
            );
        });
    }

    public function boot(): void
    {
        // Config publishing
        $this->publishes([
            __DIR__ . '/../config/mobile-file-cache.php' => config_path('mobile-file-cache.php'),
        ], 'mobile-file-cache-config');

        // Migration publishing
        $this->publishes([
            __DIR__ . '/../database/migrations/' => database_path('migrations'),
        ], 'mobile-file-cache-migrations');

        // Load migrations directly (so apps don't have to publish them)
        $this->loadMigrationsFrom(__DIR__ . '/../database/migrations');

        // Routes
        $this->loadRoutesFrom(__DIR__ . '/../routes/web.php');

        // Artisan commands
        if ($this->app->runningInConsole()) {
            $this->commands([
                PurgeCacheCommand::class,
                PurgeOldCacheCommand::class,
                CacheStatsCommand::class,
            ]);
        }
    }
}
