<?php

namespace DNDark\LogicMap\Tests\Unit\Analysis\Laravel\Boot;

use DNDark\LogicMap\Analysis\Laravel\Boot\LaravelBootInspector;
use DNDark\LogicMap\Analysis\Laravel\Boot\RouteBootCollector;
use DNDark\LogicMap\Analysis\Php\SymbolTable;
use DNDark\LogicMap\Analysis\Pipeline\PhaseResult;
use DNDark\LogicMap\Analysis\Pipeline\Phases\CollectLaravelBootFactsPhase;
use DNDark\LogicMap\Analysis\Pipeline\PipelineContext;
use DNDark\LogicMap\Domain\Graph\KnowledgeGraph;
use DNDark\LogicMap\Domain\Snapshot\DiagnosticCode;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class CollectLaravelBootFactsPhaseTest extends TestCase
{
    public function test_phase_degrades_boot_failure_to_diagnostics_and_preserves_pipeline_execution(): void
    {
        $phase = new CollectLaravelBootFactsPhase(new LaravelBootInspector(
            static fn () => throw new RuntimeException('fixture boot failed'),
            [new RouteBootCollector()],
        ));

        $result = $phase->execute(
            new PipelineContext(new KnowledgeGraph()),
            [
                'resolve_php' => new PhaseResult('resolve_php', [
                    'parsed_files' => [],
                    'symbol_table' => new SymbolTable(),
                ]),
            ],
        );

        self::assertSame('collect_laravel_boot', $phase->name());
        self::assertSame(['resolve_php'], $phase->dependencies());
        self::assertSame([], $result->value['boot_facts']);
        self::assertSame(DiagnosticCode::BootInspectionFailed, $result->diagnostics[0]->code);
        self::assertSame(0, $result->metrics['boot_fact_count']);
        self::assertSame(1, $result->metrics['diagnostic_count']);
    }
}
