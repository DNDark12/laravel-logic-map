<?php

namespace DNDark\LogicMap\Analysis\Laravel\Boot;

use Closure;
use DateTimeZone;
use DNDark\LogicMap\Domain\Snapshot\Diagnostic;
use DNDark\LogicMap\Domain\Snapshot\DiagnosticCode;
use Illuminate\Console\Scheduling\CallbackEvent;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Application;
use ReflectionClass;

final class ScheduleBootCollector implements BootCollector
{
    public function name(): string
    {
        return 'schedule';
    }

    public function collect(Application $application): BootCollectionResult
    {
        $facts = [];
        $diagnostics = [];

        foreach ($application->make(Schedule::class)->events() as $event) {
            $target = $this->target($event);

            if ($event instanceof CallbackEvent && $target === null) {
                $diagnostics[] = new Diagnostic(
                    DiagnosticCode::DynamicClassString,
                    'laravel_boot',
                    null,
                    null,
                    null,
                    'Scheduled callback has no stable class target.',
                    [
                        'collector' => $this->name(),
                        'description' => $event->description,
                    ],
                );
            }

            $facts[] = new BootFact('schedule', $this->name(), [
                'description' => $event->description,
                'expression' => $event->expression,
                'timezone' => $event->timezone instanceof DateTimeZone
                    ? $event->timezone->getName()
                    : $event->timezone,
                'command' => $this->commandName($event->command),
                'target_class' => $target['class'] ?? null,
                'target_method' => $target['method'] ?? null,
                'without_overlapping' => (bool) $event->withoutOverlapping,
                'on_one_server' => (bool) $event->onOneServer,
                'run_in_background' => (bool) $event->runInBackground,
            ]);
        }

        usort($facts, static fn (BootFact $left, BootFact $right): int => [
            $left->attributes['description'] ?? '',
            $left->attributes['expression'] ?? '',
            $left->attributes['target_class'] ?? '',
        ] <=> [
            $right->attributes['description'] ?? '',
            $right->attributes['expression'] ?? '',
            $right->attributes['target_class'] ?? '',
        ]);

        return new BootCollectionResult($facts, $diagnostics);
    }

    private function target(Event $event): ?array
    {
        if (! $event instanceof CallbackEvent) {
            return null;
        }

        $property = (new ReflectionClass(CallbackEvent::class))->getProperty('callback');
        $callback = $property->getValue($event);

        if (is_string($callback)) {
            $parts = preg_split('/@|::/', $callback, 2);

            return count($parts) === 2
                ? ['class' => ltrim($parts[0], '\\'), 'method' => $parts[1]]
                : null;
        }

        if (is_array($callback) && count($callback) === 2 && is_string($callback[1])) {
            $class = is_object($callback[0]) ? $callback[0]::class : $callback[0];

            return is_string($class)
                ? ['class' => ltrim($class, '\\'), 'method' => $callback[1]]
                : null;
        }

        if (is_object($callback) && ! $callback instanceof Closure) {
            return ['class' => $callback::class, 'method' => '__invoke'];
        }

        return null;
    }

    private function commandName(?string $command): ?string
    {
        if ($command === null) {
            return null;
        }

        if (preg_match('/artisan[\'\"]?\s+[\'\"]?([A-Za-z0-9:_-]+)/', $command, $matches) === 1) {
            return $matches[1];
        }

        return preg_match('/^([A-Za-z0-9:_-]+)/', trim($command), $matches) === 1
            ? $matches[1]
            : null;
    }
}
