<?php

namespace DNDark\LogicMap\Analysis\Laravel\Detectors;

use DNDark\LogicMap\Analysis\Laravel\Boot\BootFact;
use DNDark\LogicMap\Analysis\Laravel\SemanticEdgeFactory;
use DNDark\LogicMap\Analysis\Php\SymbolTable;
use DNDark\LogicMap\Domain\Graph\Certainty;
use DNDark\LogicMap\Domain\Graph\EdgeType;
use DNDark\LogicMap\Domain\Graph\EvidenceOrigin;
use DNDark\LogicMap\Domain\Graph\GraphNode;
use DNDark\LogicMap\Domain\Graph\KnowledgeGraph;
use DNDark\LogicMap\Domain\Graph\NodeId;
use DNDark\LogicMap\Domain\Graph\NodeKind;
use DNDark\LogicMap\Domain\Snapshot\Diagnostic;
use DNDark\LogicMap\Domain\Snapshot\DiagnosticCode;

final class ScheduleDetector
{
    public function detect(array $bootFacts, SymbolTable $symbols, KnowledgeGraph $graph): array
    {
        $diagnostics = [];

        foreach ($bootFacts as $fact) {
            if (! $fact instanceof BootFact || $fact->kind !== 'schedule') {
                continue;
            }

            $target = $this->target($fact, $symbols);

            if ($target === null) {
                $diagnostics[] = new Diagnostic(
                    DiagnosticCode::DynamicClassString,
                    'laravel_semantics',
                    null,
                    null,
                    null,
                    'Scheduled callback has no stable in-scope target.',
                    ['description' => $fact->attributes['description'] ?? null],
                );

                continue;
            }

            $key = $this->scheduleKey($fact);
            $scheduleId = NodeId::named(NodeKind::Schedule, $key);

            if (! $graph->hasNode($target) && str_starts_with($target->value, 'command:')) {
                $name = substr($target->value, strlen('command:'));
                $graph->addNode(new GraphNode($target, NodeKind::Command, $name, null, null));
            }

            if (! $graph->hasNode($scheduleId)) {
                $graph->addNode(new GraphNode(
                    $scheduleId,
                    NodeKind::Schedule,
                    $fact->attributes['description'] ?? $key,
                    null,
                    null,
                    [
                        'expression' => $fact->attributes['expression'] ?? null,
                        'timezone' => $fact->attributes['timezone'] ?? null,
                        'without_overlapping' => (bool) ($fact->attributes['without_overlapping'] ?? false),
                        'on_one_server' => (bool) ($fact->attributes['on_one_server'] ?? false),
                        'run_in_background' => (bool) ($fact->attributes['run_in_background'] ?? false),
                    ],
                ));
            }

            $registrationKey = 'schedule:'.$key.':'.($fact->attributes['expression'] ?? '');
            SemanticEdgeFactory::add(
                $graph,
                $scheduleId,
                EdgeType::Schedules,
                $target,
                EvidenceOrigin::LaravelBoot,
                'schedule_detector',
                Certainty::Certain,
                null,
                null,
                $registrationKey,
                $registrationKey.':'.$target->value,
                [
                    'execution' => ($fact->attributes['run_in_background'] ?? false) ? 'async' : 'sync',
                    'expression' => $fact->attributes['expression'] ?? null,
                    'timezone' => $fact->attributes['timezone'] ?? null,
                    'without_overlapping' => (bool) ($fact->attributes['without_overlapping'] ?? false),
                    'on_one_server' => (bool) ($fact->attributes['on_one_server'] ?? false),
                    'run_in_background' => (bool) ($fact->attributes['run_in_background'] ?? false),
                ],
            );
        }

        return $diagnostics;
    }

    private function target(BootFact $fact, SymbolTable $symbols): ?NodeId
    {
        $class = $fact->attributes['target_class'] ?? null;
        $method = $fact->attributes['target_method'] ?? null;

        if (is_string($class) && is_string($method)) {
            $targets = $symbols->methods($class, $method);

            return count($targets) === 1 ? $targets[0]->id : null;
        }

        if (is_string($class)) {
            $targets = $symbols->exact($class);

            return count($targets) === 1 ? $targets[0]->id : null;
        }

        $command = $fact->attributes['command'] ?? null;

        if (! is_string($command) || trim($command) === '') {
            return null;
        }

        $commandName = explode(' ', trim($command))[0];
        $commandId = NodeId::named(NodeKind::Command, $commandName);

        return $commandId;
    }

    private function scheduleKey(BootFact $fact): string
    {
        $description = $fact->attributes['description'] ?? null;

        if (is_string($description) && trim($description) !== '') {
            return trim($description);
        }

        return hash('sha256', json_encode($fact->attributes, JSON_THROW_ON_ERROR));
    }
}
