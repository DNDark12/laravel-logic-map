<?php

namespace DNDark\LogicMap\Tests\Unit\Projectors;

use DateTimeImmutable;
use DNDark\LogicMap\Domain\Graph\Certainty;
use DNDark\LogicMap\Domain\Graph\EvidenceOrigin;
use DNDark\LogicMap\Domain\Graph\EvidenceRecord;
use DNDark\LogicMap\Domain\Graph\NodeId;
use DNDark\LogicMap\Domain\Graph\SourceLocation;
use DNDark\LogicMap\Domain\Impact\AffectedSymbol;
use DNDark\LogicMap\Domain\Impact\ChangedSymbol;
use DNDark\LogicMap\Domain\Impact\ChangeType;
use DNDark\LogicMap\Domain\Impact\ImpactCategory;
use DNDark\LogicMap\Domain\Impact\ImpactLevel;
use DNDark\LogicMap\Domain\Impact\ImpactReason;
use DNDark\LogicMap\Domain\Impact\ImpactReport;
use DNDark\LogicMap\Projectors\ImpactJsonProjector;
use DNDark\LogicMap\Projectors\ImpactMarkdownProjector;
use PHPUnit\Framework\TestCase;

final class V2ImpactProjectorTest extends TestCase
{
    public function test_impact_json_and_markdown_keep_reason_specific_sections(): void
    {
        $evidence = new EvidenceRecord(
            EvidenceOrigin::GitDiff,
            'impact-projector-test',
            Certainty::Certain,
            new SourceLocation('app/OrderService.php', 20, 22),
            'changed lines',
        );
        $changedId = NodeId::method('App\Services\OrderService', 'cancel');
        $change = new ChangedSymbol(
            ChangeType::Modified,
            $changedId,
            $changedId,
            'app/OrderService.php',
            'app/OrderService.php',
            20,
            22,
            20,
            22,
            $evidence,
        );
        $affected = [];

        foreach ([
            [NodeId::fromString('process:cancel-order'), ImpactCategory::Workflow, ImpactLevel::Direct],
            [NodeId::fromString('module:Shipping'), ImpactCategory::Module, ImpactLevel::Direct],
            [NodeId::method('App\ShippingService', 'canShip'), ImpactCategory::SharedState, ImpactLevel::SharedResource],
            [NodeId::fromString('external:https://erp.example/cancel'), ImpactCategory::ExternalContract, ImpactLevel::Direct],
            [$changedId, ImpactCategory::Uncertainty, ImpactLevel::Possible],
        ] as $index => [$target, $category, $level]) {
            $self = $target->equals($changedId);
            $affected[] = new AffectedSymbol($target, [new ImpactReason(
                $category,
                $level,
                $self ? [$changedId->value] : [$changedId->value, $target->value],
                $self ? [] : ['edge-'.$index],
                [$evidence->id()],
                "Reason {$category->value}.",
            )]);
        }

        $report = new ImpactReport(
            [$change],
            $affected,
            [$evidence],
            array_fill_keys(array_map(static fn (ImpactCategory $category): string => $category->value, ImpactCategory::cases()), [
                'truncated' => false,
                'max_depth' => 1,
                'visited_count' => 1,
                'edge_count' => 1,
                'omitted_count' => 0,
                'frontier' => [],
            ]),
            selectedTests: [[
                'test_node_id' => 'test:tests/Feature/CancelOrderTest.php::test_cancel',
                'rank' => 2,
                'reason' => 'Direct static symbol reference.',
                'evidence_ids' => [$evidence->id()],
            ]],
        );
        $projected = (new ImpactJsonProjector())->project($report);

        self::assertSame([
            'change_set', 'summary', 'changed_symbols', 'affected_symbols', 'workflows',
            'modules', 'shared_resources', 'external_contracts', 'tests', 'uncertainty',
            'truncation', 'evidence',
        ], array_keys($projected));
        self::assertCount(1, $projected['workflows']);
        self::assertCount(1, $projected['modules']);
        self::assertCount(1, $projected['shared_resources']);
        self::assertCount(1, $projected['external_contracts']);
        self::assertCount(1, $projected['tests']);
        self::assertCount(1, $projected['uncertainty']);
        self::assertSame('modified', $projected['changed_symbols'][0]['change_type']);
        self::assertSame($evidence->id(), $projected['evidence'][0]['id']);

        $markdown = (new ImpactMarkdownProjector())->project(
            $report,
            'snapshot-2',
            $changedId->value,
            new DateTimeImmutable('2026-07-17T10:00:00+07:00'),
        );
        self::assertStringStartsWith("---\nschema_version: 2\n", $markdown);
        self::assertStringContainsString('snapshot_id: "snapshot-2"', $markdown);
        self::assertStringContainsString('## Shared resources', $markdown);
        self::assertStringContainsString('## Selected tests', $markdown);
        self::assertStringContainsString('[app/OrderService.php:20](app/OrderService.php#L20)', $markdown);
    }
}
