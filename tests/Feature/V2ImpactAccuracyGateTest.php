<?php

namespace DNDark\LogicMap\Tests\Feature;

use DateTimeImmutable;
use DNDark\LogicMap\Analysis\Laravel\Boot\CommandBootCollector;
use DNDark\LogicMap\Analysis\Laravel\Boot\ContainerBootCollector;
use DNDark\LogicMap\Analysis\Laravel\Boot\EventBootCollector;
use DNDark\LogicMap\Analysis\Laravel\Boot\LaravelBootInspector;
use DNDark\LogicMap\Analysis\Laravel\Boot\PolicyBootCollector;
use DNDark\LogicMap\Analysis\Laravel\Boot\RouteBootCollector;
use DNDark\LogicMap\Analysis\Laravel\Boot\ScheduleBootCollector;
use DNDark\LogicMap\Analysis\Laravel\Facts\BranchConditionFactCollector;
use DNDark\LogicMap\Analysis\Laravel\Facts\EloquentChainFactCollector;
use DNDark\LogicMap\Analysis\Laravel\Facts\FacadeEffectFactCollector;
use DNDark\LogicMap\Analysis\Laravel\Facts\LaravelRegistrationFactCollector;
use DNDark\LogicMap\Analysis\Laravel\LaravelSemanticAnalyzer;
use DNDark\LogicMap\Analysis\Php\CallGraphBuilder;
use DNDark\LogicMap\Analysis\Php\CallTargetResolver;
use DNDark\LogicMap\Analysis\Php\PhpFileParser;
use DNDark\LogicMap\Analysis\Php\StructuralGraphBuilder;
use DNDark\LogicMap\Analysis\Php\SymbolTable;
use DNDark\LogicMap\Analysis\Pipeline\PhaseResult;
use DNDark\LogicMap\Analysis\Pipeline\PipelineContext;
use DNDark\LogicMap\Analysis\Pipeline\Phases\BuildProcessMembershipPhase;
use DNDark\LogicMap\Domain\Graph\KnowledgeGraph;
use DNDark\LogicMap\Domain\Graph\NodeId;
use DNDark\LogicMap\Domain\Impact\ChangedFile;
use DNDark\LogicMap\Domain\Impact\ChangeType;
use DNDark\LogicMap\Domain\Impact\ImpactCategory;
use DNDark\LogicMap\Domain\Impact\ImpactLevel;
use DNDark\LogicMap\Domain\Impact\ImpactReport;
use DNDark\LogicMap\Projectors\ImpactJsonProjector;
use DNDark\LogicMap\Projectors\ImpactMarkdownProjector;
use DNDark\LogicMap\Services\Impact\BaseRefSymbolResolver;
use DNDark\LogicMap\Services\Impact\ChangedSymbolMapper;
use DNDark\LogicMap\Services\Impact\ImpactAnalyzer;
use DNDark\LogicMap\Services\Impact\ImpactPolicy;
use DNDark\LogicMap\Services\Impact\ImpactRequest;
use DNDark\LogicMap\Services\Impact\SharedResourceImpactAnalyzer;
use DNDark\LogicMap\Services\Impact\TestScopeResolver;
use DNDark\LogicMap\Services\Workflow\EdgeDirectionPolicy;
use DNDark\LogicMap\Support\Git\GitDiffChangeProvider;
use DNDark\LogicMap\Support\Git\GitObjectReader;
use DNDark\LogicMap\Support\Git\NativeGitCommandRunner;
use DNDark\LogicMap\Tests\Support\CommerceFixtureTestCase;
use DNDark\LogicMap\Tests\Support\TemporaryGitRepository;

final class V2ImpactAccuracyGateTest extends CommerceFixtureTestCase
{
    /** @var list<TemporaryGitRepository> */
    private array $repositories = [];

    protected function tearDown(): void
    {
        foreach ($this->repositories as $repository) {
            $repository->remove();
        }

        parent::tearDown();
    }

    public function test_gate_d_locks_modified_order_cancellation_blast_radius(): void
    {
        [$report] = $this->impact('order-cancel-change.diff');
        $modules = array_values(array_map(
            static fn ($symbol): string => substr($symbol->nodeId->value, strlen('module:')),
            array_values(array_filter(
                $report->affectedSymbols,
                static fn ($symbol): bool => str_starts_with($symbol->nodeId->value, 'module:'),
            )),
        ));
        sort($modules, SORT_STRING);
        self::assertSame(['Dashboard', 'Integration', 'Inventory', 'Orders', 'Shipping'], $modules);

        $this->assertReason($report, 'process:route:POST:orders/{order}/cancel', ImpactCategory::Workflow);
        $this->assertReason($report, 'method:Fixtures\CommerceApp\Services\ShippingService::canShip', ImpactCategory::SharedState);
        $this->assertReason($report, 'method:Fixtures\CommerceApp\Services\SalesDashboardService::cancelledOrderCount', ImpactCategory::SharedState);
        $this->assertReason($report, 'method:Fixtures\CommerceApp\Services\InventoryReconciliationService::totalQuantity', ImpactCategory::SharedState);
        $this->assertReason($report, 'class:Fixtures\CommerceApp\Listeners\RestockInventory', ImpactCategory::Async);
        $this->assertReason($report, 'class:Fixtures\CommerceApp\Listeners\SendCancellationWebhook', ImpactCategory::Async);
        $this->assertReason($report, 'external:{config:services.erp.base_url}/orders/{id}/cancel', ImpactCategory::ExternalContract);
        $this->assertEvidenceIntegrity($report);

        $this->assertJsonGolden($report, 'impact/order-cancel-change.json');
        $markdown = (new ImpactMarkdownProjector())->project(
            $report,
            'commerce-fixture',
            'method:Fixtures\CommerceApp\Services\OrderService::cancel',
            new DateTimeImmutable('2026-07-17T10:00:00+07:00'),
        );
        $this->assertTextGolden($markdown, 'impact/order-cancel-change.md');
    }

