<?php

namespace DNDark\LogicMap\Analysis\Laravel\Boot;

use Illuminate\Contracts\Console\Kernel as ConsoleKernel;
use Illuminate\Foundation\Application;

final class CommandBootCollector implements BootCollector
{
    public function name(): string
    {
        return 'commands';
    }

    public function collect(Application $application): BootCollectionResult
    {
        $commands = array_keys($application->make(ConsoleKernel::class)->all());
        sort($commands, SORT_STRING);

        return new BootCollectionResult(array_values(array_map(
            fn (string $name): BootFact => new BootFact('command', $this->name(), ['name' => $name]),
            $commands,
        )));
    }
}
