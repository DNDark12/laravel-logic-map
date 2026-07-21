<?php

namespace DNDark\LogicMap\Tests\Feature;

use DNDark\LogicMap\Analysis\Php\PhpFileParser;
use DNDark\LogicMap\Analysis\Pipeline\AnalysisPhase;
use DNDark\LogicMap\Analysis\Pipeline\PhaseResult;
use DNDark\LogicMap\Analysis\Pipeline\PipelineContext;
use DNDark\LogicMap\Analysis\Pipeline\Phases\BuildProcessMembershipPhase;
use DNDark\LogicMap\Analysis\Pipeline\Phases\ParsePhpPhase;
use DNDark\LogicMap\Analysis\Pipeline\Phases\ResolvePhpPhase;
use DNDark\LogicMap\Analysis\Pipeline\PipelineRunner;
use DNDark\LogicMap\Domain\Graph\EdgeType;
use DNDark\LogicMap\Domain\Graph\GraphNode;
use DNDark\LogicMap\Domain\Graph\NodeId;
use DNDark\LogicMap\Domain\Graph\NodeKind;
use DNDark\LogicMap\Domain\Snapshot\DiagnosticCode;
use DNDark\LogicMap\Projectors\CanonicalGraphProjector;
use DNDark\LogicMap\Repositories\Database\DatabaseGraphRepository;
use DNDark\LogicMap\Support\SchemaVersion;
use DNDark\LogicMap\Services\Indexing\IndexLogicMapService;
use DNDark\LogicMap\Services\Indexing\IndexOptions;
use DNDark\LogicMap\Support\AnalysisVersion;
use DNDark\LogicMap\Support\RepositoryFileDiscovery;
use DNDark\LogicMap\Support\SourceFingerprint;
use DNDark\LogicMap\Tests\TestCase;
use Illuminate\Support\Facades\File;
use RuntimeException;

final class V2GraphCorrectnessGateTest extends TestCase
{
    private const VOLATILE_KEYS = [
        'indexed_at', 'phase_metrics', 'metrics', 'duration', 'duration_ns',
        'duration_ms', 'elapsed', 'elapsed_ns', 'elapsed_ms', 'memory',
        'memory_bytes', 'peak_memory', 'peak_memory_bytes', 'timing', 'timings',
    ];

    private string $fixtureRoot;

