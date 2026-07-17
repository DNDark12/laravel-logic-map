<?php

namespace DNDark\LogicMap\Analysis\Laravel\Boot;

use Closure;
use DNDark\LogicMap\Domain\Snapshot\Diagnostic;
use DNDark\LogicMap\Domain\Snapshot\DiagnosticCode;
use Illuminate\Foundation\Application;
use Illuminate\Routing\Router;

final class RouteBootCollector implements BootCollector
{
    public function name(): string
    {
        return 'routes';
    }

    public function collect(Application $application): BootCollectionResult
    {
        $facts = [];
        $diagnostics = [];
        $routes = $application->make(Router::class)->getRoutes()->getRoutes();

        foreach ($routes as $route) {
            $action = $route->getAction('uses');
            $actionClass = $route->getControllerClass();

            if ($action instanceof Closure || $actionClass === null) {
                $diagnostics[] = new Diagnostic(
                    DiagnosticCode::DynamicRouteAction,
                    'laravel_boot',
                    null,
                    null,
                    null,
                    'Route action has no stable controller class.',
                    [
                        'collector' => $this->name(),
                        'uri' => $route->uri(),
                        'name' => $route->getName(),
                    ],
                );

                continue;
            }

            $methods = array_values(array_filter(
                $route->methods(),
                static fn (string $method): bool => $method !== 'HEAD' || ! in_array('GET', $route->methods(), true),
            ));

            $facts[] = new BootFact('route', $this->name(), [
                'methods' => $methods,
                'uri' => $route->uri(),
                'name' => $route->getName(),
                'action_class' => ltrim($actionClass, '\\'),
                'action_method' => $route->getActionMethod(),
                'middleware' => $this->middlewareNames($route->middleware()),
                'domain' => $route->getDomain(),
            ]);
        }

        usort($facts, static fn (BootFact $left, BootFact $right): int => [
            $left->attributes['name'] ?? '',
            $left->attributes['uri'] ?? '',
            implode(',', $left->attributes['methods'] ?? []),
        ] <=> [
            $right->attributes['name'] ?? '',
            $right->attributes['uri'] ?? '',
            implode(',', $right->attributes['methods'] ?? []),
        ]);

        return new BootCollectionResult($facts, $diagnostics);
    }

    private function middlewareNames(array $middleware): array
    {
        return array_values(array_map(
            static fn (mixed $value): string => is_string($value) ? $value : $value::class,
            $middleware,
        ));
    }
}
