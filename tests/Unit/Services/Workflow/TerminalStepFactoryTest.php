<?php

namespace DNDark\LogicMap\Tests\Unit\Services\Workflow;

use DNDark\LogicMap\Analysis\Facts\ThrowFact;
use DNDark\LogicMap\Domain\Graph\GraphNode;
use DNDark\LogicMap\Domain\Graph\NodeId;
use DNDark\LogicMap\Domain\Graph\NodeKind;
use DNDark\LogicMap\Services\Workflow\TerminalStepFactory;
use PHPUnit\Framework\TestCase;

final class TerminalStepFactoryTest extends TestCase
{
    public function test_omits_node_id_when_exception_class_has_no_graph_node(): void
    {
        $fact = new ThrowFact(
            'app/Services/OrderService.php',
            10,
            10,
            'method:App\Services\OrderService::cancel',
            'InvalidArgumentException',
            'throw new InvalidArgumentException',
            null,
        );

        $step = (new TerminalStepFactory())->make($fact, 'Orders', []);

        self::assertNull($step->nodeId);
        self::assertSame('InvalidArgumentException', $step->label);
    }

    public function test_keeps_node_id_when_exception_class_has_a_graph_node(): void
    {
        $fact = new ThrowFact(
            'app/Exceptions/OrderCannotBeCancelledException.php',
            10,
            10,
            'method:App\Services\OrderService::cancel',
            'App\Exceptions\OrderCannotBeCancelledException',
            'throw new OrderCannotBeCancelledException',
            null,
        );
        $nodeId = NodeId::symbol(NodeKind::ClassSymbol, 'App\Exceptions\OrderCannotBeCancelledException');
        $node = new GraphNode($nodeId, NodeKind::ClassSymbol, 'OrderCannotBeCancelledException', $nodeId->value, null);

        $step = (new TerminalStepFactory())->make($fact, 'Orders', [$nodeId->value => $node]);

        self::assertSame($nodeId->value, $step->nodeId?->value);
    }
}
