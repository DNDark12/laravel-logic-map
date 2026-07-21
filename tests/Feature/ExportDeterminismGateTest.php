<?php

namespace DNDark\LogicMap\Tests\Feature;

use DNDark\LogicMap\Analysis\Pipeline\PipelineRunner;
use DNDark\LogicMap\Contracts\SemanticGraphRepository;
use DNDark\LogicMap\Repositories\Database\DatabaseGraphRepository;
use DNDark\LogicMap\Services\Indexing\IndexLogicMapService;
use DNDark\LogicMap\Services\Indexing\IndexOptions;
use DNDark\LogicMap\Support\RepositoryFileDiscovery;
use DNDark\LogicMap\Support\SourceFingerprint;
use DNDark\LogicMap\Tests\Support\CommerceFixtureTestCase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;

/**
 * Mirrors V2GraphCorrectnessGateTest's determinism guarantee, but for the AI
 * documentation bundle: indexing the same fixture source twice — through two
 * independent storage connections, so nothing but wall-clock time differs
 * between the runs — must still export a byte-identical bundle. This is the
 * gate for Sprint 2 Task 8: graph.json, every impact/*.json, every
 * modules/*.json, llms.txt, and index.md must never leak run-specific state
 * (timestamps, connection names, row IDs) into the exported bytes.
 */
final class ExportDeterminismGateTest extends CommerceFixtureTestCase
{
    private string $relativeOutput;

    private string $absoluteOutput;

    protected function setUp(): void
    {
        parent::setUp();
        $this->relativeOutput = 'storage/framework/testing/logic-map-ai-gate-'.bin2hex(random_bytes(6));
        $this->absoluteOutput = base_path($this->relativeOutput);
        $this->app->instance(RepositoryFileDiscovery::class, new RepositoryFileDiscovery($this->fixtureRoot()));
        config()->set('logic-map.scan_paths', ['app', 'routes', 'tests']);
        config()->set('logic-map.excludes', []);
        config()->set('logic-map.export.allow_absolute_paths', false);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->absoluteOutput);
        parent::tearDown();
    }

    public function test_ai_bundle_is_byte_identical_across_two_independent_index_runs_of_the_same_source(): void
    {
        $firstRepository = new DatabaseGraphRepository($this->app->make('db')->connection());
        $this->indexWith($firstRepository);
        $this->app->instance(SemanticGraphRepository::class, $firstRepository);
        self::assertSame(0, Artisan::call('logic-map:export-ai', ['--output' => $this->relativeOutput]));
        $firstBundle = $this->readBundle();
        self::assertNotEmpty($firstBundle);

        config()->set('database.connections.ai_gate_second', [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
            'foreign_key_constraints' => true,
        ]);
        $previousConnection = config('logic-map.storage.connection');
        config()->set('logic-map.storage.connection', 'ai_gate_second');
        $migration = require dirname(__DIR__, 2).'/database/migrations/2026_01_01_000001_create_logic_map_tables.php';
        $migration->up();
        config()->set('logic-map.storage.connection', $previousConnection);

        $secondRepository = new DatabaseGraphRepository($this->app->make('db')->connection('ai_gate_second'));
        $this->indexWith($secondRepository);
        $this->app->instance(SemanticGraphRepository::class, $secondRepository);
        self::assertSame(0, Artisan::call('logic-map:export-ai', [
            '--output' => $this->relativeOutput,
            '--force' => true,
        ]));
        $secondBundle = $this->readBundle();

        self::assertSame(array_keys($firstBundle), array_keys($secondBundle));
        self::assertSame($firstBundle, $secondBundle);
    }

    private function indexWith(SemanticGraphRepository $repository): void
    {
        (new IndexLogicMapService(
            $repository,
            $this->app->make(RepositoryFileDiscovery::class),
            $this->app->make(SourceFingerprint::class),
            $this->app->make(PipelineRunner::class),
        ))->index(new IndexOptions(['app', 'routes', 'tests'], [], true));
    }

    /** @return array<string,string> bundle-relative path => content */
    private function readBundle(): array
    {
        $files = [
            ...File::glob($this->absoluteOutput.'/*.json'),
            ...File::glob($this->absoluteOutput.'/*.md'),
            ...File::glob($this->absoluteOutput.'/*.txt'),
            ...File::glob($this->absoluteOutput.'/impact/*.json'),
            ...File::glob($this->absoluteOutput.'/modules/*.json'),
        ];
        sort($files, SORT_STRING);

        $bundle = [];

        foreach ($files as $file) {
            $bundle[substr($file, strlen($this->absoluteOutput) + 1)] = File::get($file);
        }

        return $bundle;
    }
}