    public function test_gate_d_locks_renamed_deleted_and_added_symbol_semantics(): void
    {
        [$report] = $this->impact('rename-and-delete.diff');
        $renamed = array_values(array_filter(
            $report->changedSymbols,
            static fn ($change): bool => $change->changeType === ChangeType::Renamed
                && $change->oldNodeId?->value === 'class:Fixtures\CommerceApp\Listeners\SendCancellationWebhook',
        ));
        self::assertCount(1, $renamed, json_encode(array_map(
            static fn ($change): array => [
                $change->changeType->value,
                $change->oldNodeId?->value,
                $change->newNodeId?->value,
            ],
            $report->changedSymbols,
        ), JSON_THROW_ON_ERROR));
        self::assertSame(
            'class:Fixtures\CommerceApp\Listeners\SendOrderCancellationWebhook',
            $renamed[0]->newNodeId?->value,
        );
        $this->assertReason($report, 'class:Fixtures\CommerceApp\Events\OrderCancelled', ImpactCategory::Async);

        $deleted = array_values(array_filter(
            $report->changedSymbols,
            static fn ($change): bool => $change->changeType === ChangeType::Deleted
                && $change->oldNodeId?->value === 'method:Fixtures\CommerceApp\Models\Order::canBeCancelled',
        ));
        self::assertCount(1, $deleted);
        self::assertSame([
            'method:Fixtures\CommerceApp\Policies\OrderPolicy::cancel',
            'method:Fixtures\CommerceApp\Services\OrderService::cancel',
        ], $deleted[0]->attributes['diagnostic_callers'] ?? [], json_encode($deleted[0]->attributes, JSON_THROW_ON_ERROR));
        $this->assertReason(
            $report,
            'method:Fixtures\CommerceApp\Services\OrderService::cancel',
            ImpactCategory::HardDependency,
            ImpactLevel::Breaks,
        );
        $this->assertReason(
            $report,
            'method:Fixtures\CommerceApp\Policies\OrderPolicy::cancel',
            ImpactCategory::HardDependency,
            ImpactLevel::Breaks,
        );

        $addedId = 'method:Fixtures\CommerceApp\Services\UnreferencedHelper::format';
        self::assertCount(1, array_filter(
            $report->changedSymbols,
            static fn ($change): bool => $change->changeType === ChangeType::Added
                && $change->newNodeId?->value === $addedId,
        ));

        foreach ($report->affectedSymbols as $affected) {
            foreach ($affected->reasons as $reason) {
                self::assertNotSame($addedId, $reason->nodeChain[0], $affected->nodeId->value);
            }
        }

        $this->assertEvidenceIntegrity($report);
        $this->assertJsonGolden($report, 'impact/rename-and-delete.json');
    }

    private function impact(string $patch): array
    {
        $repository = new TemporaryGitRepository($this->fixtureRoot());
        $this->repositories[] = $repository;
        $repository->applyPatch(dirname(__DIR__).'/Fixtures/Diffs/'.$patch);
        [$graph, $diagnostics, $files, $symbols, $outputs] = $this->buildHeadGraph($repository->root());
        $runner = new NativeGitCommandRunner();
        $diff = (new GitDiffChangeProvider($repository->root(), $runner, 10_000))->changes(
            $repository->baseCommit(),
            $repository->headCommit(),
        );
        $mapped = (new ChangedSymbolMapper($symbols))->map(
            $diff['files'],
            $diff['base_commit'],
            $diff['head_commit'],
        );
        $changes = array_values(array_filter(
            $mapped['symbols'],
            static fn ($change): bool => $change->changeType !== ChangeType::Renamed,
        ));
        $diagnostics = [...$diagnostics, ...$diff['diagnostics'], ...$mapped['diagnostics']];
        $baseResolver = new BaseRefSymbolResolver(
            new GitObjectReader($repository->root(), $runner, 10_000),
            new PhpFileParser(),
            $symbols,
            $diagnostics,
        );

        foreach ($diff['files'] as $file) {
            $oldSideFiles = [];

            if (in_array($file->changeType, [ChangeType::Deleted, ChangeType::Renamed], true)) {
                $oldSideFiles[] = $file;
            } else {
                foreach ($file->hunks as $hunk) {
                    if ($hunk->oldCount > 0 && $hunk->newCount === 0) {
                        $oldSideFiles[] = new ChangedFile(
                            ChangeType::Deleted,
                            $file->oldPath,
                            $file->newPath,
                            [$hunk],
                        );
                    }
                }
            }

            foreach ($oldSideFiles as $oldSideFile) {
                $resolved = $baseResolver->resolve(
                    $oldSideFile,
                    $diff['base_commit'],
                    $diff['head_commit'],
                );
                $changes = [...$changes, ...$resolved['symbols']];
                $diagnostics = [...$diagnostics, ...$resolved['diagnostics']];
            }
        }

        $policy = new ImpactPolicy(new EdgeDirectionPolicy());
        $report = (new ImpactAnalyzer(
            $graph,
            $diagnostics,
            $policy,
            new SharedResourceImpactAnalyzer($graph, $policy),
            new TestScopeResolver($graph),
        ))->analyze(new ImpactRequest($changes, 500, 1000, 20, 2_000_000));

        return [$report, $files, $symbols, $outputs];
    }

