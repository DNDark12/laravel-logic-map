<?php

namespace DNDark\LogicMap\Tests\Feature;

use DNDark\LogicMap\Analysis\Facts\BranchConditionFact;
use DNDark\LogicMap\Analysis\Facts\EarlyReturnFact;
use DNDark\LogicMap\Analysis\Facts\ThrowFact;
use DNDark\LogicMap\Analysis\Facts\TransactionBoundaryFact;
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
use DNDark\LogicMap\Analysis\Php\PhpFileParser;
use DNDark\LogicMap\Analysis\Php\SymbolTable;
use DNDark\LogicMap\Analysis\Pipeline\Phases\CollectLaravelBootFactsPhase;
use DNDark\LogicMap\Analysis\Pipeline\Phases\ExtractLaravelSemanticsPhase;
use DNDark\LogicMap\Analysis\Pipeline\Phases\ParsePhpPhase;
use DNDark\LogicMap\Analysis\Pipeline\Phases\ResolvePhpPhase;
use DNDark\LogicMap\Analysis\Pipeline\PipelineContext;
use DNDark\LogicMap\Analysis\Pipeline\PipelineRunner;
use DNDark\LogicMap\Domain\Graph\Certainty;
use DNDark\LogicMap\Domain\Graph\EvidenceOrigin;
use DNDark\LogicMap\Domain\Graph\EvidenceRecord;
use DNDark\LogicMap\Domain\Graph\GraphEdge;
use DNDark\LogicMap\Domain\Graph\GraphNode;
use DNDark\LogicMap\Domain\Graph\KnowledgeGraph;
use DNDark\LogicMap\Domain\Graph\NodeId;
use DNDark\LogicMap\Support\AnalysisVersion;
use DNDark\LogicMap\Tests\Support\CommerceFixtureTestCase;

final class V2LaravelSemanticAccuracyGateTest extends CommerceFixtureTestCase
{
    private const DETECTOR_NAMES = [
        'route', 'container', 'form-request', 'authorization',
        'event', 'job', 'listener', 'schedule', 'notification', 'mail',
        'eloquent', 'query-builder', 'cache', 'config', 'storage', 'view',
        'http-client', 'symbol-classifier', 'command', 'module', 'test-reference',
    ];

    private const EDGE_DETECTORS = [
        'route_detector', 'container_binding_detector', 'form_request_detector',
        'authorization_detector', 'event_dispatch_detector', 'job_dispatch_detector',
        'listener_detector', 'schedule_detector', 'notification_detector', 'mail_detector',
        'eloquent_effect_detector', 'query_builder_effect_detector', 'cache_effect_detector',
        'config_effect_detector', 'storage_effect_detector', 'view_effect_detector',
        'http_client_effect_detector', 'module-resolver',
    ];

