<?php

namespace DNDark\LogicMap\Analysis\Laravel\Detectors;

use DNDark\LogicMap\Analysis\Facts\CallSiteFact;
use DNDark\LogicMap\Analysis\Laravel\SemanticEdgeFactory;
use DNDark\LogicMap\Analysis\Php\ParsedFile;
use DNDark\LogicMap\Analysis\Php\SymbolDefinition;
use DNDark\LogicMap\Analysis\Php\SymbolTable;
use DNDark\LogicMap\Domain\Graph\Certainty;
use DNDark\LogicMap\Domain\Graph\EdgeType;
use DNDark\LogicMap\Domain\Graph\EvidenceOrigin;
use DNDark\LogicMap\Domain\Graph\GraphNode;
use DNDark\LogicMap\Domain\Graph\KnowledgeGraph;
use DNDark\LogicMap\Domain\Graph\NodeId;
use DNDark\LogicMap\Domain\Graph\NodeKind;
use DNDark\LogicMap\Domain\Graph\SourceLocation;

final class TestReferenceDetector
{
    public function detect(array $files, SymbolTable $symbols, KnowledgeGraph $graph): array
    {
        $tests = [];
        $nodes = [];

        foreach ($graph->nodes() as $node) {
            $nodes[$node->id->value] = $node;
        }

        foreach ($files as $file) {
            if (! $file instanceof ParsedFile || ! str_starts_with($file->relativePath, 'tests/')) {
                continue;
            }

            foreach ($file->symbols as $symbol) {
                if ($symbol->structuralKind !== NodeKind::Method || ! str_starts_with(strtolower($symbol->name), 'test')) {
                    continue;
                }

                $test = $this->testNode($file, $symbol, $symbols, $graph);
                $tests[$test->id->value] = $test;
                $this->copyDirectReferences($symbol, $test->id, $graph);

                foreach ($file->callSites as $call) {
                    if (! $call->enclosingSymbolId->equals($symbol->id)) {
                        continue;
                    }

                    $this->detectCallReference($call, $test->id, $nodes, $graph);
                }
            }
        }

        ksort($tests, SORT_STRING);

        return ['tests' => array_values($tests)];
    }

    private function testNode(
        ParsedFile $file,
        SymbolDefinition $method,
        SymbolTable $symbols,
        KnowledgeGraph $graph,
    ): GraphNode {
        $owner = is_string($method->attributes['owner_id'] ?? null)
            ? $symbols->byId(NodeId::fromString($method->attributes['owner_id']))
            : [];
        $className = count($owner) === 1 ? $owner[0]->qualifiedName : null;
        $id = NodeId::named(NodeKind::Test, $file->relativePath.'::'.$method->name);
        $node = new GraphNode(
            $id,
            NodeKind::Test,
            ($className ?? basename($file->relativePath)).'::'.$method->name,
            $method->qualifiedName,
            $method->location,
            [
                'file' => $file->relativePath,
                'source_method_id' => $method->id->value,
                'module' => $this->module($className),
            ],
        );
        $graph->addNode($node);

        return $node;
    }

    private function copyDirectReferences(SymbolDefinition $method, NodeId $test, KnowledgeGraph $graph): void
    {
        foreach ($graph->outgoing($method->id, [EdgeType::Calls, EdgeType::Instantiates]) as $edge) {
            $evidence = $edge->evidence[0];
            $this->reference(
                $graph,
                $test,
                $edge->target,
                'direct_symbol',
                $evidence->location,
                $evidence->expression,
            );
        }
    }