    private function buildHeadGraph(string $root): array
    {
        $parser = new PhpFileParser([
            new LaravelRegistrationFactCollector(),
            new BranchConditionFactCollector(),
            new EloquentChainFactCollector(),
            new FacadeEffectFactCollector(),
        ]);
        $symbols = new SymbolTable();
        $files = [];

        foreach (['app', 'routes', 'tests'] as $scanRoot) {
            $path = $root.'/'.$scanRoot;

            if (! is_dir($path)) {
                continue;
            }

            $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(
                $path,
                \FilesystemIterator::SKIP_DOTS,
            ));

            foreach ($iterator as $file) {
                if (! $file->isFile() || $file->getExtension() !== 'php') {
                    continue;
                }

                $source = file_get_contents($file->getPathname());
                self::assertIsString($source);
                $relative = str_replace('\\', '/', substr($file->getPathname(), strlen($root) + 1));
                $parsed = $parser->parse($relative, $source);
                $files[] = $parsed;

                foreach ($parsed->symbols as $symbol) {
                    $symbols->add($symbol);
                }
            }
        }

        $graph = new KnowledgeGraph();
        $diagnostics = (new StructuralGraphBuilder($symbols))->build($files, $graph);
        $diagnostics = [
            ...$diagnostics,
            ...(new CallGraphBuilder(new CallTargetResolver($symbols), $symbols))->build($files, $graph),
        ];
        $boot = (new LaravelBootInspector(
            fn () => $this->app,
            [
                new RouteBootCollector(),
                new ContainerBootCollector(),
                new EventBootCollector(),
                new PolicyBootCollector(),
                new ScheduleBootCollector(),
                new CommandBootCollector(),
            ],
        ))->inspect($symbols, $files);
        $semantic = (new LaravelSemanticAnalyzer())->analyze($files, $symbols, $boot->facts, $graph);
        $diagnostics = [...$diagnostics, ...$boot->diagnostics, ...$semantic['diagnostics']];
        (new BuildProcessMembershipPhase(200, 20))->execute(
            new PipelineContext($graph),
            ['extract_laravel_semantics' => new PhaseResult(
                'extract_laravel_semantics',
                $semantic['outputs'],
                $diagnostics,
            )],
        );

        return [$graph, $diagnostics, $files, $symbols, $semantic['outputs']];
    }

    private function assertReason(
        ImpactReport $report,
        string $nodeId,
        ImpactCategory $category,
        ?ImpactLevel $level = null,
    ): void {
        foreach ($report->affectedSymbols as $symbol) {
            if ($symbol->nodeId->value !== $nodeId) {
                continue;
            }

            foreach ($symbol->reasons as $reason) {
                if ($reason->category === $category && ($level === null || $reason->level === $level)) {
                    self::assertNotEmpty($reason->evidenceIds);

                    return;
                }
            }
        }

        self::fail("Missing {$category->value} reason for {$nodeId}.");
    }

    private function assertJsonGolden(ImpactReport $report, string $relative): void
    {
        $json = json_encode(
            (new ImpactJsonProjector())->project($report),
            JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
        )."\n";
        $this->assertTextGolden($json, $relative);
    }

    private function assertEvidenceIntegrity(ImpactReport $report): void
    {
        $available = array_fill_keys(array_map(
            static fn ($record): string => $record->id(),
            $report->evidence,
        ), true);

        foreach ($report->affectedSymbols as $symbol) {
            foreach ($symbol->reasons as $reason) {
                foreach ($reason->evidenceIds as $evidenceId) {
                    self::assertArrayHasKey($evidenceId, $available, $symbol->nodeId->value);
                }
            }
        }

        foreach ($report->selectedTests as $test) {
            foreach ($test['evidence_ids'] as $evidenceId) {
                self::assertArrayHasKey($evidenceId, $available, $test['test_node_id']);
            }
        }
    }

    private function assertTextGolden(string $actual, string $relative): void
    {
        $path = dirname(__DIR__).'/Golden/'.$relative;

        self::assertFileExists($path);
        self::assertSame(file_get_contents($path), $actual);
    }
}
