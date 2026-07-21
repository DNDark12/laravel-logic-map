<?php

namespace DNDark\LogicMap\Tests\Unit\Analysis\Runtime;

use DNDark\LogicMap\Analysis\Runtime\CloverCoverageImporter;
use DNDark\LogicMap\Domain\Graph\GraphNode;
use DNDark\LogicMap\Domain\Graph\KnowledgeGraph;
use DNDark\LogicMap\Domain\Graph\NodeId;
use DNDark\LogicMap\Domain\Graph\NodeKind;
use PHPUnit\Framework\TestCase;

final class CloverCoverageImporterTest extends TestCase
{
    private ?string $report = null;

    protected function tearDown(): void
    {
        if ($this->report !== null && is_file($this->report)) {
            unlink($this->report);
        }
    }

    public function test_maps_class_and_method_hits_and_preserves_report_identity(): void
    {
        $graph = $this->graph();
        $this->report = tempnam(sys_get_temp_dir(), 'logic-map-clover-');
        self::assertIsString($this->report);
        file_put_contents($this->report, <<<'XML'
<?xml version="1.0" encoding="UTF-8"?>
<coverage generated="1">
  <project timestamp="1">
    <file name="/repo/app/OrderService.php">
      <class name="App\Services\OrderService" namespace="App\Services">
        <metrics methods="2" coveredmethods="1"/>
      </class>
      <line num="10" type="method" name="cancel" visibility="public" count="3"/>
    </file>
  </project>
</coverage>
XML);

        $result = (new CloverCoverageImporter())->import($this->report, $graph);

        self::assertSame(3, $result['coverage']['method:App\Services\OrderService::cancel']['hit_count']);
        self::assertSame(1, $result['coverage']['class:App\Services\OrderService']['covered_methods']);
        self::assertArrayNotHasKey('method:App\Services\OrderService::untouched', $result['coverage']);
        self::assertSame($this->report, $result['metadata']['report_path']);
        self::assertSame(hash_file('sha256', $this->report), $result['metadata']['report_hash']);

        $explicitZero = (new CloverCoverageImporter())->import($this->report, $graph, true);
        self::assertSame(0, $explicitZero['coverage']['method:App\Services\OrderService::untouched']['hit_count']);
        self::assertSame('explicit_zero', $explicitZero['coverage']['method:App\Services\OrderService::untouched']['status']);
    }

    private function graph(): KnowledgeGraph
    {
        $graph = new KnowledgeGraph();

        foreach ([
            [NodeId::fromString('class:App\Services\OrderService'), NodeKind::ClassSymbol],
            [NodeId::method('App\Services\OrderService', 'cancel'), NodeKind::Method],
            [NodeId::method('App\Services\OrderService', 'untouched'), NodeKind::Method],
        ] as [$id, $kind]) {
            $graph->addNode(new GraphNode($id, $kind, $id->value, null, null));
        }

        return $graph;
    }
}
