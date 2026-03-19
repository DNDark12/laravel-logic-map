<?php

namespace dndark\LogicMap\Tests\Unit;

use dndark\LogicMap\Analysis\Runtime\CoverageMetadataCollector;
use dndark\LogicMap\Domain\Enums\NodeKind;
use dndark\LogicMap\Domain\Graph;
use dndark\LogicMap\Domain\Node;
use dndark\LogicMap\Tests\TestCase;
use PHPUnit\Framework\Attributes\Test;

class CoverageMetadataCollectorTest extends TestCase
{
    #[Test]
    public function it_maps_class_and_method_coverage_from_clover(): void
    {
        $path = $this->writeCloverFixture(<<<XML
<?xml version="1.0" encoding="UTF-8"?>
<coverage generated="1710000000" clover="4.5.2">
  <project timestamp="1710000000">
    <file name="/app/Services/OrderService.php">
      <class name="App\Services\OrderService">
        <metrics methods="2" coveredmethods="1" statements="10" coveredstatements="8" />
        <method name="process">
          <metrics methods="1" coveredmethods="1" statements="4" coveredstatements="2" />
        </method>
      </class>
    </file>
  </project>
</coverage>
XML);

        $this->app['config']->set('logic-map.coverage.enabled', true);
        $this->app['config']->set('logic-map.coverage.clover_path', $path);
        $this->app['config']->set('logic-map.coverage.assume_uncovered_when_missing', false);
        $this->app['config']->set('logic-map.coverage.low_threshold', 0.5);
        $this->app['config']->set('logic-map.coverage.high_threshold', 0.8);

        $graph = new Graph();
        $graph->addNode(new Node('class:App\Services\OrderService', NodeKind::SERVICE, 'App\Services\OrderService'));
        $graph->addNode(new Node('method:App\Services\OrderService@process', NodeKind::SERVICE, 'process'));
        $graph->addNode(new Node('method:App\Services\OrderService@index', NodeKind::SERVICE, 'index'));

        (new CoverageMetadataCollector())->collect($graph);

        $classCoverage = $graph->getNode('class:App\Services\OrderService')?->metadata['coverage'] ?? null;
        $this->assertIsArray($classCoverage);
        $this->assertSame(80, $classCoverage['coverage_percent']);
        $this->assertSame('high', $classCoverage['coverage_level']);
        $this->assertSame('class', $classCoverage['scope']);

        $processCoverage = $graph->getNode('method:App\Services\OrderService@process')?->metadata['coverage'] ?? null;
        $this->assertIsArray($processCoverage);
        $this->assertSame(50, $processCoverage['coverage_percent']);
        $this->assertSame('medium', $processCoverage['coverage_level']);
        $this->assertSame('method', $processCoverage['scope']);

        $indexCoverage = $graph->getNode('method:App\Services\OrderService@index')?->metadata['coverage'] ?? null;
        $this->assertIsArray($indexCoverage);
        $this->assertSame(80, $indexCoverage['coverage_percent']);
        $this->assertSame('class_fallback', $indexCoverage['scope']);
    }

    #[Test]
    public function it_marks_missing_symbols_as_uncovered_when_assume_enabled(): void
    {
        $path = $this->writeCloverFixture(<<<XML
<?xml version="1.0" encoding="UTF-8"?>
<coverage generated="1710000000" clover="4.5.2">
  <project timestamp="1710000000">
    <file name="/app/Services/OnlyKnown.php">
      <class name="App\Services\OnlyKnown">
        <metrics methods="1" coveredmethods="1" statements="1" coveredstatements="1" />
      </class>
    </file>
  </project>
</coverage>
XML);

        $this->app['config']->set('logic-map.coverage.enabled', true);
        $this->app['config']->set('logic-map.coverage.clover_path', $path);
        $this->app['config']->set('logic-map.coverage.assume_uncovered_when_missing', true);

        $graph = new Graph();
        $graph->addNode(new Node('class:App\Services\MissingService', NodeKind::SERVICE, 'App\Services\MissingService'));

        (new CoverageMetadataCollector())->collect($graph);

        $coverage = $graph->getNode('class:App\Services\MissingService')?->metadata['coverage'] ?? null;
        $this->assertIsArray($coverage);
        $this->assertSame(0, $coverage['coverage_percent']);
        $this->assertSame('none', $coverage['coverage_level']);
        $this->assertTrue($coverage['assumed']);
    }

    protected function writeCloverFixture(string $xml): string
    {
        $path = tempnam(sys_get_temp_dir(), 'logic-map-clover-');
        file_put_contents($path, $xml);

        return $path;
    }
}

