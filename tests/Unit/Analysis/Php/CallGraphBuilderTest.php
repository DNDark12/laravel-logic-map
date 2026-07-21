<?php

namespace DNDark\LogicMap\Tests\Unit\Analysis\Php;

use DNDark\LogicMap\Analysis\Php\CallGraphBuilder;
use DNDark\LogicMap\Analysis\Php\CallTargetResolver;
use DNDark\LogicMap\Analysis\Php\PhpFileParser;
use DNDark\LogicMap\Analysis\Php\StructuralGraphBuilder;
use DNDark\LogicMap\Analysis\Php\SymbolTable;
use DNDark\LogicMap\Domain\Graph\EdgeType;
use DNDark\LogicMap\Domain\Graph\KnowledgeGraph;
use DNDark\LogicMap\Domain\Snapshot\DiagnosticCode;
use PHPUnit\Framework\TestCase;

final class CallGraphBuilderTest extends TestCase
{
    public function test_builds_a_probable_interface_call_edge_for_the_commerce_fixture(): void
    {
        $root = dirname(__DIR__, 3).'/Fixtures/CommerceApp/app';
        $parser = new PhpFileParser();
        $files = [];

        foreach ([
            'Contracts/OrderGateway.php',
            'Repositories/DatabaseOrderGateway.php',
            'Services/OrderService.php',
        ] as $path) {
            $source = file_get_contents($root.'/'.$path);
            self::assertIsString($source);
            $files[] = $parser->parse('app/'.$path, $source);
        }

        [$graph, $diagnostics] = $this->build($files);
        $edges = array_values(array_filter(
            $graph->edges(),
            static fn ($edge): bool => $edge->type === EdgeType::Calls
                && $edge->source->value === 'method:Fixtures\CommerceApp\Services\OrderService::cancel'
                && $edge->target->value === 'method:Fixtures\CommerceApp\Repositories\DatabaseOrderGateway::save',
        ));

        self::assertCount(1, $edges);
        self::assertSame('probable', $edges[0]->evidence[0]->certainty->value);
        self::assertSame('interface_implementation', $edges[0]->evidence[0]->attributes['receiver_resolution']);
        self::assertSame([], array_values(array_filter(
            $diagnostics,
            static fn ($diagnostic): bool => ($diagnostic->attributes['receiver_type'] ?? null)
                === 'Fixtures\\CommerceApp\\Contracts\\OrderGateway'
                && ($diagnostic->attributes['target_name'] ?? null) === 'save',
        )));
    }

    public function test_covers_enum_anonymous_nullsafe_first_class_and_duplicate_syntax_without_guessing(): void
    {
        $source = <<<'PHP'
<?php
namespace App;

class Gateway { public function save(?Order $order = null): void {} }
class Order { public function gateway(): Gateway { return new Gateway(); } }
enum Flag
{
    case Enabled;
    public function run(Gateway $gateway): void { $gateway->save(); }
}
class Calls
{
    public function run(?Order $order, Gateway $gateway): void
    {
        $order?->gateway()?->save($order);
        $callable = $gateway->save(...);
        $worker = new class {
            public function run(Gateway $gateway): void { $gateway->save(); }
        };
    }
}
class Duplicate { public function broken(): void {} }
class Duplicate { public function broken(): void {} }
PHP;
        $parsed = (new PhpFileParser())->parse('app/Mixed.php', $source);
        [$graph, $diagnostics] = $this->build([$parsed]);
        $callEdges = array_values(array_filter(
            $graph->edges(),
            static fn ($edge): bool => $edge->type === EdgeType::Calls,
        ));

        self::assertNotEmpty(array_filter($callEdges, static fn ($edge): bool =>
            $edge->source->value === 'method:App\Flag::run'
            && $edge->target->value === 'method:App\Gateway::save'));
        self::assertNotEmpty(array_filter($callEdges, static fn ($edge): bool =>
            $edge->source->value === 'method:app/Mixed.php@anonymous[0]::run'
            && $edge->target->value === 'method:App\Gateway::save'));
        self::assertGreaterThanOrEqual(2, count(array_filter($callEdges, static fn ($edge): bool =>
            ($edge->evidence[0]->attributes['nullsafe'] ?? false) === true)));
        self::assertNotEmpty(array_filter($callEdges, static fn ($edge): bool =>
            ($edge->evidence[0]->attributes['first_class_callable'] ?? false) === true
            && $edge->evidence[0]->attributes['arguments'] === []));
        self::assertSame([], array_values(array_filter($callEdges, static fn ($edge): bool =>
            str_contains($edge->source->value, 'App\Duplicate')
            || str_contains($edge->target->value, 'App\Duplicate'))));
        self::assertNotEmpty(array_filter($diagnostics, static fn ($diagnostic): bool =>
            $diagnostic->code === DiagnosticCode::DuplicateSymbol));
    }

    public function test_persists_exact_missing_target_diagnostics_without_inventing_an_edge(): void
    {
        $parsed = (new PhpFileParser())->parse('app/Missing.php', <<<'PHP'
<?php
namespace App;
final class Gateway {}
final class Caller
{
    public function run(Gateway $gateway): void
    {
        $gateway->deleted();
    }
}
PHP);
        [$graph, $diagnostics] = $this->build([$parsed]);
        $missing = array_values(array_filter(
            $diagnostics,
            static fn ($diagnostic): bool => $diagnostic->code === DiagnosticCode::UnresolvedTarget,
        ));

        self::assertCount(1, $missing);
        self::assertSame('method:App\Caller::run', $missing[0]->attributes['enclosing_symbol_id']);
        self::assertSame('method:App\Gateway::deleted', $missing[0]->attributes['attempted_target_id']);
        self::assertSame([], array_values(array_filter(
            $graph->edges(),
            static fn ($edge): bool => $edge->type === EdgeType::Calls
                && $edge->target->value === 'method:App\Gateway::deleted',
        )));
    }

    private function build(array $files): array
    {
        $table = new SymbolTable();

        foreach ($files as $file) {
            foreach ($file->symbols as $symbol) {
                $table->add($symbol);
            }
        }

        $graph = new KnowledgeGraph();
        $structural = new StructuralGraphBuilder($table);
        $diagnostics = $structural->build($files, $graph);
        $calls = new CallGraphBuilder(new CallTargetResolver($table), $table);
        $diagnostics = [...$diagnostics, ...$calls->build($files, $graph)];

        return [$graph, $diagnostics];
    }
}
