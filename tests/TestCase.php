<?php

namespace PTeal79\MobileFileCache\Tests;

use Orchestra\Testbench\TestCase as Orchestra;
use PTeal79\MobileFileCache\MobileFileCacheServiceProvider;

abstract class TestCase extends Orchestra
{
    protected function getPackageProviders($app): array
    {
        return [
            MobileFileCacheServiceProvider::class,
        ];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('database.default', 'testing');
        $app['config']->set('database.connections.testing', [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
        ]);

        $app['config']->set('filesystems.disks.mobile_public', [
            'driver' => 'local',
            'root' => __DIR__ . '/../workbench/mobile_public',
            'url' => 'http://localhost/storage',
            'visibility' => 'public',
        ]);
    }
}
