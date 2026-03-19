<?php

namespace dndark\LogicMap\Tests\Feature;

use dndark\LogicMap\Contracts\GraphRepository;
use dndark\LogicMap\Domain\AnalysisReport;
use dndark\LogicMap\Domain\Graph;
use dndark\LogicMap\Domain\Node;
use dndark\LogicMap\Domain\Enums\NodeKind;
use dndark\LogicMap\Tests\TestCase;
use Illuminate\Support\Facades\Artisan;
use PHPUnit\Framework\Attributes\Test;

class HotspotsEndpointTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Artisan::call('logic-map:clear-cache');

        $repo = $this->app->make(GraphRepository::class);
        $repo->putSnapshot('fp-hotspots', $this->makeGraph());
        $repo->putAnalysisReport('fp-hotspots', $this->makeReport());
        $repo->setActiveFingerprint('fp-hotspots');
    }

    #[Test]
    public function hotspots_endpoint_returns_sorted_high_risk_nodes()
    {
        $response = $this->getJson('/logic-map/hotspots');

        $response->assertStatus(200);
        $response->assertJsonStructure([
            'ok',
            'data' => [
                'items' => [
                    ['node_id', 'kind', 'name', 'module', 'risk', 'risk_score', 'coupling', 'instability', 'fan_out', 'depth'],
                ],
                'meta' => ['total', 'count', 'limit', 'filters'],
                '_resolution' => ['requested_snapshot', 'resolved_via', 'resolved_fingerprint', 'pointer_state', 'analysis_state'],
            ],
            'message',
            'errors',
        ]);

        $items = $response->json('data.items');
        $this->assertSame('class:App\\Services\\Billing\\CheckoutService', $items[0]['node_id']);
        $this->assertSame('class:App\\Services\\Orders\\SyncOrderService', $items[1]['node_id']);
        $this->assertSame('class:App\\Services\\Billing\\InvoiceService', $items[2]['node_id']);
    }

    #[Test]
    public function hotspots_endpoint_can_filter_by_kind_module_risk_and_limit()
    {
        $response = $this->getJson('/logic-map/hotspots?kind=service&module=Billing&risk=critical&limit=1');

        $response->assertStatus(200);
        $items = $response->json('data.items');

        $this->assertCount(1, $items);
        $this->assertSame('service', $items[0]['kind']);
        $this->assertSame('Billing', $items[0]['module']);
        $this->assertSame('critical', $items[0]['risk']);
        $this->assertSame(1, $response->json('data.meta.limit'));
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
            metadata: ['analysis_config_hash' => 'hotspots-test-hash']
        );
    }
}
