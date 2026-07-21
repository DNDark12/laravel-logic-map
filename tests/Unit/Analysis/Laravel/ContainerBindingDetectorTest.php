<?php

namespace DNDark\LogicMap\Tests\Unit\Analysis\Laravel;

use DNDark\LogicMap\Analysis\Laravel\Detectors\ContainerBindingDetector;
use DNDark\LogicMap\Analysis\Laravel\Facts\LaravelRegistrationFactCollector;
use DNDark\LogicMap\Analysis\Php\PhpFileParser;
use DNDark\LogicMap\Analysis\Php\StructuralGraphBuilder;
use DNDark\LogicMap\Analysis\Php\SymbolTable;
use DNDark\LogicMap\Domain\Graph\Certainty;
use DNDark\LogicMap\Domain\Graph\EdgeType;
use DNDark\LogicMap\Domain\Graph\GraphEdge;
use DNDark\LogicMap\Domain\Graph\KnowledgeGraph;
use DNDark\LogicMap\Tests\Support\CommerceFixtureTestCase;

final class ContainerBindingDetectorTest extends CommerceFixtureTestCase
{
    public function test_bindings_resolution_and_injection_are_explicit_multiedges(): void
    {
        [$graph] = $this->buildSemanticGraph();

        $bindings = $this->edges(
            $graph,
            'interface:Fixtures\CommerceApp\Contracts\OrderGateway',
            EdgeType::BindsTo,
            'class:Fixtures\CommerceApp\Repositories\DatabaseOrderGateway',
        );
        $this->assertOriginPair($bindings);
        $this->assertSharedRelationKey($bindings);

        $resolutions = $this->edges(
            $graph,
            'method:Fixtures\CommerceApp\Contracts\OrderGateway::save',
            EdgeType::ResolvesTo,
            'method:Fixtures\CommerceApp\Repositories\DatabaseOrderGateway::save',
        );
        $this->assertOriginPair($resolutions);

        $injections = $this->edges(
            $graph,
            'method:Fixtures\CommerceApp\Services\OrderService::__construct',
            EdgeType::Injects,
            'interface:Fixtures\CommerceApp\Contracts\OrderGateway',
        );
        self::assertCount(1, $injections);
        self::assertSame('static_ast', $injections[0]->evidence[0]->origin->value);
    }

    public function test_explicit_class_returned_from_closure_is_possible_without_execution(): void
    {
        $source = <<<'PHP'
<?php
namespace App;

interface Gateway { public function save(): void; }
interface DynamicGateway { public function save(): void; }
final class DatabaseGateway implements Gateway { public function save(): void {} }
final class Provider
{
    public function register(): void
    {
        $this->app->bind(Gateway::class, fn () => DatabaseGateway::class);
        $this->app->bind(DynamicGateway::class, fn () => new DatabaseGateway());
    }
}
PHP;
        $parsed = (new PhpFileParser([new LaravelRegistrationFactCollector()]))
            ->parse('app/Provider.php', $source);
        $symbols = new SymbolTable();

        foreach ($parsed->symbols as $symbol) {
            $symbols->add($symbol);
        }

        $graph = new KnowledgeGraph();
        (new StructuralGraphBuilder($symbols))->build([$parsed], $graph);
        $diagnostics = (new ContainerBindingDetector())->detect([$parsed], $symbols, [], $graph);
        $edges = $this->edges(
            $graph,
            'interface:App\Gateway',
            EdgeType::BindsTo,
            'class:App\DatabaseGateway',
        );

        self::assertCount(1, $edges);
        self::assertSame(Certainty::Possible, $edges[0]->evidence[0]->certainty);
        self::assertSame('static_ast', $edges[0]->evidence[0]->origin->value);
        self::assertContains('dynamic_class_string', array_map(
            static fn ($diagnostic): string => $diagnostic->code->value,
            $diagnostics,
        ));
    }

    private function assertOriginPair(array $edges): void
    {
        self::assertCount(2, $edges, json_encode(array_map(
            static fn (GraphEdge $edge): array => $edge->evidence[0]->toArray(),
            $edges,
        ), JSON_PRETTY_PRINT));
        $origins = array_values(array_unique(array_map(
            static fn (GraphEdge $edge): string => $edge->evidence[0]->origin->value,
            $edges,
        )));
        sort($origins, SORT_STRING);
        self::assertSame(['laravel_boot', 'static_ast'], $origins);
        self::assertNotSame($edges[0]->id, $edges[1]->id);
    }

    private function assertSharedRelationKey(array $edges): void
    {
        $keys = array_values(array_unique(array_map(
            static fn (GraphEdge $edge): mixed => $edge->evidence[0]->attributes['semantic_relation_key'] ?? null,
            $edges,
        )));

        self::assertCount(1, $keys);
        self::assertNotSame('', $keys[0]);
    }

    private function edges(KnowledgeGraph $graph, string $source, EdgeType $type, string $target): array
    {
        return array_values(array_filter(
            $graph->edges(),
            static fn (GraphEdge $edge): bool => $edge->source->value === $source
                && $edge->type === $type
                && $edge->target->value === $target,
        ));
    }
}