    public function test_gate_b_locks_laravel_semantics_to_the_authoritative_fixture(): void
    {
        self::assertSame('2.0.0', AnalysisVersion::CURRENT);

        $graph = new KnowledgeGraph();
        $pipeline = new PipelineRunner([
            new ParsePhpPhase(new PhpFileParser([
                new LaravelRegistrationFactCollector(),
                new BranchConditionFactCollector(),
                new EloquentChainFactCollector(),
                new FacadeEffectFactCollector(),
            ])),
            new ResolvePhpPhase(),
            new CollectLaravelBootFactsPhase(new LaravelBootInspector(
                fn () => $this->app,
                [
                    new RouteBootCollector(),
                    new ContainerBootCollector(),
                    new EventBootCollector(),
                    new PolicyBootCollector(),
                    new ScheduleBootCollector(),
                    new CommandBootCollector(),
                ],
            )),
            new ExtractLaravelSemanticsPhase(new LaravelSemanticAnalyzer()),
        ]);
        $results = $pipeline->run(new PipelineContext($graph, ['sources' => $this->sources()]));
        $resolved = $results['resolve_php']->value;
        $semantic = $results['extract_laravel_semantics'];
        self::assertIsArray($resolved);
        self::assertInstanceOf(SymbolTable::class, $resolved['symbol_table']);
        self::assertIsArray($semantic->value);

        $this->assertTypedOutputs($semantic->value);
        $this->assertDetectorMetrics($semantic->metrics['detectors'] ?? []);
        $this->assertFixtureScope(
            $resolved['parsed_files'],
            $results['collect_laravel_boot']->value['boot_facts'],
            $resolved['symbol_table'],
            $graph,
        );

        $observations = $this->semanticObservations($graph);

        self::assertSame(
            $this->golden('semantic-edges.json'),
            $observations[Certainty::Certain->value],
        );
        self::assertSame([
            'method:Fixtures\CommerceApp\Listeners\SendCancellationWebhook::handle'
                .'|calls_external|external:{config:services.erp.base_url}/orders/{id}/cancel'
                .'|static_ast|http_client_effect_detector',
        ], $observations[Certainty::Probable->value]);
        self::assertSame([], $observations[Certainty::Possible->value]);

        $kinds = array_values(array_map(
            static fn (GraphNode $node): string => implode('|', [
                $node->id->value,
                $node->kind->value,
                $node->attributes['classification_certainty'],
            ]),
            array_values(array_filter(
                $graph->nodes(),
                static fn (GraphNode $node): bool => preg_match('/^(class|interface|trait|enum):/', $node->id->value) === 1,
            )),
        ));
        sort($kinds, SORT_STRING);
        self::assertSame($this->golden('semantic-kinds.json'), $kinds);

        $diagnosticCodes = array_map(
            static fn ($diagnostic): string => $diagnostic->code->value,
            $semantic->diagnostics,
        );
        sort($diagnosticCodes, SORT_STRING);
        self::assertSame($this->golden('semantic-diagnostics.json'), $diagnosticCodes);
    }

    private function assertTypedOutputs(array $outputs): void
    {
        foreach ([
            'branches' => BranchConditionFact::class,
            'throws' => ThrowFact::class,
            'early_returns' => EarlyReturnFact::class,
            'transactions' => TransactionBoundaryFact::class,
        ] as $key => $type) {
            self::assertArrayHasKey($key, $outputs);
            self::assertNotEmpty($outputs[$key]);

            foreach ($outputs[$key] as $fact) {
                self::assertInstanceOf($type, $fact);
            }
        }

        self::assertArrayHasKey('data_effects', $outputs);
        self::assertArrayHasKey('external_effects', $outputs);
        self::assertArrayHasKey('classifications', $outputs);
        self::assertArrayHasKey('module_assignments', $outputs);
        self::assertArrayHasKey('tests', $outputs);
    }

    private function assertDetectorMetrics(array $metrics): void
    {
        self::assertSame(self::DETECTOR_NAMES, array_keys($metrics));

        foreach ($metrics as $detector => $values) {
            self::assertSame(['facts', 'edges', 'diagnostics', 'duration_ms'], array_keys($values), $detector);
            self::assertGreaterThanOrEqual(0, $values['facts'], $detector);
            self::assertGreaterThanOrEqual(0, $values['edges'], $detector);
            self::assertGreaterThanOrEqual(0, $values['diagnostics'], $detector);
            self::assertGreaterThanOrEqual(0, $values['duration_ms'], $detector);
        }
    }

