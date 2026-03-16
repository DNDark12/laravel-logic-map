<?php

namespace dndark\LogicMap\Tests;

use dndark\LogicMap\LogicMapServiceProvider;
use Orchestra\Testbench\TestCase as Orchestra;

class TestCase extends Orchestra
{
    protected function getPackageProviders($app)
    {
        return [
            LogicMapServiceProvider::class,
        ];
    }

    protected function getEnvironmentSetUp($app)
    {
        // Set app key for encryption
        $app['config']->set('app.key', 'base64:'.base64_encode(random_bytes(32)));

        // Configure scan paths
        $app['config']->set('logic-map.scan_paths', [
            __DIR__ . '/../src',
        ]);

        // Use file cache driver
        $app['config']->set('cache.default', 'array');
    }

    protected function defineDatabaseMigrations()
    {
        // No database needed for package tests
    }
}
