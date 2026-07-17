<?php

namespace DNDark\LogicMap\Tests\Unit\Analysis\Laravel;

use DNDark\LogicMap\Analysis\Laravel\SymbolClassifier;
use DNDark\LogicMap\Domain\Graph\GraphNode;
use DNDark\LogicMap\Tests\Support\CommerceFixtureTestCase;

final class SymbolClassifierTest extends CommerceFixtureTestCase
{
    public function test_fixture_roles_use_certain_framework_rules_before_probable_conventions(): void
    {
        [$graph, , $files, $symbols, $bootFacts] = $this->buildSemanticGraph(['app', 'routes']);
        (new SymbolClassifier(config('logic-map.classifier.namespace_conventions', [])))
            ->classify($files, $symbols, $bootFacts, $graph);
        $nodes = [];

        foreach ($graph->nodes() as $node) {
            $nodes[$node->id->value] = $node;
        }

        $expected = [
            'class:Fixtures\CommerceApp\Http\Controllers\OrderController' => ['controller', 'certain'],
            'class:Fixtures\CommerceApp\Http\Requests\CancelOrderRequest' => ['form_request', 'certain'],
            'class:Fixtures\CommerceApp\Policies\OrderPolicy' => ['policy', 'certain'],
            'class:Fixtures\CommerceApp\Models\Order' => ['model', 'certain'],
            'class:Fixtures\CommerceApp\Jobs\ReconcileInventoryJob' => ['job', 'certain'],
            'class:Fixtures\CommerceApp\Console\Commands\ReconcileInventory' => ['command', 'certain'],
            'class:Fixtures\CommerceApp\Events\OrderCancelled' => ['event', 'certain'],
            'class:Fixtures\CommerceApp\Notifications\OrderWasCancelled' => ['notification', 'certain'],
            'class:Fixtures\CommerceApp\Mail\OrderCancelledMail' => ['mailable', 'certain'],
            'class:Fixtures\CommerceApp\Listeners\RestockInventory' => ['listener', 'certain'],
            'class:Fixtures\CommerceApp\Listeners\SendCancellationWebhook' => ['listener', 'certain'],
            'class:Fixtures\CommerceApp\Services\OrderService' => ['service', 'probable'],
            'class:Fixtures\CommerceApp\Repositories\DatabaseOrderGateway' => ['repository', 'probable'],
        ];

        foreach ($expected as $id => [$kind, $certainty]) {
            self::assertArrayHasKey($id, $nodes);
            self::assertSame($kind, $nodes[$id]->kind->value, $id);
            self::assertSame($certainty, $nodes[$id]->attributes['classification_certainty'], $id);
            self::assertNotSame('', $nodes[$id]->attributes['classification_reason'], $id);
        }

        $actualGolden = array_values(array_map(
            static fn (GraphNode $node): string => implode('|', [
                $node->id->value,
                $node->kind->value,
                $node->attributes['classification_certainty'],
            ]),
            array_values(array_filter(
                $graph->nodes(),
                static fn (GraphNode $node): bool => preg_match('/^(class|interface|trait|enum):/', $node->id->value) === 1,
            )),
        ));
        sort($actualGolden, SORT_STRING);
        $expectedGolden = json_decode(
            file_get_contents($this->fixtureRoot().'/expected/semantic-kinds.json'),
            true,
            flags: JSON_THROW_ON_ERROR,
        );

        self::assertSame($expectedGolden, $actualGolden);
    }
}
