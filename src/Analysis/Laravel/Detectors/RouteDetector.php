<?php

namespace DNDark\LogicMap\Analysis\Laravel\Detectors;

use DNDark\LogicMap\Analysis\Facts\SemanticFact;
use DNDark\LogicMap\Analysis\Laravel\Boot\BootFact;
use DNDark\LogicMap\Analysis\Laravel\SemanticEdgeFactory;
use DNDark\LogicMap\Analysis\Php\ParsedFile;
use DNDark\LogicMap\Analysis\Php\SymbolTable;
use DNDark\LogicMap\Domain\Graph\Certainty;
use DNDark\LogicMap\Domain\Graph\EdgeType;
use DNDark\LogicMap\Domain\Graph\EvidenceOrigin;
use DNDark\LogicMap\Domain\Graph\GraphNode;
use DNDark\LogicMap\Domain\Graph\KnowledgeGraph;
use DNDark\LogicMap\Domain\Graph\NodeId;
use DNDark\LogicMap\Domain\Graph\NodeKind;
use DNDark\LogicMap\Domain\Graph\SourceLocation;
use DNDark\LogicMap\Domain\Snapshot\Diagnostic;
use DNDark\LogicMap\Domain\Snapshot\DiagnosticCode;

final class RouteDetector
{
    public function detect(
        array $files,
        SymbolTable $symbols,
        array $bootFacts,
        KnowledgeGraph $graph,
    ): array {
        $diagnostics = [];
        $static = $this->staticRegistrations($files);
        $effectiveStatic = [];
        $effectiveBoot = [];

        foreach ($static as $registration) {
            $fact = $registration['fact'];

            if (($fact->attributes['dynamic'] ?? true) === true) {
                $diagnostics[] = new Diagnostic(
                    DiagnosticCode::DynamicRouteAction,
                    'laravel_semantics',
                    $fact->file,
                    $fact->startLine,
                    $fact->endLine,
                    'Static route registration has a dynamic URI or action.',
                    ['registration_key' => $registration['registration_key']],
                );

                continue;
            }

            foreach ($fact->attributes['methods'] as $method) {
                $observation = [
                    'method' => $method,
                    'uri' => $this->normalizeUri($fact->attributes['uri']),
                    'name' => $registration['name'],
                    'action_class' => $fact->attributes['action_class'],
                    'action_method' => $fact->attributes['action_method'],
                    'middleware' => $registration['middleware'],
                    'registration_key' => $registration['registration_key'],
                    'location' => new SourceLocation($fact->file, $fact->startLine, $fact->endLine),
                    'expression' => $fact->attributes['expression'] ?? null,
                ];
                $effectiveStatic[$this->routeKey($method, $observation['uri'])] = $observation;
                $this->emit($observation, EvidenceOrigin::StaticAst, $symbols, $graph);
            }
        }

        foreach ($bootFacts as $fact) {
            if (! $fact instanceof BootFact || $fact->kind !== 'route') {
                continue;
            }

            foreach ($fact->attributes['methods'] ?? [] as $method) {
                $observation = [
                    'method' => $method,
                    'uri' => $this->normalizeUri($fact->attributes['uri']),
                    'name' => $fact->attributes['name'] ?? null,
                    'action_class' => $fact->attributes['action_class'],
                    'action_method' => $fact->attributes['action_method'],
                    'middleware' => $fact->attributes['middleware'] ?? [],
                    'registration_key' => implode(':', [
                        'route',
                        $method,
                        $fact->attributes['uri'],
                        $fact->attributes['action_class'].'::'.$fact->attributes['action_method'],
                    ]),
                    'location' => null,
                    'expression' => null,
                ];
                $effectiveBoot[$this->routeKey($method, $observation['uri'])] = $observation;
                $this->emit($observation, EvidenceOrigin::LaravelBoot, $symbols, $graph);
            }
        }

        foreach (array_intersect(array_keys($effectiveStatic), array_keys($effectiveBoot)) as $key) {
            $left = $effectiveStatic[$key];
            $right = $effectiveBoot[$key];
            $leftMiddleware = $left['middleware'];
            $rightMiddleware = $right['middleware'];
            sort($leftMiddleware, SORT_STRING);
            sort($rightMiddleware, SORT_STRING);

            if ($left['action_class'] === $right['action_class']
                && $left['action_method'] === $right['action_method']
                && $leftMiddleware === $rightMiddleware) {
                continue;
            }

            $diagnostics[] = new Diagnostic(
                DiagnosticCode::RouteRegistrationMismatch,
                'laravel_semantics',
                $left['location']->file,
                $left['location']->startLine,
                $left['location']->endLine,
                'Static and effective Laravel route registrations disagree.',
                ['route' => $key],
            );
        }

        return $diagnostics;
    }

