<?php

namespace DNDark\LogicMap\Tests\Feature;

use DNDark\LogicMap\Contracts\SemanticGraphRepository;
use DNDark\LogicMap\Domain\Graph\NodeId;
use DNDark\LogicMap\Repositories\Database\DatabaseGraphRepository;
use DNDark\LogicMap\Services\Indexing\IndexLogicMapService;
use DNDark\LogicMap\Services\Indexing\IndexOptions;
use DNDark\LogicMap\Support\RepositoryFileDiscovery;
use DNDark\LogicMap\Tests\Support\CommerceFixtureTestCase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;

final class ExportAiDocsCommandTest extends CommerceFixtureTestCase
{
    private string $relativeOutput;

    private string $absoluteOutput;

    protected function setUp(): void
    {
        parent::setUp();
        $this->relativeOutput = 'storage/framework/testing/logic-map-ai-'.bin2hex(random_bytes(6));
        $this->absoluteOutput = base_path($this->relativeOutput);
        $this->app->instance(
            SemanticGraphRepository::class,
            new DatabaseGraphRepository($this->app->make('db')->connection()),
        );
        $this->app->instance(RepositoryFileDiscovery::class, new RepositoryFileDiscovery($this->fixtureRoot()));
        config()->set('logic-map.scan_paths', ['app', 'routes', 'tests']);
        config()->set('logic-map.excludes', []);
        config()->set('logic-map.export.allow_absolute_paths', false);

        $this->app->make(IndexLogicMapService::class)->index(new IndexOptions(
            ['app', 'routes', 'tests'],
            [],
            true,
        ));
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->absoluteOutput);
        parent::tearDown();
    }

    public function test_export_ai_writes_the_full_bundle_with_weighted_impact_and_modules(): void
    {
        self::assertArrayHasKey('logic-map:export-ai', Artisan::all());
        self::assertSame(0, Artisan::call('logic-map:export-ai', [
            '--output' => $this->relativeOutput,
        ]));

        self::assertFileExists($this->absoluteOutput.'/graph.json');
        self::assertFileExists($this->absoluteOutput.'/llms.txt');
        self::assertFileExists($this->absoluteOutput.'/index.md');
        self::assertFileExists($this->absoluteOutput.'/modules/orders.json');

        $graph = json_decode(File::get($this->absoluteOutput.'/graph.json'), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame(
            ['schema_version', 'analysis_version', 'snapshot_id', 'fingerprint', 'nodes', 'edges', 'modules'],
            array_keys($graph),
        );
        self::assertNotEmpty($graph['nodes']);
        self::assertNotEmpty($graph['modules']);

        $module = json_decode(File::get($this->absoluteOutput.'/modules/orders.json'), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame('Orders', $module['name']);
        self::assertNotEmpty($module['members']);

        $impactFiles = File::glob($this->absoluteOutput.'/impact/*.json');
        self::assertNotEmpty($impactFiles, 'automatic enumeration must export at least one impact bundle');

        $sawWeightedEntry = false;

        foreach ($impactFiles as $impactFile) {
            $impact = json_decode(File::get($impactFile), true, 512, JSON_THROW_ON_ERROR);
            self::assertSame(['target', 'snapshot_id', 'truncation', 'affected'], array_keys($impact));

            foreach ($impact['affected'] as $affected) {
                self::assertArrayHasKey('band', $affected);
                self::assertArrayHasKey('score', $affected);
                self::assertArrayHasKey('reason_chain', $affected);
                self::assertArrayHasKey('suggested_tests', $affected);
                $sawWeightedEntry = true;
            }
        }

        self::assertTrue($sawWeightedEntry, 'at least one impact file must contain a weighted affected symbol');

        $llms = File::get($this->absoluteOutput.'/llms.txt');
        self::assertStringContainsString('score = clamp01', $llms);
        self::assertStringContainsString('graph.json', $llms);

        $index = File::get($this->absoluteOutput.'/index.md');
        self::assertStringContainsString('snapshot_id: "'.$graph['snapshot_id'].'"', $index);
    }

    public function test_export_ai_is_byte_stable_across_two_runs_of_the_same_snapshot(): void
    {
        self::assertSame(0, Artisan::call('logic-map:export-ai', ['--output' => $this->relativeOutput]));
        $firstGraph = File::get($this->absoluteOutput.'/graph.json');
        $firstLlms = File::get($this->absoluteOutput.'/llms.txt');
        $firstIndex = File::get($this->absoluteOutput.'/index.md');
        $firstImpactFiles = File::glob($this->absoluteOutput.'/impact/*.json');
        sort($firstImpactFiles);
        $firstImpact = array_map(static fn (string $file): string => File::get($file), $firstImpactFiles);

        self::assertSame(0, Artisan::call('logic-map:export-ai', [
            '--output' => $this->relativeOutput,
            '--force' => true,
        ]));
        $secondGraph = File::get($this->absoluteOutput.'/graph.json');
        $secondLlms = File::get($this->absoluteOutput.'/llms.txt');
        $secondIndex = File::get($this->absoluteOutput.'/index.md');
        $secondImpactFiles = File::glob($this->absoluteOutput.'/impact/*.json');
        sort($secondImpactFiles);
        $secondImpact = array_map(static fn (string $file): string => File::get($file), $secondImpactFiles);

        self::assertSame($firstGraph, $secondGraph);
        self::assertSame($firstLlms, $secondLlms);
        self::assertSame($firstIndex, $secondIndex);
        self::assertSame($firstImpactFiles, $secondImpactFiles);
        self::assertSame($firstImpact, $secondImpact);
    }

    public function test_export_ai_requires_force_and_rejects_paths_outside_the_repository(): void
    {
        self::assertSame(0, Artisan::call('logic-map:export-ai', [
            '--output' => $this->relativeOutput,
        ]));
        self::assertSame(1, Artisan::call('logic-map:export-ai', [
            '--output' => $this->relativeOutput,
        ]));
        self::assertSame(0, Artisan::call('logic-map:export-ai', [
            '--output' => $this->relativeOutput,
            '--force' => true,
        ]));
        self::assertSame(1, Artisan::call('logic-map:export-ai', [
            '--output' => '../logic-map-ai-escape',
        ]));
    }

    public function test_export_ai_respects_the_symbols_option(): void
    {
        $target = NodeId::method('Fixtures\\CommerceApp\\Http\\Controllers\\OrderController', 'cancel')->value;

        self::assertSame(0, Artisan::call('logic-map:export-ai', [
            '--output' => $this->relativeOutput,
            '--symbols' => [$target],
        ]));

        $impactFiles = File::glob($this->absoluteOutput.'/impact/*.json');
        self::assertCount(1, $impactFiles, 'the --symbols allowlist must override automatic enumeration');

        $impact = json_decode(File::get($impactFiles[0]), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame($target, $impact['target']);

        $index = File::get($this->absoluteOutput.'/index.md');
        self::assertStringContainsString('| impact symbols omitted | 0 |', $index);
    }
}
