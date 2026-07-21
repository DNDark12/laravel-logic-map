<?php

namespace DNDark\LogicMap\Tests\Unit\Domain\Snapshot;

use DateTimeImmutable;
use DNDark\LogicMap\Domain\Graph\KnowledgeGraph;
use DNDark\LogicMap\Domain\Graph\GraphNode;
use DNDark\LogicMap\Domain\Graph\NodeId;
use DNDark\LogicMap\Domain\Graph\NodeKind;
use DNDark\LogicMap\Domain\Snapshot\Diagnostic;
use DNDark\LogicMap\Domain\Snapshot\DiagnosticCode;
use DNDark\LogicMap\Domain\Snapshot\GraphSnapshot;
use DNDark\LogicMap\Domain\Snapshot\IndexedFile;
use DNDark\LogicMap\Domain\Snapshot\ProcessStepRecord;
use DNDark\LogicMap\Domain\Workflow\ExecutionBoundary;
use DNDark\LogicMap\Domain\Workflow\WorkflowStepKind;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class GraphSnapshotTest extends TestCase
{
    public function test_snapshot_identity_ignores_time_and_phase_metrics(): void
    {
        $fingerprint = hash('sha256', 'fixture');
        $id = hash('sha256', '1'."\0".$fingerprint);

        $first = new GraphSnapshot(
            $id,
            1,
            '2.0-core-1',
            new DateTimeImmutable('2026-07-16T01:00:00+00:00'),
            $fingerprint,
            [
                new IndexedFile('routes/web.php', hash('sha256', 'routes'), 50),
                new IndexedFile('app/Order.php', hash('sha256', 'model'), 100),
            ],
            new KnowledgeGraph(),
            [],
            ['parse' => ['duration_ms' => 10]],
        );
        $second = new GraphSnapshot(
            $id,
            1,
            '2.0-core-1',
            new DateTimeImmutable('2026-07-16T02:00:00+00:00'),
            $fingerprint,
            [
                new IndexedFile('app/Order.php', hash('sha256', 'model'), 100),
                new IndexedFile('routes/web.php', hash('sha256', 'routes'), 50),
            ],
            new KnowledgeGraph(),
            [],
            ['parse' => ['duration_ms' => 99]],
        );

        self::assertSame($first->id, $second->id);
        self::assertSame(['app/Order.php', 'routes/web.php'], array_map(
            static fn (IndexedFile $file): string => $file->path,
            $first->files,
        ));
        self::assertSame('2.0-core-1', $first->analysisVersion);
    }

    public function test_rejects_a_snapshot_id_not_derived_from_schema_and_fingerprint(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new GraphSnapshot(
            'invalid',
            1,
            '2.0-core-1',
            new DateTimeImmutable(),
            hash('sha256', 'fixture'),
            [],
            new KnowledgeGraph(),
            [],
            [],
        );
    }

    public function test_indexed_files_and_diagnostics_enforce_relative_valid_spans(): void
    {
        $diagnostic = new Diagnostic(
            DiagnosticCode::ParseError,
            'parse',
            'app/Bad.php',
            3,
            4,
            'Unexpected token',
            ['exception' => 'PhpParser\\Error'],
        );

        self::assertSame('app/Bad.php', $diagnostic->file);
        self::assertSame(3, $diagnostic->startLine);

        $this->expectException(InvalidArgumentException::class);
        new IndexedFile('../outside.php', hash('sha256', 'bad'), 3);
    }

    public function test_process_steps_are_validated_and_sorted_by_process_then_ordinal(): void
    {
        $fingerprint = hash('sha256', 'process-fixture');
        $id = hash('sha256', '1'."\0".$fingerprint);
        $graph = new KnowledgeGraph();
        $route = NodeId::route('POST', 'orders/{order}/cancel');
        $process = NodeId::named(NodeKind::Process, $route->value);
        $graph->addNode(new GraphNode($route, NodeKind::Route, 'POST orders/{order}/cancel', null, null));
        $graph->addNode(new GraphNode($process, NodeKind::Process, 'Cancel order process', null, null));
        $snapshot = new GraphSnapshot(
            $id,
            1,
            '2.0-workflow-1',
            new DateTimeImmutable('2026-07-16T01:00:00+00:00'),
            $fingerprint,
            [],
            $graph,
            [],
            [],
            [
                new ProcessStepRecord(
                    $process,
                    1,
                    'decision:cancelable',
                    null,
                    WorkflowStepKind::Decision,
                    ExecutionBoundary::Sync,
                    [],
                    ['condition' => '$order->isCancelable()'],
                ),
                new ProcessStepRecord(
                    $process,
                    0,
                    'step:route',
                    $route,
                    WorkflowStepKind::Entry,
                    ExecutionBoundary::Sync,
                    [],
                    ['label' => 'Cancel order'],
                ),
            ],
        );

        self::assertSame([0, 1], array_map(
            static fn (ProcessStepRecord $step): int => $step->ordinal,
            $snapshot->processSteps,
        ));
        self::assertSame([
            'process_id', 'ordinal', 'step_id', 'node_id', 'step_kind',
            'boundary', 'evidence_ids', 'attributes',
        ], array_keys($snapshot->processSteps[0]->toArray()));
    }
}
