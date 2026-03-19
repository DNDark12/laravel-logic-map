<?php

namespace dndark\LogicMap\Tests\Unit;

use dndark\LogicMap\Domain\AnalysisReport;
use dndark\LogicMap\Domain\Graph;
use dndark\LogicMap\Domain\Node;
use dndark\LogicMap\Domain\Enums\NodeKind;
use dndark\LogicMap\Services\HealthPayloadBuilder;
use dndark\LogicMap\Tests\TestCase;
use PHPUnit\Framework\Attributes\Test;

class HealthPayloadBuilderTest extends TestCase
{
    #[Test]
    public function it_builds_health_payload_with_graph_stats_and_config_blocks()
    {
        $builder = new HealthPayloadBuilder();
        $payload = $builder->build($this->makeGraph(), $this->makeReport());

        $this->assertSame(88, $payload['score']);
        $this->assertSame('B', $payload['grade']);
        $this->assertSame(2, $payload['graph_stats']['total_nodes']);
        $this->assertSame(1, $payload['graph_stats']['total_edges']);
        $this->assertArrayHasKey('labels', $payload);
        $this->assertArrayHasKey('colors', $payload);
    }

    #[Test]
    public function it_builds_coverage_correlation_from_node_coverage_metadata()
    {
        $builder = new HealthPayloadBuilder();
        $payload = $builder->build($this->makeGraph(), $this->makeReport());

        $this->assertIsArray($payload['coverage_correlation']);
        $this->assertSame(1, $payload['coverage_correlation']['eligible_nodes']);
        $this->assertSame(1, $payload['coverage_correlation']['high_risk_low_coverage']);
        $this->assertCount(1, $payload['coverage_correlation']['top_offenders']);
    }

    protected function makeGraph(): Graph
    {
        $graph = new Graph();
        $graph->addNode(new Node(
            'class:App\\Services\\Billing\\CheckoutService',
            NodeKind::SERVICE,
            'CheckoutService',
            metrics: ['fan_out' => 6, 'depth' => 3],
            metadata: [
                'shortLabel' => 'CheckoutService',
                'coverage' => ['line_rate' => 0.2, 'coverage_level' => 'low'],
            ]
        ));
        $graph->addNode(new Node(
            'route:/checkout',
            NodeKind::ROUTE,
            'route:/checkout'
        ));
        $graph->addEdge(new \dndark\LogicMap\Domain\Edge(
            'route:/checkout',
            'class:App\\Services\\Billing\\CheckoutService',
            \dndark\LogicMap\Domain\Enums\EdgeType::CALL
        ));

        return $graph;
    }

    protected function makeReport(): AnalysisReport
    {
        return new AnalysisReport(
            violations: [],
            healthScore: 88,
            grade: 'B',
            summary: ['critical' => 0, 'high' => 1, 'medium' => 0, 'low' => 0],
            nodeRiskMap: [
                'class:App\\Services\\Billing\\CheckoutService' => ['risk' => 'high', 'score' => 72, 'reasons' => ['high_coupling']],
            ],
            metadata: ['analysis_config_hash' => 'health-builder-test']
        );
    }
}
