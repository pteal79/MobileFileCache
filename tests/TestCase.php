<?php

namespace PTeal79\MobileFileCache\Tests;

use Illuminate\Database\Schema\Blueprint;
use Orchestra\Testbench\TestCase as OrchestraTestCase;
use PTeal79\MobileFileCache\MobileFileCacheServiceProvider;

abstract class TestCase extends OrchestraTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpDatabase();
        $this->setUpStorage();
    }

    protected function getPackageProviders($app): array
    {
        return [MobileFileCacheServiceProvider::class];
    }

    protected function getPackageAliases($app): array
    {
        return [
            'MobileFileCache' => \PTeal79\MobileFileCache\Facades\MobileFileCache::class,
        ];
    }

    protected function defineEnvironment($app): void
    {
        // Use in-memory SQLite for tests
        $app['config']->set('database.default', 'testbench');
        $app['config']->set('database.connections.testbench', [
            'driver'   => 'sqlite',
            'database' => ':memory:',
            'prefix'   => '',
        ]);

        // Use local disk pointing to a temp folder
        $app['config']->set('filesystems.disks.mobile_public', [
            'driver' => 'local',
            'root'   => storage_path('app/test_cache'),
            'url'    => '/test-storage',
        ]);

        $app['config']->set('mobile-file-cache.disk', 'mobile_public');
        $app['config']->set('mobile-file-cache.folder', 'cached_files');
        $app['config']->set('mobile-file-cache.max_file_size_mb', 30);
        $app['config']->set('mobile-file-cache.download_timeout', 10);
    }

    private function setUpDatabase(): void
    {
        $this->loadMigrationsFrom(__DIR__ . '/../database/migrations');
    }

    private function setUpStorage(): void
    {
        $disk = \Illuminate\Support\Facades\Storage::disk('mobile_public');

        // Ensure folder exists and is clean
        if ($disk->directoryExists('cached_files')) {
            $disk->deleteDirectory('cached_files');
        }
        $disk->makeDirectory('cached_files');
    }

    protected function tearDown(): void
    {
        // Clean up test storage
        try {
            \Illuminate\Support\Facades\Storage::disk('mobile_public')->deleteDirectory('cached_files');
        } catch (\Throwable) {}

        parent::tearDown();
    }
}
