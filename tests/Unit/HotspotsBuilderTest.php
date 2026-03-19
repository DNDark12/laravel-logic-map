<?php

namespace dndark\LogicMap\Tests\Unit;

use dndark\LogicMap\Domain\AnalysisReport;
use dndark\LogicMap\Domain\Graph;
use dndark\LogicMap\Domain\Node;
use dndark\LogicMap\Domain\Enums\NodeKind;
use dndark\LogicMap\Services\HotspotsBuilder;
use dndark\LogicMap\Tests\TestCase;
use PHPUnit\Framework\Attributes\Test;

class HotspotsBuilderTest extends TestCase
{
    #[Test]
    public function it_sorts_hotspots_by_risk_score_then_coupling_then_instability_then_fan_out()
    {
        $builder = new HotspotsBuilder();
        $payload = $builder->build($this->makeGraph(), $this->makeReport(), []);

        $this->assertSame('class:App\\Services\\Billing\\CheckoutService', $payload['items'][0]['node_id']);
        $this->assertSame('class:App\\Services\\Orders\\SyncOrderService', $payload['items'][1]['node_id']);
        $this->assertSame('class:App\\Services\\Billing\\InvoiceService', $payload['items'][2]['node_id']);
    }

    #[Test]
    public function it_filters_hotspots_by_kind_module_risk_and_limit()
    {
        $builder = new HotspotsBuilder();
        $payload = $builder->build($this->makeGraph(), $this->makeReport(), [
            'kind' => 'service',
            'module' => 'Billing',
            'risk' => 'critical',
            'limit' => 1,
        ]);

        $this->assertCount(1, $payload['items']);
        $this->assertSame('Billing', $payload['items'][0]['module']);
        $this->assertSame('critical', $payload['items'][0]['risk']);
        $this->assertSame(1, $payload['meta']['limit']);
    }

    protected function makeGraph(): Graph
    {
        $graph = new Graph();
        $graph->addNode(new Node(
            'class:App\\Services\\Billing\\CheckoutService',
            NodeKind::SERVICE,
            'CheckoutService',
            metrics: ['coupling' => 12, 'instability' => 0.70, 'fan_out' => 6, 'depth' => 3],
            metadata: ['module' => 'Billing', 'shortLabel' => 'CheckoutService']
        ));
        $graph->addNode(new Node(
            'class:App\\Services\\Orders\\SyncOrderService',
            NodeKind::SERVICE,
            'SyncOrderService',
            metrics: ['coupling' => 11, 'instability' => 0.95, 'fan_out' => 8, 'depth' => 4],
            metadata: ['module' => 'Orders', 'shortLabel' => 'SyncOrderService']
        ));
        $graph->addNode(new Node(
            'class:App\\Services\\Billing\\InvoiceService',
            NodeKind::SERVICE,
            'InvoiceService',
            metrics: ['coupling' => 7, 'instability' => 0.40, 'fan_out' => 3, 'depth' => 2],
            metadata: ['module' => 'Billing', 'shortLabel' => 'InvoiceService']
        ));

        return $graph;
    }

    protected function makeReport(): AnalysisReport
    {
        return new AnalysisReport(
            violations: [],
            healthScore: 62,
            grade: 'D',
            summary: ['critical' => 1, 'high' => 1, 'medium' => 1, 'low' => 0],
            nodeRiskMap: [
                'class:App\\Services\\Billing\\CheckoutService' => ['risk' => 'critical', 'score' => 90, 'reasons' => ['fat_controller']],
                'class:App\\Services\\Orders\\SyncOrderService' => ['risk' => 'critical', 'score' => 90, 'reasons' => ['high_coupling']],
                'class:App\\Services\\Billing\\InvoiceService' => ['risk' => 'high', 'score' => 70, 'reasons' => ['orphan']],
            ],
            metadata: ['analysis_config_hash' => 'hotspots-builder-test']
        );
    }
}
