<?php

namespace DNDark\LogicMap\Analysis\Laravel;

use DNDark\LogicMap\Analysis\Laravel\Detectors\AuthorizationDetector;
use DNDark\LogicMap\Analysis\Laravel\Detectors\CacheEffectDetector;
use DNDark\LogicMap\Analysis\Laravel\Detectors\CommandDetector;
use DNDark\LogicMap\Analysis\Laravel\Detectors\ConfigEffectDetector;
use DNDark\LogicMap\Analysis\Laravel\Detectors\ContainerBindingDetector;
use DNDark\LogicMap\Analysis\Laravel\Detectors\EloquentEffectDetector;
use DNDark\LogicMap\Analysis\Laravel\Detectors\EventDispatchDetector;
use DNDark\LogicMap\Analysis\Laravel\Detectors\FormRequestDetector;
use DNDark\LogicMap\Analysis\Laravel\Detectors\HttpClientEffectDetector;
use DNDark\LogicMap\Analysis\Laravel\Detectors\JobDispatchDetector;
use DNDark\LogicMap\Analysis\Laravel\Detectors\ListenerDetector;
use DNDark\LogicMap\Analysis\Laravel\Detectors\MailDetector;
use DNDark\LogicMap\Analysis\Laravel\Detectors\NotificationDetector;
use DNDark\LogicMap\Analysis\Laravel\Detectors\QueryBuilderEffectDetector;
use DNDark\LogicMap\Analysis\Laravel\Detectors\RouteDetector;
use DNDark\LogicMap\Analysis\Laravel\Detectors\ScheduleDetector;
use DNDark\LogicMap\Analysis\Laravel\Detectors\StorageEffectDetector;
use DNDark\LogicMap\Analysis\Laravel\Detectors\TestReferenceDetector;
use DNDark\LogicMap\Analysis\Laravel\Detectors\ViewEffectDetector;
use DNDark\LogicMap\Analysis\Laravel\Facts\ControlFlowFactMapper;
use DNDark\LogicMap\Analysis\Laravel\Facts\TransactionBoundaryMapper;
use DNDark\LogicMap\Analysis\Php\ParsedFile;
use DNDark\LogicMap\Analysis\Php\SymbolTable;
use DNDark\LogicMap\Domain\Graph\KnowledgeGraph;
use InvalidArgumentException;

