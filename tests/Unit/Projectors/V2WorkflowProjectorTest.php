<?php

namespace DNDark\LogicMap\Tests\Unit\Projectors;

use DateTimeImmutable;
use DNDark\LogicMap\Domain\Graph\Certainty;
use DNDark\LogicMap\Domain\Graph\EvidenceOrigin;
use DNDark\LogicMap\Domain\Graph\EvidenceRecord;
use DNDark\LogicMap\Domain\Graph\NodeId;
use DNDark\LogicMap\Domain\Graph\SourceLocation;
use DNDark\LogicMap\Domain\Workflow\ExecutionBoundary;
use DNDark\LogicMap\Domain\Workflow\TransactionSegment;
use DNDark\LogicMap\Domain\Workflow\WorkflowDefinition;
use DNDark\LogicMap\Domain\Workflow\WorkflowGap;
use DNDark\LogicMap\Domain\Workflow\WorkflowId;
use DNDark\LogicMap\Domain\Workflow\WorkflowStep;
use DNDark\LogicMap\Domain\Workflow\WorkflowStepKind;
use DNDark\LogicMap\Domain\Workflow\WorkflowTransition;
use DNDark\LogicMap\Projectors\WorkflowJsonProjector;
use DNDark\LogicMap\Projectors\WorkflowMarkdownProjector;
use PHPUnit\Framework\TestCase;

final class V2WorkflowProjectorTest extends TestCase
{
    public function test_json_and_markdown_have_canonical_sections_and_evidence_links(): void
    {
        [$workflow, $evidence] = $this->fixture();
        $projector = new WorkflowJsonProjector();
        $first = $projector->project($workflow, 'snapshot-1', [$evidence]);
        $second = $projector->project($workflow, 'snapshot-1', [$evidence]);

        self::assertSame([
            'identity', 'entrypoint', 'summary', 'steps', 'transitions', 'transactions',
            'modules', 'effects', 'gaps', 'truncation', 'evidence',
        ], array_keys($first));
        self::assertSame($first, $second);
        self::assertSame('workflow:test', $first['identity']['workflow_id']);
        self::assertSame('route:POST:orders/cancel', $first['entrypoint']['node_id']);
        self::assertSame($evidence->id(), $first['evidence'][0]['id']);

        $markdown = (new WorkflowMarkdownProjector())->project(
            $workflow,
            'snapshot-1',
            new DateTimeImmutable('2026-07-17T10:00:00+07:00'),
            [$evidence],
        );
        self::assertStringStartsWith("---\nschema_version: 2\n", $markdown);
        self::assertStringContainsString('snapshot_id: "snapshot-1"', $markdown);
        self::assertStringContainsString('target_id: "route:POST:orders/cancel"', $markdown);
        self::assertStringContainsString('[app/OrderService.php:10](app/OrderService.php#L10)', $markdown);
        self::assertStringContainsString('## Gaps', $markdown);
    }

    private function fixture(): array
    {
        $evidence = new EvidenceRecord(
            EvidenceOrigin::StaticAst,
            'projector-test',
            Certainty::Certain,
            new SourceLocation('app/OrderService.php', 10, 12),
            '$orders->cancel()',
        );
        $steps = [
            new WorkflowStep('step:a', WorkflowStepKind::Entry, 'Cancel route', NodeId::route('POST', 'orders/cancel'), 'Orders', [$evidence->id()]),
            new WorkflowStep('step:b', WorkflowStepKind::Effect, 'Write orders.status', NodeId::fromString('column:orders.status'), 'Orders', [$evidence->id()]),
            new WorkflowStep('step:c', WorkflowStepKind::Gap, 'Dynamic continuation', null, null, [$evidence->id()]),
        ];
        $workflow = new WorkflowDefinition(
            new WorkflowId('workflow:test'),
            NodeId::route('POST', 'orders/cancel'),
            'step:a',
            $steps,
            [
                new WorkflowTransition('step:a', 'step:b', ExecutionBoundary::Sync, null, null, false, [$evidence->id()]),
                new WorkflowTransition('step:b', 'step:c', ExecutionBoundary::Sync, null, null, false, [$evidence->id()]),
            ],
            [new TransactionSegment('transaction:1', ['step:b'], [$evidence->id()])],
            [new WorkflowGap('step:c', 'Dynamic continuation', [$evidence->id()])],
        );

        return [$workflow, $evidence];
    }
}
