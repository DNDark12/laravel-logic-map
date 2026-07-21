<?php

namespace DNDark\LogicMap\Tests;

use DNDark\LogicMap\LogicMapServiceProvider;
use Orchestra\Testbench\TestCase as Orchestra;

abstract class TestCase extends Orchestra
{
    protected function getPackageProviders($app)
    {
        return [
            LogicMapServiceProvider::class,
        ];
    }

    protected function getEnvironmentSetUp($app)
    {
        $app['config']->set('app.key', 'base64:u8V8Y8y8Y8y8Y8y8Y8y8Y8y8Y8y8Y8y8Y8y8Y8y8Y8y=');

        // Snapshots live in the application's database. Tests run against an
        // isolated in-memory SQLite connection; production/staging use whatever
        // the host app configures (MySQL, Postgres, ...).
        $app['config']->set('database.default', 'testing');
        $app['config']->set('database.connections.testing', [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
            'foreign_key_constraints' => true,
        ]);
        $app['config']->set('logic-map.storage.connection', null);
    }

    protected function defineDatabaseMigrations(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');
    }
}
