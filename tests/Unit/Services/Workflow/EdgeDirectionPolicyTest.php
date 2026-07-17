<?php

namespace DNDark\LogicMap\Tests\Unit\Services\Workflow;

use DNDark\LogicMap\Domain\Graph\EdgeType;
use DNDark\LogicMap\Services\Workflow\EdgeDirectionPolicy;
use PHPUnit\Framework\TestCase;

final class EdgeDirectionPolicyTest extends TestCase
{
    public function test_direction_table_is_total_and_structural_edges_are_not_executable(): void
    {
        $policy = new EdgeDirectionPolicy();
        self::assertSame(
            array_map(static fn (EdgeType $type): string => $type->value, EdgeType::cases()),
            array_keys($policy->rules()),
        );
        self::assertFalse($policy->workflow(EdgeType::Contains));
        self::assertFalse($policy->workflow(EdgeType::Defines));
        self::assertSame('reverse', $policy->workflowDirection(EdgeType::ListensTo));
        self::assertSame('forward', $policy->workflowDirection(EdgeType::Queues));
        self::assertSame('both', $policy->impactDirection(EdgeType::ResolvesTo));
    }
}