    private function assertFixtureScope(
        array $files,
        array $bootFacts,
        SymbolTable $symbols,
        KnowledgeGraph $graph,
    ): void {
        foreach ($files as $file) {
            self::assertMatchesRegularExpression('#^(app|routes)/#', $file->relativePath);
            self::assertStringNotContainsString('packages/dndark/laravel-logic-map', $file->relativePath);
        }

        foreach ($bootFacts as $fact) {
            if ($fact->kind === 'route') {
                self::assertCount(1, $symbols->exact($fact->attributes['action_class']));
                self::assertStringStartsNotWith('logic-map.', (string) ($fact->attributes['name'] ?? ''));
                self::assertNotContains($fact->attributes['action_class'], [
                    'DNDark\LogicMap\Http\Controllers\LogicMapController',
                    'DNDark\LogicMap\Http\Controllers\ReportController',
                ]);
            } elseif ($fact->kind === 'container_binding') {
                self::assertCount(1, $symbols->exact($fact->attributes['abstract']));
                self::assertCount(1, $symbols->exact($fact->attributes['concrete']));
            } elseif ($fact->kind === 'event_listener') {
                self::assertCount(1, $symbols->exact($fact->attributes['event']));
                self::assertCount(1, $symbols->exact($fact->attributes['listener']));
            } elseif ($fact->kind === 'policy') {
                self::assertCount(1, $symbols->exact($fact->attributes['model']));
                self::assertCount(1, $symbols->exact($fact->attributes['policy']));
            }
        }

        foreach ($graph->edges() as $edge) {
            foreach ($edge->evidence as $evidence) {
                if ($evidence->origin !== EvidenceOrigin::LaravelBoot) {
                    continue;
                }

                foreach ([$edge->source, $edge->target] as $endpoint) {
                    if (preg_match('/^(class|interface|trait|enum|method):/', $endpoint->value) === 1) {
                        self::assertCount(1, $symbols->byId($endpoint));
                    }
                }
            }
        }
    }

    private function semanticObservations(KnowledgeGraph $graph): array
    {
        $observations = [
            Certainty::Certain->value => [],
            Certainty::Probable->value => [],
            Certainty::Possible->value => [],
        ];

        foreach ($graph->edges() as $edge) {
            foreach ($edge->evidence as $evidence) {
                if (! in_array($evidence->detector, self::EDGE_DETECTORS, true)) {
                    continue;
                }

                self::assertSame(
                    $edge->id,
                    GraphEdge::fromEvidence($edge->source, $edge->target, $edge->type, $evidence)->id,
                );
                $serialized = $evidence->certainty === Certainty::Certain
                    ? $this->serializeCertain($edge, $evidence)
                    : $this->serializeUncertain($edge, $evidence);
                $observations[$evidence->certainty->value][] = $serialized;
            }
        }

        foreach ($observations as &$values) {
            sort($values, SORT_STRING);
        }

        return $observations;
    }

    private function serializeCertain(GraphEdge $edge, EvidenceRecord $evidence): string
    {
        return implode('|', [
            $edge->source->value,
            $edge->type->value,
            $edge->target->value,
            $edge->id,
            $evidence->origin->value,
            $evidence->detector,
            $evidence->attributes['semantic_relation_key'] ?? '',
        ]);
    }

    private function serializeUncertain(GraphEdge $edge, EvidenceRecord $evidence): string
    {
        return implode('|', [
            $edge->source->value,
            $edge->type->value,
            $edge->target->value,
            $evidence->origin->value,
            $evidence->detector,
        ]);
    }

    private function sources(): array
    {
        $sources = [];
        foreach (['app', 'routes'] as $scanRoot) {
            $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(
                $this->fixtureRoot().'/'.$scanRoot,
                \FilesystemIterator::SKIP_DOTS,
            ));

            foreach ($iterator as $file) {
                if (! $file->isFile() || $file->getExtension() !== 'php') {
                    continue;
                }

                $relative = str_replace('\\', '/', substr(
                    $file->getPathname(),
                    strlen($this->fixtureRoot()) + 1,
                ));
                $source = file_get_contents($file->getPathname());
                self::assertIsString($source);
                $sources[$relative] = $source;
            }
        }

        ksort($sources, SORT_STRING);

        return $sources;
    }

    private function golden(string $file): array
    {
        $json = file_get_contents($this->fixtureRoot().'/expected/'.$file);
        self::assertIsString($json);
        $values = json_decode($json, true, flags: JSON_THROW_ON_ERROR);
        sort($values, SORT_STRING);

        return $values;
    }
}