    private function detectCallReference(
        CallSiteFact $call,
        NodeId $test,
        array $nodes,
        KnowledgeGraph $graph,
    ): void {
        $separator = strrpos($call->targetName, '\\');
        $target = strtolower($separator === false
            ? $call->targetName
            : substr($call->targetName, $separator + 1));
        $location = new SourceLocation($call->file, $call->startLine, $call->endLine);

        if ($target === 'route' && is_string($call->arguments[0] ?? null)) {
            foreach ($nodes as $node) {
                if ($node->kind === NodeKind::Route && ($node->attributes['name'] ?? null) === $call->arguments[0]) {
                    $this->reference($graph, $test, $node->id, 'route', $location, $call->normalizedExpression);
                }
            }
        }

        $httpMethod = match ($target) {
            'get', 'getjson' => 'GET',
            'post', 'postjson' => 'POST',
            'put', 'putjson' => 'PUT',
            'patch', 'patchjson' => 'PATCH',
            'delete', 'deletejson' => 'DELETE',
            default => null,
        };

        if ($httpMethod !== null && is_string($call->arguments[0] ?? null)) {
            $route = NodeId::route($httpMethod, $call->arguments[0]);

            if ($graph->hasNode($route)) {
                $this->reference($graph, $test, $route, 'route', $location, $call->normalizedExpression);
            }
        }

        if (in_array($target, ['assertdatabasehas', 'assertdatabasemissing'], true)
            && is_string($call->arguments[0] ?? null)) {
            $table = NodeId::named(NodeKind::Table, $call->arguments[0]);

            if (! $graph->hasNode($table)) {
                $graph->addNode(new GraphNode(
                    $table,
                    NodeKind::Table,
                    $call->arguments[0],
                    null,
                    null,
                    ['discovered_from_test_reference' => true],
                ));
            }

            $this->reference($graph, $test, $table, 'table', $location, $call->normalizedExpression);
        }

        $facadeKind = match ($call->receiverType) {
            'Illuminate\Support\Facades\Event' => 'event',
            'Illuminate\Support\Facades\Bus' => 'job',
            default => null,
        };

        if ($facadeKind !== null && in_array($target, ['fake', 'assertdispatched'], true)) {
            foreach ($this->classConstants($call->arguments) as $class) {
                $classId = $this->classNodeId($class, $nodes);

                if ($classId !== null) {
                    $this->reference($graph, $test, $classId, $facadeKind, $location, $call->normalizedExpression);
                }
            }

            return;
        }

        foreach ($this->classConstants($call->arguments) as $class) {
            $classId = $this->classNodeId($class, $nodes);

            if ($classId !== null) {
                $this->reference($graph, $test, $classId, 'direct_symbol', $location, $call->normalizedExpression);
            }
        }
    }

    private function reference(
        KnowledgeGraph $graph,
        NodeId $test,
        NodeId $target,
        string $kind,
        ?SourceLocation $location,
        ?string $expression,
    ): void {
        if (! $graph->hasNode($target)) {
            return;
        }

        SemanticEdgeFactory::add(
            $graph,
            $test,
            EdgeType::CoveredByTest,
            $target,
            EvidenceOrigin::StaticAst,
            'test-reference-detector',
            Certainty::Certain,
            $location,
            $expression,
            null,
            null,
            ['coverage_kind' => 'reference', 'reference_kind' => $kind],
        );
    }

    /** @return list<string> */
    private function classConstants(array $arguments): array
    {
        $classes = [];
        $visit = function (mixed $value) use (&$visit, &$classes): void {
            if (! is_array($value)) {
                return;
            }

            if (is_string($value['class_constant'] ?? null) && str_ends_with($value['class_constant'], '::class')) {
                $classes[] = substr($value['class_constant'], 0, -7);
            }

            foreach ($value as $item) {
                $visit($item);
            }
        };
        $visit($arguments);
        $classes = array_values(array_unique($classes));
        sort($classes, SORT_STRING);

        return $classes;
    }

    private function classNodeId(string $class, array $nodes): ?NodeId
    {
        foreach (['class', 'interface', 'trait', 'enum'] as $prefix) {
            $id = $prefix.':'.ltrim($class, '\\');

            if (isset($nodes[$id])) {
                return NodeId::fromString($id);
            }
        }

        return null;
    }

    private function module(?string $className): ?string
    {
        if ($className !== null && preg_match('/\\\\Tests\\\\(?:Feature|Unit)\\\\([^\\\\]+)/', $className, $matches) === 1) {
            return $matches[1];
        }

        return null;
    }
}
