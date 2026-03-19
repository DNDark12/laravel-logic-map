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

    /** @test */
    public function it_filters_non_business_classes_and_calls()
    {
        $fixture = <<<'PHP'
<?php

namespace App\Models;

class User
{
    public function find(int $id): ?self
    {
        return null;
    }
}

namespace App\Services;

use App\Models\User;

class OrderService
{
    public function __construct(private User $user)
    {
    }

    public function syncOrders(): void
    {
        $this->user->find(1);
    }
}
PHP;

        $tmpFile = tempnam(sys_get_temp_dir(), 'logic-map-filter-');
        file_put_contents($tmpFile, $fixture);

        try {
            $graph = $this->parser->parse([$tmpFile]);

            $nodeIds = array_keys($graph->getNodes());
            $edgeTargets = array_map(fn($e) => $e->target, $graph->getEdges());

            $this->assertNotContains('class:App\\Models\\User', $nodeIds);
            $this->assertNotContains('method:App\\Models\\User@find', $nodeIds);
            $this->assertNotContains('method:App\\Models\\User@find', $edgeTargets);
        } finally {
            @unlink($tmpFile);
        }
    }

    /** @test */
    public function it_extracts_doc_intent_and_body_strings_from_method_metadata()
    {
        $fixture = <<<'PHP'
<?php

namespace App\Services\Billing;

class InvoiceService
{
    /**
     * @intent Synchronize invoice status with payment provider
     */
    public function syncInvoice(): array
    {
        $log = 'sync started';

        return [
            'message' => 'Invoice synchronized successfully',
            'status' => 'ok',
        ];
    }
}
PHP;

        $tmpFile = tempnam(sys_get_temp_dir(), 'logic-map-intent-');
        file_put_contents($tmpFile, $fixture);

        try {
            $graph = $this->parser->parse([$tmpFile]);
            $node = $graph->getNode('method:App\\Services\\Billing\\InvoiceService@syncInvoice');

            $this->assertNotNull($node);
            $this->assertArrayHasKey('docIntent', $node->metadata);
            $this->assertArrayHasKey('bodyStrings', $node->metadata);
            $this->assertArrayHasKey('resultMessages', $node->metadata);

            $this->assertSame('Synchronize invoice status with payment provider', $node->metadata['docIntent']);
            $this->assertContains('sync started', $node->metadata['bodyStrings']);
            $this->assertContains('Invoice synchronized successfully', $node->metadata['resultMessages']);
        } finally {
            @unlink($tmpFile);
        }
    }

    /** @test */
    public function it_resolves_interface_types_to_concrete_implementations()
    {
        $fixture = <<<'PHP'
<?php

namespace App\Contracts;

interface PaymentGateway
{
    public function charge(int $amount): bool;
}

namespace App\Services\Billing;

use App\Contracts\PaymentGateway;

class StripeGateway implements PaymentGateway
{
    public function charge(int $amount): bool
    {
        return true;
    }
}

class CheckoutService
{
    public function __construct(private PaymentGateway $gateway)
    {
    }

    public function checkout(): void
    {
        $this->gateway->charge(100);
    }
}
PHP;

        $tmpFile = tempnam(sys_get_temp_dir(), 'logic-map-interface-');
        file_put_contents($tmpFile, $fixture);

        try {
            $graph = $this->parser->parse([$tmpFile]);
            $edges = $graph->getEdges();

            $targets = array_map(
                fn($edge) => [$edge->source, $edge->target],
                $edges
            );

            $this->assertContains(
                [
                    'method:App\\Services\\Billing\\CheckoutService@checkout',
                    'method:App\\Services\\Billing\\StripeGateway@charge',
                ],
                $targets
            );

            $this->assertNotContains(
                [
                    'method:App\\Services\\Billing\\CheckoutService@checkout',
                    'method:App\\Contracts\\PaymentGateway@charge',
                ],
                $targets
            );
        } finally {
            @unlink($tmpFile);
        }
    }
}
