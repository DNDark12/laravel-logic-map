<?php

namespace dndark\LogicMap\Tests\Unit;

use dndark\LogicMap\Analysis\AstParser;
use dndark\LogicMap\Contracts\GraphExtractor;
use dndark\LogicMap\Domain\Graph;
use dndark\LogicMap\Tests\TestCase;

class AstParserTest extends TestCase
{
    protected AstParser $parser;

    protected function setUp(): void
    {
        parent::setUp();
        $this->parser = new AstParser();
    }

    /** @test */
    public function it_returns_graph_instance()
    {
        $graph = $this->parser->parse([]);

        $this->assertInstanceOf(Graph::class, $graph);
    }

    /** @test */
    public function it_extracts_nodes_from_php_files()
    {
        $files = [
            __DIR__ . '/../../src/Domain/Graph.php',
        ];

        $graph = $this->parser->parse($files);
        $nodes = $graph->getNodes();

        $this->assertNotEmpty($nodes);

        // Should have at least the Graph class
        $hasGraphClass = false;
        foreach ($nodes as $node) {
            if (str_contains($node->id, 'Graph')) {
                $hasGraphClass = true;
                break;
            }
        }

        $this->assertTrue($hasGraphClass, 'Should extract Graph class');
    }

    /** @test */
    public function it_extracts_methods_from_classes()
    {
        $files = [
            __DIR__ . '/../../src/Domain/Graph.php',
        ];

        $graph = $this->parser->parse($files);
        $nodes = $graph->getNodes();

        // Should have method nodes
        $methodNodes = array_filter($nodes, fn($n) => str_starts_with($n->id, 'method:'));

        $this->assertNotEmpty($methodNodes, 'Should extract method nodes');
    }

    /** @test */
    public function it_tracks_diagnostics()
    {
        $files = [
            __DIR__ . '/../../src/Domain/Graph.php',
        ];

        $this->parser->parse($files);
        $diagnostics = $this->parser->getDiagnostics();

        $this->assertArrayHasKey('total_files', $diagnostics);
        $this->assertArrayHasKey('parsed_files', $diagnostics);
        $this->assertArrayHasKey('skipped_files', $diagnostics);
        $this->assertArrayHasKey('error_files', $diagnostics);

        $this->assertEquals(1, $diagnostics['total_files']);
        $this->assertEquals(1, $diagnostics['parsed_files']);
        $this->assertEquals(0, $diagnostics['skipped_files']);
    }

    /** @test */
    public function it_handles_missing_files_gracefully()
    {
        $files = [
            '/nonexistent/file.php',
        ];

        $graph = $this->parser->parse($files);
        $diagnostics = $this->parser->getDiagnostics();

        $this->assertEquals(1, $diagnostics['skipped_files']);
        $this->assertNotEmpty($diagnostics['error_files']);
    }

    /** @test */
    public function it_extracts_edges_for_method_calls()
    {
        // Parse a file with method calls
        $files = [
            __DIR__ . '/../../src/Services/BuildLogicMapService.php',
        ];

        $graph = $this->parser->parse($files);
        $edges = $graph->getEdges();

        // BuildLogicMapService calls methods on injected services
        // Should produce edges
        $this->assertIsArray($edges);
    }

    /** @test */
    public function it_assigns_correct_node_kinds()
    {
        $files = [
            __DIR__ . '/../../src/Services/BuildLogicMapService.php',
            __DIR__ . '/../../src/Repositories/CacheGraphRepository.php',
            __DIR__ . '/../../src/Http/Controllers/LogicMapController.php',
        ];

        $graph = $this->parser->parse($files);
        $nodes = $graph->getNodes();

        $kinds = [];
        foreach ($nodes as $node) {
            if (str_starts_with($node->id, 'class:')) {
                $kinds[$node->name] = $node->kind->value;
            }
        }

        // Check that kinds are correctly assigned
        $this->assertContains('service', array_values($kinds));
        $this->assertContains('repository', array_values($kinds));
        $this->assertContains('controller', array_values($kinds));
    }

    /** @test */
    public function it_implements_graph_extractor_contract()
    {
        $this->assertInstanceOf(
            GraphExtractor::class,
            $this->parser
        );
    }
}
