<?php

namespace dndark\LogicMap\Tests;

use dndark\LogicMap\LogicMapServiceProvider;
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
    }

    protected function defineEnvironment($app)
    {
        // Setup default config if needed
    }
}
