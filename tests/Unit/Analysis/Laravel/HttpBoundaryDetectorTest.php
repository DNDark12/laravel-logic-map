<?php

namespace DNDark\LogicMap\Tests\Unit\Analysis\Laravel;

use DNDark\LogicMap\Analysis\Laravel\Facts\LaravelRegistrationFactCollector;
use DNDark\LogicMap\Analysis\Laravel\LaravelFactReconciler;
use DNDark\LogicMap\Analysis\Php\CallGraphBuilder;
use DNDark\LogicMap\Analysis\Php\CallTargetResolver;
use DNDark\LogicMap\Analysis\Php\PhpFileParser;
use DNDark\LogicMap\Analysis\Php\StructuralGraphBuilder;
use DNDark\LogicMap\Analysis\Php\SymbolTable;
use DNDark\LogicMap\Domain\Graph\EdgeType;
use DNDark\LogicMap\Domain\Graph\EvidenceOrigin;
use DNDark\LogicMap\Domain\Graph\GraphEdge;
use DNDark\LogicMap\Domain\Graph\KnowledgeGraph;
use DNDark\LogicMap\Tests\Support\CommerceFixtureTestCase;

final class HttpBoundaryDetectorTest extends CommerceFixtureTestCase
{
    public function test_http_boundary_edges_keep_static_and_boot_observations_distinct(): void
    {
        [$graph] = $this->buildSemanticGraph();
        $route = 'route:POST:orders/{order}/cancel';
        $controller = 'method:Fixtures\CommerceApp\Http\Controllers\OrderController::cancel';

        $handles = $this->edges($graph, $route, EdgeType::HandlesRoute, $controller);
        $this->assertOriginPair($handles);
        $this->assertSharedRelationKey($handles);

        $authMiddleware = $this->edges($graph, $route, EdgeType::AppliesMiddleware, 'middleware:auth');
        $throttleMiddleware = $this->edges(
            $graph,
            $route,
            EdgeType::AppliesMiddleware,
            'middleware:throttle:orders',
        );
        $this->assertOriginPair($authMiddleware);
        $this->assertOriginPair($throttleMiddleware);

        $validation = $this->edges(
            $graph,
            $controller,
            EdgeType::ValidatesWith,
            'class:Fixtures\CommerceApp\Http\Requests\CancelOrderRequest',
        );
        self::assertCount(1, $validation);
        self::assertSame(EvidenceOrigin::StaticAst, $validation[0]->evidence[0]->origin);

        $authorization = $this->edges(
            $graph,
            $controller,
            EdgeType::AuthorizesWith,
            'method:Fixtures\CommerceApp\Policies\OrderPolicy::cancel',
        );
        $this->assertOriginPair($authorization);
        $this->assertSharedRelationKey($authorization);

        $injection = $this->edges(
            $graph,
            $controller,
            EdgeType::Injects,
            'class:Fixtures\CommerceApp\Services\OrderService',
        );
        self::assertCount(1, $injection);
        self::assertSame(EvidenceOrigin::StaticAst, $injection[0]->evidence[0]->origin);
    }

    public function test_authorize_resource_route_can_and_controller_authorize_are_detected(): void
    {
        $source = <<<'PHP'
<?php
namespace App;

use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Route;

final class Order {}
final class OrderPolicy { public function update(): bool { return true; } }
final class OrderController
{
    public function __construct() { $this->authorizeResource(Order::class, 'order'); }
    public function update(Order $order): void { $this->authorize('update', $order); }
}
final class Provider
{
    public function boot(): void { Gate::policy(Order::class, OrderPolicy::class); }
}

Route::put('/orders/{order}', [OrderController::class, 'update'])
    ->middleware('can:update,order');
PHP;
        $parsed = (new PhpFileParser([new LaravelRegistrationFactCollector()]))
            ->parse('app/Http.php', $source);
        $symbols = new SymbolTable();

        foreach ($parsed->symbols as $symbol) {
            $symbols->add($symbol);
        }

        $graph = new KnowledgeGraph();
        (new StructuralGraphBuilder($symbols))->build([$parsed], $graph);
        (new CallGraphBuilder(new CallTargetResolver($symbols), $symbols))->build([$parsed], $graph);
        (new LaravelFactReconciler())->reconcile([$parsed], $symbols, [], $graph);

        self::assertCount(1, $this->edges(
            $graph,
            'method:App\OrderController::__construct',
            EdgeType::AuthorizesWith,
            'class:App\OrderPolicy',
        ));
        self::assertCount(2, $this->edges(
            $graph,
            'method:App\OrderController::update',
            EdgeType::AuthorizesWith,
            'method:App\OrderPolicy::update',
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
        self::assertIsString($keys[0]);
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
