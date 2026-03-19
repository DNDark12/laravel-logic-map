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

    protected function defineEnvironment($app)
    {
        // Setup default config if needed
    }
}
