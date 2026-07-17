<?php

namespace DNDark\LogicMap\Analysis\Laravel\Boot;

use Closure;
use DNDark\LogicMap\Domain\Snapshot\Diagnostic;
use DNDark\LogicMap\Domain\Snapshot\DiagnosticCode;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Foundation\Application;

final class EventBootCollector implements BootCollector
{
    public function name(): string
    {
        return 'events';
    }

    public function collect(Application $application): BootCollectionResult
    {
        $dispatcher = $application->make(Dispatcher::class);
        $listeners = $dispatcher->getRawListeners();
        $facts = [];
        $diagnostics = [];
        ksort($listeners, SORT_STRING);

        foreach ($listeners as $event => $registeredListeners) {
            foreach ($registeredListeners as $listener) {
                $target = $this->listenerTarget($listener);

                if ($target === null) {
                    $diagnostics[] = new Diagnostic(
                        DiagnosticCode::DynamicClassString,
                        'laravel_boot',
                        null,
                        null,
                        null,
                        'Event listener has no stable class target.',
                        ['collector' => $this->name(), 'event' => (string) $event],
                    );

                    continue;
                }

                $facts[] = new BootFact('event_listener', $this->name(), [
                    'event' => ltrim((string) $event, '\\'),
                    'listener' => ltrim($target['class'], '\\'),
                    'method' => $target['method'],
                ]);
            }
        }

        usort($facts, static fn (BootFact $left, BootFact $right): int => [
            $left->attributes['event'],
            $left->attributes['listener'],
            $left->attributes['method'],
        ] <=> [
            $right->attributes['event'],
            $right->attributes['listener'],
            $right->attributes['method'],
        ]);

        return new BootCollectionResult($facts, $diagnostics);
    }

    private function listenerTarget(mixed $listener): ?array
    {
        if (is_string($listener)) {
            $parts = preg_split('/@|::/', $listener, 2);

            return ['class' => $parts[0], 'method' => $parts[1] ?? 'handle'];
        }

        if (is_array($listener) && count($listener) === 2 && is_string($listener[1])) {
            $class = is_object($listener[0]) ? $listener[0]::class : $listener[0];

            return is_string($class) ? ['class' => $class, 'method' => $listener[1]] : null;
        }

        if (is_object($listener) && ! $listener instanceof Closure) {
            return ['class' => $listener::class, 'method' => '__invoke'];
        }

        return null;
    }
}