    protected function setUp(): void
    {
        parent::setUp();
        $this->fixtureRoot = sys_get_temp_dir().'/logic-map-gate-a-'.bin2hex(random_bytes(6));
        File::makeDirectory($this->fixtureRoot.'/app', 0755, true);
        file_put_contents($this->fixtureRoot.'/app/Core.php', $this->fixtureSource());
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->fixtureRoot);
        parent::tearDown();
    }

    public function test_gate_a_locks_determinism_integrity_multiedges_diagnostics_and_atomicity(): void
    {
        [$firstService, $firstRepository] = $this->indexer(null);
        [$secondService] = $this->indexer('gate_second');
        $options = new IndexOptions(['app'], [], true);
        $first = $firstService->index($options)->snapshot;
        $second = $secondService->index($options)->snapshot;
        $projector = new CanonicalGraphProjector();
        $firstProjection = $projector->project($first);
        $firstJson = $projector->json($first);
        $secondJson = $projector->json($second);

        self::assertSame($firstJson, $secondJson);
        self::assertSame([
            'schema_version',
            'analysis_version',
            'snapshot_id',
            'fingerprint',
            'nodes',
            'edges',
            'evidence',
            'diagnostics',
            'process_steps',
        ], array_keys($firstProjection));
        self::assertNotEmpty($firstProjection['process_steps']);
        self::assertSame([], $this->findVolatileKeys($firstProjection));

        $nodeIds = array_fill_keys(array_column($firstProjection['nodes'], 'id'), true);

        foreach ($firstProjection['nodes'] as $node) {
            self::assertStringNotContainsString($this->fixtureRoot, $node['id']);
            self::assertDoesNotMatchRegularExpression('#^[^:]+:(?:/|[A-Za-z]:[\\\\/])#', $node['id']);
        }

        foreach ($firstProjection['edges'] as $edge) {
            self::assertNotEmpty($edge['evidence']);
            self::assertArrayHasKey($edge['source'], $nodeIds);
            self::assertArrayHasKey($edge['target'], $nodeIds);
        }

        self::assertCount(2, array_filter(
            $first->graph->edges(),
            static fn ($edge): bool => $edge->type === EdgeType::Calls
                && $edge->source->value === 'method:App\OrderService::cancel'
                && $edge->target->value === 'method:App\Gateway::save',
        ));
        self::assertNotEmpty(array_filter(
            $first->diagnostics,
            static fn ($diagnostic): bool => $diagnostic->code === DiagnosticCode::UnresolvedReceiver,
        ));

        $golden = file_get_contents(dirname(__DIR__).'/Golden/core-graph.json');
        self::assertIsString($golden);
        self::assertSame(rtrim($golden, "\n"), $firstJson);

        $activeId = $firstRepository->active()?->id;
        file_put_contents($this->fixtureRoot.'/app/Core.php', '<?php final class Broken { public function x( }');

        try {
            $firstService->index($options);
            self::fail('A parse failure must abort before activation.');
        } catch (RuntimeException $exception) {
            self::assertStringContainsString('parse failed', $exception->getMessage());
        }

        self::assertSame($activeId, $firstRepository->active()?->id);
    }

    /**
     * @param null|string $connection null = default (already migrated) connection;
     *        a name creates and migrates a second isolated in-memory store.
     * @return array{IndexLogicMapService, DatabaseGraphRepository}
     */
    private function indexer(?string $connection): array
    {
        if ($connection !== null) {
            config()->set('database.connections.'.$connection, [
                'driver' => 'sqlite',
                'database' => ':memory:',
                'prefix' => '',
                'foreign_key_constraints' => true,
            ]);
            $previous = config('logic-map.storage.connection');
            config()->set('logic-map.storage.connection', $connection);
            $migration = require dirname(__DIR__, 2).'/database/migrations/2026_01_01_000001_create_logic_map_tables.php';
            $migration->up();
            config()->set('logic-map.storage.connection', $previous);
        }

        $repository = new DatabaseGraphRepository($this->app->make('db')->connection($connection));
        $parser = new PhpFileParser();
        $pipeline = new PipelineRunner([
            new ParsePhpPhase($parser),
            new ResolvePhpPhase(),
            new class implements AnalysisPhase
            {
                public function name(): string
                {
                    return 'extract_laravel_semantics';
                }

                public function dependencies(): array
                {
                    return ['resolve_php'];
                }

                public function execute(PipelineContext $context, array $dependencies): PhaseResult
                {
                    $command = NodeId::named(NodeKind::Command, 'gate:rebuild');
                    $context->graph->addNode(new GraphNode(
                        $command,
                        NodeKind::Command,
                        'gate:rebuild',
                        null,
                        null,
                    ));

                    return new PhaseResult($this->name(), []);
                }
            },
            new BuildProcessMembershipPhase(20, 5),
        ]);

        return [
            new IndexLogicMapService(
                $repository,
                new RepositoryFileDiscovery($this->fixtureRoot),
                new SourceFingerprint(AnalysisVersion::CURRENT, SchemaVersion::VERSION),
                $pipeline,
            ),
            $repository,
        ];
    }

    /** @return list<string> */
    private function findVolatileKeys(array $value): array
    {
        $found = [];

        foreach ($value as $key => $item) {
            if (is_string($key) && in_array(strtolower($key), self::VOLATILE_KEYS, true)) {
                $found[] = strtolower($key);
            }

            if (is_array($item)) {
                $found = [...$found, ...$this->findVolatileKeys($item)];
            }
        }

        $found = array_values(array_unique($found));
        sort($found, SORT_STRING);

        return $found;
    }

    private function fixtureSource(): string
    {
        return <<<'PHP'
<?php
namespace App;

final class Gateway
{
    public function save(object $order): void {}
}

final class OrderService
{
    public function __construct(private Gateway $gateway) {}

    public function cancel(object $order, $dynamic): void
    {
        $this->gateway->save($order);
        $this->gateway->save($order);
        $dynamic->missing();
    }
}
PHP;
    }
}
