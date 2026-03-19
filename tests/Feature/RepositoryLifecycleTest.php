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

class RepositoryLifecycleTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Artisan::call('logic-map:clear-cache');
    }

    #[Test]
    public function putting_a_snapshot_updates_latest_without_auto_activating_it()
    {
        $repo = $this->app->make(GraphRepository::class);
        $repo->putSnapshot('fp-latest-only', $this->makeGraph('route:/latest-only'));

        $this->assertSame('fp-latest-only', $repo->getLatestFingerprint());
        $this->assertNull($repo->getActiveFingerprint());
    }

    #[Test]
    public function clear_cache_removes_snapshots_analysis_and_pointer_state()
    {
        $repo = $this->app->make(GraphRepository::class);
        $repo->putSnapshot('fp-clear', $this->makeGraph('route:/clear'));
        $repo->putAnalysisReport('fp-clear', $this->makeReport());
        $repo->setActiveFingerprint('fp-clear');

        Artisan::call('logic-map:clear-cache');

        $this->assertNull($repo->getSnapshot('fp-clear'));
        $this->assertNull($repo->getAnalysisReport('fp-clear'));
        $this->assertNull($repo->getLatestFingerprint());
        $this->assertNull($repo->getActiveFingerprint());
        $this->assertSame([], $repo->listFingerprints());
    }

    protected function makeGraph(string $routeId): Graph
    {
        $graph = new Graph();
        $graph->addNode(new Node($routeId, NodeKind::ROUTE, $routeId));

        return $graph;
    }

    protected function makeReport(): AnalysisReport
    {
        return new AnalysisReport(
            violations: [],
            healthScore: 100,
            grade: 'S',
            summary: ['critical' => 0, 'high' => 0, 'medium' => 0, 'low' => 0],
            nodeRiskMap: [],
            metadata: ['analysis_config_hash' => 'repo-test-hash']
        );
    }
}