final class LaravelSemanticAnalyzer
{
    public function analyze(
        array $files,
        SymbolTable $symbols,
        array $bootFacts,
        KnowledgeGraph $graph,
    ): array {
        foreach ($files as $file) {
            if (! $file instanceof ParsedFile) {
                throw new InvalidArgumentException('Laravel semantic analysis requires parsed files.');
            }
        }

        $outputs = $this->mapControlFlow($files);
        $outputs['data_effects'] = [];
        $outputs['external_effects'] = [];
        $outputs['classifications'] = [];
        $outputs['module_assignments'] = [];
        $outputs['tests'] = [];
        $diagnostics = [];
        $metrics = [];
        $facadeFacts = $this->facts($files, 'facade_effect');

        $this->run($metrics, $diagnostics, 'route', $this->factCount($files, [
            'laravel_route_registration', 'laravel_route_chain',
        ]) + $this->bootFactCount($bootFacts, ['route']), $graph, function () use ($files, $symbols, $bootFacts, $graph): array {
            return [[], (new RouteDetector())->detect($files, $symbols, $bootFacts, $graph)];
        });
        $this->run($metrics, $diagnostics, 'container', $this->factCount($files, [
            'laravel_container_binding',
        ]) + $this->bootFactCount($bootFacts, ['container_binding', 'container_alias']), $graph, function () use ($files, $symbols, $bootFacts, $graph): array {
            return [[], (new ContainerBindingDetector())->detect($files, $symbols, $bootFacts, $graph)];
        });
        $this->run($metrics, $diagnostics, 'form-request', count($symbols->all()), $graph, function () use ($symbols, $graph): array {
            return [[], (new FormRequestDetector())->detect($symbols, $graph)];
        });
        $this->run($metrics, $diagnostics, 'authorization', $this->factCount($files, [
            'laravel_policy_registration',
        ]) + $this->bootFactCount($bootFacts, ['policy']), $graph, function () use ($files, $symbols, $bootFacts, $graph): array {
            return [[], (new AuthorizationDetector())->detect($files, $symbols, $bootFacts, $graph)];
        });
        $this->run($metrics, $diagnostics, 'event', $this->callCount($files), $graph, function () use ($files, $symbols, $graph): array {
            return [[], (new EventDispatchDetector())->detect($files, $symbols, $graph)];
        });
        $this->run($metrics, $diagnostics, 'job', $this->callCount($files), $graph, function () use ($files, $symbols, $graph): array {
            return [[], (new JobDispatchDetector())->detect($files, $symbols, $graph)];
        });
        $this->run($metrics, $diagnostics, 'listener', $this->callCount($files) + $this->bootFactCount($bootFacts, ['event_listener']), $graph, function () use ($files, $symbols, $bootFacts, $graph): array {
            return [[], (new ListenerDetector())->detect($files, $symbols, $bootFacts, $graph)];
        });
        $this->run($metrics, $diagnostics, 'schedule', $this->bootFactCount($bootFacts, ['schedule']), $graph, function () use ($bootFacts, $symbols, $graph): array {
            return [[], (new ScheduleDetector())->detect($bootFacts, $symbols, $graph)];
        });
        $this->run($metrics, $diagnostics, 'notification', $this->callCount($files), $graph, function () use ($files, $symbols, $graph): array {
            return [[], (new NotificationDetector())->detect($files, $symbols, $graph)];
        });
        $this->run($metrics, $diagnostics, 'mail', $this->callCount($files), $graph, function () use ($files, $symbols, $graph): array {
            return [[], (new MailDetector())->detect($files, $symbols, $graph)];
        });
        $this->run($metrics, $diagnostics, 'eloquent', $this->factCount($files, ['eloquent_chain']), $graph, function () use ($files, $symbols, $graph, &$outputs): array {
            $result = (new EloquentEffectDetector())->detect($files, $symbols, $graph);
            $outputs['data_effects'] = [...$outputs['data_effects'], ...$result->facts];

            return [$result->facts, $result->diagnostics];
        });
        $this->run($metrics, $diagnostics, 'query-builder', $this->factCount($files, ['eloquent_chain']), $graph, function () use ($files, $symbols, $graph, &$outputs): array {
            $result = (new QueryBuilderEffectDetector())->detect($files, $symbols, $graph);
            $outputs['data_effects'] = [...$outputs['data_effects'], ...$result->facts];

            return [$result->facts, $result->diagnostics];
        });

        $externalGraph = new ExternalEffectGraphBuilder();
        $externalDetectors = [
            'cache' => new CacheEffectDetector(),
            'config' => new ConfigEffectDetector(),
            'storage' => new StorageEffectDetector(),
            'view' => new ViewEffectDetector(),
            'http-client' => new HttpClientEffectDetector(),
        ];

        foreach ($externalDetectors as $name => $detector) {
            $family = $name === 'http-client' ? 'http' : $name;
            $factCount = count(array_filter(
                $facadeFacts,
                static fn ($fact): bool => ($fact->attributes['family'] ?? null) === $family,
            ));
            $this->run($metrics, $diagnostics, $name, $factCount, $graph, function () use ($detector, $facadeFacts, $externalGraph, $graph, &$outputs): array {
                $result = $detector->detect($facadeFacts);
                $externalGraph->build($result->facts, $graph);
                $outputs['external_effects'] = [...$outputs['external_effects'], ...$result->facts];

                return [$result->facts, $result->diagnostics];
            });
        }

        $this->run($metrics, $diagnostics, 'symbol-classifier', count($symbols->all()), $graph, function () use ($files, $symbols, $bootFacts, $graph, &$outputs): array {
            $outputs['classifications'] = (new SymbolClassifier(
                config('logic-map.classifier.namespace_conventions', []),
            ))->classify($files, $symbols, $bootFacts, $graph);

            return [$outputs['classifications'], []];
        });
        $this->run($metrics, $diagnostics, 'command', $this->bootFactCount($bootFacts, ['command']), $graph, function () use ($files, $symbols, $bootFacts, $graph): array {
            return [[], (new CommandDetector())->detect($files, $symbols, $bootFacts, $graph)];
        });
        $this->run($metrics, $diagnostics, 'module', count($symbols->all()), $graph, function () use ($symbols, $graph, &$outputs): array {
            $outputs['module_assignments'] = (new ModuleResolver(
                config('logic-map.modules.explicit', []),
                config('logic-map.modules.namespace_roots', []),
                config('logic-map.modules.directory_roots', []),
                config('logic-map.modules.fallback', 'Core'),
            ))->assign($symbols, $graph);

            return [$outputs['module_assignments'], []];
        });
        $this->run($metrics, $diagnostics, 'test-reference', $this->callCount($files), $graph, function () use ($files, $symbols, $graph, &$outputs): array {
            $result = (new TestReferenceDetector())->detect($files, $symbols, $graph);
            $outputs['tests'] = $result['tests'];

            return [$outputs['tests'], []];
        });

        return [
            'outputs' => $outputs,
            'diagnostics' => $diagnostics,
            'metrics' => $metrics,
        ];
    }

