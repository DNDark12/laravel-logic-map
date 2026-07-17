<?php

namespace DNDark\LogicMap\Analysis\Laravel\Boot;

use Illuminate\Foundation\Application;

interface BootCollector
{
    public function name(): string;

    public function collect(Application $application): BootCollectionResult;
}
