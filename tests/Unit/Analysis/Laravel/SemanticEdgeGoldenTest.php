<?php

namespace DNDark\LogicMap\Tests\Unit\Analysis\Laravel;

use DNDark\LogicMap\Domain\Graph\EdgeType;
use DNDark\LogicMap\Domain\Graph\EvidenceOrigin;
use DNDark\LogicMap\Domain\Graph\GraphEdge;
use DNDark\LogicMap\Tests\Support\CommerceFixtureTestCase;

final class SemanticEdgeGoldenTest extends CommerceFixtureTestCase
{
    public function test_initial_static_laravel_relations_match_exact_golden_keys(): void
    {
        [$graph] = $this->buildSemanticGraph();
        $expectedRelations = [
            implode('|', [
                'route:POST:orders/{order}/cancel',
                EdgeType::HandlesRoute->value,
                'method:Fixtures\CommerceApp\Http\Controllers\OrderController::cancel',
            ]),
            implode('|', [
                'method:Fixtures\CommerceApp\Http\Controllers\OrderController::cancel',
                EdgeType::ValidatesWith->value,
                'class:Fixtures\CommerceApp\Http\Requests\CancelOrderRequest',
            ]),
            implode('|', [
                'method:Fixtures\CommerceApp\Http\Controllers\OrderController::cancel',
                EdgeType::AuthorizesWith->value,
                'method:Fixtures\CommerceApp\Policies\OrderPolicy::cancel',
            ]),
            implode('|', [
                'interface:Fixtures\CommerceApp\Contracts\OrderGateway',
                EdgeType::BindsTo->value,
                'class:Fixtures\CommerceApp\Repositories\DatabaseOrderGateway',
            ]),
        ];
        $actual = [];

        foreach ($graph->edges() as $edge) {
            $relation = implode('|', [$edge->source->value, $edge->type->value, $edge->target->value]);

            if ($edge->evidence[0]->origin !== EvidenceOrigin::StaticAst
                || ! in_array($relation, $expectedRelations, true)) {
                continue;
            }

            $actual[] = $this->serialize($edge);
        }

        sort($actual, SORT_STRING);
        $expected = json_decode(
            file_get_contents($this->fixtureRoot().'/expected/semantic-edges.json'),
            true,
            flags: JSON_THROW_ON_ERROR,
        );
        $expected = array_values(array_filter(
            $expected,
            static fn (string $serialized): bool => in_array(
                implode('|', array_slice(explode('|', $serialized), 0, 3)),
                $expectedRelations,
                true,
            ) && str_contains($serialized, '|static_ast|'),
        ));
        sort($expected, SORT_STRING);

        self::assertCount(4, $actual);
        self::assertSame($expected, $actual);
    }

    private function serialize(GraphEdge $edge): string
    {
        return implode('|', [
            $edge->source->value,
            $edge->type->value,
            $edge->target->value,
            $edge->id,
            $edge->evidence[0]->origin->value,
            $edge->evidence[0]->detector,
            $edge->evidence[0]->attributes['semantic_relation_key'] ?? '',
        ]);
    }
}