    private function mapControlFlow(array $files): array
    {
        $outputs = [
            'branches' => [],
            'throws' => [],
            'early_returns' => [],
            'transactions' => [],
        ];
        $control = new ControlFlowFactMapper();
        $transactions = new TransactionBoundaryMapper();

        foreach ($files as $file) {
            $mapped = $control->map($file);
            $outputs['branches'] = [...$outputs['branches'], ...$mapped['branches']];
            $outputs['throws'] = [...$outputs['throws'], ...$mapped['throws']];
            $outputs['early_returns'] = [...$outputs['early_returns'], ...$mapped['early_returns']];
            $outputs['transactions'] = [...$outputs['transactions'], ...$transactions->map($file)];
        }

        return $outputs;
    }

    private function run(
        array &$metrics,
        array &$diagnostics,
        string $name,
        int $factCount,
        KnowledgeGraph $graph,
        callable $detector,
    ): void {
        $edgeCount = count($graph->edges());
        $startedAt = hrtime(true);
        [$facts, $newDiagnostics] = $detector();
        $duration = (hrtime(true) - $startedAt) / 1_000_000;
        $diagnostics = [...$diagnostics, ...$newDiagnostics];
        $metrics[$name] = [
            'facts' => max($factCount, count($facts)),
            'edges' => count($graph->edges()) - $edgeCount,
            'diagnostics' => count($newDiagnostics),
            'duration_ms' => round($duration, 3),
        ];
    }

    private function facts(array $files, string $kind): array
    {
        $facts = [];

        foreach ($files as $file) {
            $facts = [...$facts, ...$file->facts($kind)];
        }

        return $facts;
    }

    private function factCount(array $files, array $kinds): int
    {
        return array_sum(array_map(fn (string $kind): int => count($this->facts($files, $kind)), $kinds));
    }

    private function bootFactCount(array $facts, array $kinds): int
    {
        return count(array_filter(
            $facts,
            static fn ($fact): bool => in_array($fact->kind ?? null, $kinds, true),
        ));
    }

    private function callCount(array $files): int
    {
        return array_sum(array_map(static fn (ParsedFile $file): int => count($file->callSites), $files));
    }
}
