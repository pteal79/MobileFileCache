<?php

namespace PTeal79\MobileFileCache;

use Illuminate\Support\ServiceProvider;
use PTeal79\MobileFileCache\Console\CleanUpFileCacheCommand;

class MobileFileCacheServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__ . '/../config/mobile-file-cache.php', 'mobile-file-cache');

        $this->app->singleton('mobile-file-cache', function ($app) {
            return new FileCacheManager();
        });
    }

    public function boot(): void
    {
        $this->publishes([
            __DIR__ . '/../config/mobile-file-cache.php' => config_path('mobile-file-cache.php'),
        ], 'mobile-file-cache-config');

        $migrationPath = __DIR__ . '/../database/migrations';
        $migrationFiles = glob($migrationPath . '/*.php') ?: [];
        $publishMigrations = [];

        foreach ($migrationFiles as $migrationFile) {
            $publishMigrations[$migrationFile] = database_path('migrations/' . basename($migrationFile));
        }

        if ($publishMigrations !== []) {
            $this->publishes($publishMigrations, 'mobile-file-cache-migrations');
        }

        $this->loadMigrationsFrom($migrationPath);

        if ($this->app->runningInConsole()) {
            $this->commands([
                CleanUpFileCacheCommand::class,
            ]);
        }
    }
}
