<?php

namespace DNDark\LogicMap\Tests\Unit\Analysis\Laravel;

use DNDark\LogicMap\Analysis\Laravel\Detectors\EloquentEffectDetector;
use DNDark\LogicMap\Analysis\Laravel\Facts\EloquentChainFactCollector;
use DNDark\LogicMap\Analysis\Laravel\ModelTableResolver;
use DNDark\LogicMap\Analysis\Php\PhpFileParser;
use DNDark\LogicMap\Analysis\Php\StructuralGraphBuilder;
use DNDark\LogicMap\Analysis\Php\SymbolTable;
use DNDark\LogicMap\Domain\Graph\EdgeType;
use DNDark\LogicMap\Domain\Graph\GraphEdge;
use DNDark\LogicMap\Domain\Graph\KnowledgeGraph;
use DNDark\LogicMap\Domain\Snapshot\DiagnosticCode;
use PHPUnit\Framework\TestCase;

final class EloquentEffectDetectorTest extends TestCase
{
    public function test_operation_sets_are_closed_and_exact(): void
    {
        $detector = new EloquentEffectDetector();

        foreach (['find', 'first', 'firstOrFail', 'get', 'pluck', 'count', 'exists', 'paginate', 'cursor', 'value'] as $method) {
            self::assertSame('read', $detector->classifyOperation($method), $method);
        }

        foreach (['save', 'create', 'update', 'delete', 'forceDelete', 'restore', 'increment', 'decrement', 'insert', 'insertGetId', 'upsert', 'firstOrCreate', 'updateOrCreate', 'touch', 'attach', 'detach', 'sync'] as $method) {
            self::assertSame('write', $detector->classifyOperation($method), $method);
        }

        self::assertNull($detector->classifyOperation('map'));
    }

    public function test_model_table_and_column_effects_use_facts_without_model_instantiation(): void
    {
        [$parsed, $symbols, $graph] = $this->fixture();
        $result = (new EloquentEffectDetector())->detect([$parsed], $symbols, $graph);
        $source = 'method:App\BillingService::run';

        self::assertNotEmpty($this->edges($graph, $source, EdgeType::ReadsModel, 'class:App\Invoice'));
        self::assertNotEmpty($this->edges($graph, $source, EdgeType::ReadsTable, 'table:billing_invoices'));
        self::assertGreaterThanOrEqual(2, count($this->edges(
            $graph,
            $source,
            EdgeType::WritesModel,
            'class:App\Invoice',
        )));
        self::assertGreaterThanOrEqual(2, count($this->edges(
            $graph,
            $source,
            EdgeType::WritesColumn,
            'column:billing_invoices.status',
        )));
        self::assertContains(DiagnosticCode::UnknownColumnSet, array_map(
            static fn ($diagnostic): DiagnosticCode => $diagnostic->code,
            $result->diagnostics,
        ));

        $resolver = new ModelTableResolver();
        self::assertSame('billing_invoices', $resolver->resolve('App\Invoice', [$parsed], $symbols)['table']);
        self::assertSame('convention_records', $resolver->resolve('App\ConventionRecord', [$parsed], $symbols)['table']);
        $dynamic = $resolver->resolve('App\DynamicRecord', [$parsed], $symbols);
        self::assertNull($dynamic['table']);
        self::assertSame(DiagnosticCode::UnknownTable, $dynamic['diagnostics'][0]->code);
        self::assertCount(1, $this->edges(
            $graph,
            'method:App\BillingService::preview',
            EdgeType::ReadsColumn,
            'column:billing_invoices.status',
        ));
    }

    public function test_gateway_confirmed_save_connects_caller_assignments_to_written_columns(): void
    {
        $source = <<<'PHP'
<?php
namespace App;

use Illuminate\Database\Eloquent\Model;

final class Order extends Model { protected $table = 'orders'; }
interface OrderGateway { public function save(Order $order): void; }
final class DatabaseOrderGateway implements OrderGateway
{
    public function save(Order $order): void { $order->save(); }
}
final class OrderService
{
    public function __construct(private OrderGateway $orders) {}

    public function cancel(Order $order): void
    {
        $order->status = 'cancelled';
        $order->cancellation_reason = 'customer_request';
        $this->orders->save($order);
    }

    public function preview(Order $order): void
    {
        $order->status = 'preview';
    }
}
PHP;
        $parsed = (new PhpFileParser([new EloquentChainFactCollector()]))
            ->parse('app/Orders.php', $source);
        $symbols = new SymbolTable();

        foreach ($parsed->symbols as $symbol) {
            $symbols->add($symbol);
        }

        $graph = new KnowledgeGraph();
        (new StructuralGraphBuilder($symbols))->build([$parsed], $graph);
        (new EloquentEffectDetector())->detect([$parsed], $symbols, $graph);

        self::assertCount(1, $this->edges(
            $graph,
            'method:App\OrderService::cancel',
            EdgeType::WritesColumn,
            'column:orders.status',
        ));
        self::assertCount(1, $this->edges(
            $graph,
            'method:App\OrderService::cancel',
            EdgeType::WritesColumn,
            'column:orders.cancellation_reason',
        ));
        self::assertSame([], $this->edges(
            $graph,
            'method:App\OrderService::preview',
            EdgeType::WritesColumn,
            'column:orders.status',
        ));
    }

    private function fixture(): array
    {
        $source = <<<'PHP'
<?php
namespace App;

use Illuminate\Database\Eloquent\Model;

final class Invoice extends Model { protected $table = 'billing_invoices'; }
final class ConventionRecord extends Model {}
final class DynamicRecord extends Model
{
    private const TABLE_NAME = 'dynamic_records';
    protected $table = self::TABLE_NAME;
}
final class BillingService
{
    public function run(Invoice $invoice, array $attributes): void
    {
        $invoice->status = 'paid';
        $invoice->save();
        Invoice::query()->where('status', 'paid')->get();
        Invoice::query()->update(['status' => 'archived']);
        Invoice::query()->update($attributes);
    }

    public function preview(Invoice $invoice): string
    {
        return $invoice->status;
    }
}
PHP;
        $parsed = (new PhpFileParser([new EloquentChainFactCollector()]))
            ->parse('app/Billing.php', $source);
        $symbols = new SymbolTable();

        foreach ($parsed->symbols as $symbol) {
            $symbols->add($symbol);
        }

        $graph = new KnowledgeGraph();
        (new StructuralGraphBuilder($symbols))->build([$parsed], $graph);

        return [$parsed, $symbols, $graph];
    }

    private function edges(KnowledgeGraph $graph, string $source, EdgeType $type, string $target): array
    {
        return array_values(array_filter(
            $graph->edges(),
            static fn (GraphEdge $edge): bool => $edge->source->value === $source
                && $edge->type === $type
                && $edge->target->value === $target,
        ));
    }
}
