<?php

namespace DNDark\LogicMap\Analysis\Laravel\Boot;

use Closure;
use DNDark\LogicMap\Domain\Snapshot\Diagnostic;
use DNDark\LogicMap\Domain\Snapshot\DiagnosticCode;
use Illuminate\Container\Container;
use Illuminate\Foundation\Application;
use ReflectionClass;
use ReflectionFunction;

final class ContainerBootCollector implements BootCollector
{
    public function name(): string
    {
        return 'container';
    }

    public function collect(Application $application): BootCollectionResult
    {
        $facts = [];
        $diagnostics = [];
        $bindings = $application->getBindings();
        ksort($bindings, SORT_STRING);

        foreach ($bindings as $abstract => $binding) {
            $concrete = $this->classStringConcrete($binding['concrete'] ?? null);

            if ($concrete === null) {
                $diagnostics[] = new Diagnostic(
                    DiagnosticCode::ClosureContainerBinding,
                    'laravel_boot',
                    null,
                    null,
                    null,
                    'Container binding concrete cannot be recovered without resolution.',
                    [
                        'collector' => $this->name(),
                        'abstract' => (string) $abstract,
                    ],
                );

                continue;
            }

            $facts[] = new BootFact('container_binding', $this->name(), [
                'abstract' => ltrim((string) $abstract, '\\'),
                'concrete' => ltrim($concrete, '\\'),
                'shared' => (bool) ($binding['shared'] ?? false),
            ]);
        }

        foreach ($this->aliases($application) as $alias => $abstract) {
            $facts[] = new BootFact('container_alias', $this->name(), [
                'alias' => ltrim((string) $alias, '\\'),
                'abstract' => ltrim((string) $abstract, '\\'),
            ]);
        }

        return new BootCollectionResult($facts, $diagnostics);
    }

    private function classStringConcrete(mixed $concrete): ?string
    {
        if (is_string($concrete)) {
            return $concrete;
        }

        if (! $concrete instanceof Closure) {
            return null;
        }

        $variables = (new ReflectionFunction($concrete))->getStaticVariables();

        return isset($variables['concrete']) && is_string($variables['concrete'])
            ? $variables['concrete']
            : null;
    }

    private function aliases(Application $application): array
    {
        $property = (new ReflectionClass(Container::class))->getProperty('aliases');
        $aliases = $property->getValue($application);

        if (! is_array($aliases)) {
            return [];
        }

        ksort($aliases, SORT_STRING);

        return $aliases;
    }
}