    private function staticRegistrations(array $files): array
    {
        $registrations = [];

        foreach ($files as $file) {
            if (! $file instanceof ParsedFile) {
                continue;
            }

            foreach ($file->facts('laravel_route_registration') as $fact) {
                $key = $fact->attributes['registration_key'];
                $registrations[$key] = [
                    'registration_key' => $key,
                    'fact' => $fact,
                    'name' => null,
                    'middleware' => [],
                ];
            }
        }

        foreach ($files as $file) {
            if (! $file instanceof ParsedFile) {
                continue;
            }

            foreach ($file->facts('laravel_route_chain') as $fact) {
                $key = $fact->attributes['registration_key'];

                if (! isset($registrations[$key])) {
                    continue;
                }

                if (($fact->attributes['operation'] ?? null) === 'name') {
                    $registrations[$key]['name'] = $fact->attributes['name'] ?? null;
                }

                if (($fact->attributes['operation'] ?? null) === 'middleware'
                    && is_array($fact->attributes['middleware'] ?? null)) {
                    $registrations[$key]['middleware'] = [
                        ...$registrations[$key]['middleware'],
                        ...$fact->attributes['middleware'],
                    ];
                }
            }
        }

        ksort($registrations, SORT_STRING);

        return array_values($registrations);
    }

    private function emit(
        array $observation,
        EvidenceOrigin $origin,
        SymbolTable $symbols,
        KnowledgeGraph $graph,
    ): void {
        $targets = $symbols->methods($observation['action_class'], $observation['action_method']);

        if (count($targets) !== 1) {
            return;
        }

        $routeId = NodeId::route($observation['method'], $observation['uri']);

        if (! $graph->hasNode($routeId)) {
            $graph->addNode(new GraphNode(
                $routeId,
                NodeKind::Route,
                $observation['name'] ?? $observation['method'].' '.$observation['uri'],
                null,
                $observation['location'],
                [
                    'method' => $observation['method'],
                    'uri' => $observation['uri'],
                    'name' => $observation['name'],
                ],
            ));
        }

        $target = $targets[0]->id;
        $identity = implode(':', [
            'route',
            $observation['method'],
            $observation['uri'],
            $observation['action_class'].'::'.$observation['action_method'],
        ]);
        SemanticEdgeFactory::add(
            $graph,
            $routeId,
            EdgeType::HandlesRoute,
            $target,
            $origin,
            'route_detector',
            Certainty::Certain,
            $observation['location'],
            $observation['expression'],
            $observation['registration_key'],
            $identity,
            ['effective' => $origin === EvidenceOrigin::LaravelBoot],
        );

        foreach ($observation['middleware'] as $middleware) {
            if (! is_string($middleware) || trim($middleware) === '') {
                continue;
            }

            $middlewareId = NodeId::named(NodeKind::Middleware, $middleware);

            if (! $graph->hasNode($middlewareId)) {
                $graph->addNode(new GraphNode(
                    $middlewareId,
                    NodeKind::Middleware,
                    $middleware,
                    null,
                    null,
                ));
            }

            SemanticEdgeFactory::add(
                $graph,
                $routeId,
                EdgeType::AppliesMiddleware,
                $middlewareId,
                $origin,
                'route_detector',
                Certainty::Certain,
                $observation['location'],
                $observation['expression'],
                $observation['registration_key'],
                $identity.':middleware:'.$middleware,
                ['middleware' => $middleware, 'effective' => $origin === EvidenceOrigin::LaravelBoot],
            );
        }
    }

    private function routeKey(string $method, string $uri): string
    {
        return strtoupper($method).' '.trim($uri, '/');
    }

    private function normalizeUri(string $uri): string
    {
        $uri = trim($uri, '/');

        return $uri === '' ? '/' : $uri;
    }
}
