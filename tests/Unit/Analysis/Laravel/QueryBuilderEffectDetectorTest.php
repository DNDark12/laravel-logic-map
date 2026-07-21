<?php

namespace DNDark\LogicMap\Tests\Unit\Analysis\Laravel;

use DNDark\LogicMap\Analysis\Laravel\Detectors\QueryBuilderEffectDetector;
use DNDark\LogicMap\Analysis\Laravel\Facts\EloquentChainFactCollector;
use DNDark\LogicMap\Analysis\Php\PhpFileParser;
use DNDark\LogicMap\Analysis\Php\StructuralGraphBuilder;
use DNDark\LogicMap\Analysis\Php\SymbolTable;
use DNDark\LogicMap\Domain\Graph\EdgeType;
use DNDark\LogicMap\Domain\Graph\GraphEdge;
use DNDark\LogicMap\Domain\Graph\KnowledgeGraph;
use DNDark\LogicMap\Domain\Snapshot\DiagnosticCode;
use PHPUnit\Framework\TestCase;

final class QueryBuilderEffectDetectorTest extends TestCase
{
    public function test_literal_dynamic_and_raw_query_effects_are_conservative(): void
    {
        $source = <<<'PHP'
<?php
namespace App;

use Illuminate\Support\Facades\DB;

final class QueryService
{
    public function run(string $table, array $attributes, string $sql): void
    {
        DB::table('orders')->where('id', 1)->update(['status' => 'paid']);
        DB::table('orders')->update($attributes);
        DB::table($table)->delete();
        DB::statement('UPDATE audit_logs SET processed = 1');
        DB::statement($sql);
    }
}
PHP;
        $parsed = (new PhpFileParser([new EloquentChainFactCollector()]))
            ->parse('app/QueryService.php', $source);
        $symbols = new SymbolTable();

        foreach ($parsed->symbols as $symbol) {
            $symbols->add($symbol);
        }

        $graph = new KnowledgeGraph();
        (new StructuralGraphBuilder($symbols))->build([$parsed], $graph);
        $result = (new QueryBuilderEffectDetector())->detect([$parsed], $symbols, $graph);
        $caller = 'method:App\QueryService::run';

        self::assertGreaterThanOrEqual(2, count($this->edges(
            $graph,
            $caller,
            EdgeType::WritesTable,
            'table:orders',
        )));
        self::assertCount(1, $this->edges(
            $graph,
            $caller,
            EdgeType::WritesColumn,
            'column:orders.status',
        ));
        self::assertCount(1, $this->edges(
            $graph,
            $caller,
            EdgeType::WritesTable,
            'table:audit_logs',
        ));

        $codes = array_map(static fn ($diagnostic): DiagnosticCode => $diagnostic->code, $result->diagnostics);
        self::assertContains(DiagnosticCode::UnknownTable, $codes);
        self::assertContains(DiagnosticCode::UnknownColumnSet, $codes);
        self::assertContains(DiagnosticCode::UnparsedRawSql, $codes);
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
