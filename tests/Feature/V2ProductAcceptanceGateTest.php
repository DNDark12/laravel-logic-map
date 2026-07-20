<?php

namespace DNDark\LogicMap\Tests\Feature;

use DNDark\LogicMap\Analysis\Php\PhpFileParser;
use DNDark\LogicMap\Contracts\SemanticGraphRepository;
use DNDark\LogicMap\Repositories\Database\DatabaseGraphRepository;
use DNDark\LogicMap\Services\Impact\ImpactQueryService;
use DNDark\LogicMap\Services\Indexing\IndexLogicMapService;
use DNDark\LogicMap\Support\RepositoryFileDiscovery;
use DNDark\LogicMap\Tests\Support\CommerceFixtureTestCase;
use DNDark\LogicMap\Tests\Support\TemporaryGitRepository;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;

final class V2ProductAcceptanceGateTest extends CommerceFixtureTestCase
{
    /** @var list<string> */
    private array $temporaryDirectories = [];

    /** @var list<TemporaryGitRepository> */
    private array $gitRepositories = [];

    protected function tearDown(): void
    {
        foreach ($this->gitRepositories as $repository) {
            $repository->remove();
        }

        foreach ($this->temporaryDirectories as $directory) {
            File::deleteDirectory($directory);
        }

        parent::tearDown();
    }

    public function test_acceptance_workflow_command_exposes_the_full_order_cancellation_flow(): void
    {
        $this->bindIndexFor($this->fixtureRoot());

        self::assertSame(0, Artisan::call('logic-map:index', ['--force' => true]));
        self::assertSame(0, Artisan::call('logic-map:workflow', [
            'symbol' => 'route:POST:orders/{order}/cancel',
            '--format' => 'json',
        ]));

        $payload = $this->decodeCommandOutput();
        self::assertSame('route:POST:orders/{order}/cancel', $payload['entrypoint']['node_id']);
        self::assertSame(1, $payload['summary']['branch_count']);
        self::assertSame(1, $payload['summary']['transaction_count']);
        self::assertGreaterThanOrEqual(1, $payload['summary']['async_boundary_count']);
        self::assertGreaterThanOrEqual(3, $payload['summary']['module_count']);

        $nodeIds = array_values(array_filter(array_column($payload['steps'], 'node_id')));
        self::assertContains('column:orders.status', $nodeIds);
        self::assertContains('column:inventory_stocks.quantity', $nodeIds);
        self::assertContains(
            'external:{config:services.erp.base_url}/orders/{id}/cancel',
            $nodeIds,
        );
        self::assertContains('class:Fixtures\CommerceApp\Listeners\RestockInventory', $nodeIds);
        self::assertContains('class:Fixtures\CommerceApp\Listeners\SendCancellationWebhook', $nodeIds);
    }

    public function test_acceptance_impact_command_exposes_the_cross_module_git_blast_radius(): void
    {
        $git = new TemporaryGitRepository($this->fixtureRoot());
        $this->gitRepositories[] = $git;
        $git->applyPatch(dirname(__DIR__).'/Fixtures/Diffs/order-cancel-change.diff');
        $this->bindIndexFor($git->root());
        $this->app->instance(ImpactQueryService::class, new ImpactQueryService(
            $git->root(),
            $this->app->make(PhpFileParser::class),
            500,
            1000,
            20,
            2_000_000,
        ));

        self::assertSame(0, Artisan::call('logic-map:index', ['--force' => true]));
        self::assertSame(0, Artisan::call('logic-map:impact', [
            '--base' => $git->baseCommit(),
            '--head' => $git->headCommit(),
            '--format' => 'json',
        ]));

        $payload = $this->decodeCommandOutput();
        self::assertSame(1, $payload['summary']['changed_symbol_count']);
        self::assertGreaterThanOrEqual(5, $payload['summary']['affected_module_count']);
        self::assertGreaterThan(0, $payload['summary']['affected_symbol_count']);

        $affected = [];
        foreach ($payload['affected_symbols'] as $symbol) {
            $affected[$symbol['node_id']] = $symbol['reasons'];
        }

        foreach ([
            'module:Dashboard',
            'module:Integration',
            'module:Inventory',
            'module:Orders',
            'module:Shipping',
            'process:route:POST:orders/{order}/cancel',
            'class:Fixtures\CommerceApp\Listeners\RestockInventory',
            'external:{config:services.erp.base_url}/orders/{id}/cancel',
        ] as $nodeId) {
            self::assertArrayHasKey($nodeId, $affected);
            self::assertNotEmpty($affected[$nodeId], $nodeId);
            self::assertNotEmpty($affected[$nodeId][0]['evidence_ids'] ?? [], $nodeId);
        }
    }

    private function bindIndexFor(string $repositoryRoot): void
    {
        $storage = sys_get_temp_dir().'/logic-map-acceptance-'.bin2hex(random_bytes(8));
        File::makeDirectory($storage, 0755, true);
        $this->temporaryDirectories[] = $storage;
        config()->set('logic-map.scan_paths', ['app', 'routes', 'tests']);
        config()->set('logic-map.excludes', []);

        $repository = new DatabaseGraphRepository($this->app->make('db')->connection());
        // Each bind previously pointed at a fresh SQLite file; the shared
        // in-memory connection is cleared to keep the same fresh-store semantics.
        $repository->clear();
        $this->app->instance(SemanticGraphRepository::class, $repository);
        $this->app->instance(
            RepositoryFileDiscovery::class,
            new RepositoryFileDiscovery($repositoryRoot),
        );
        $this->app->forgetInstance(IndexLogicMapService::class);
    }

    private function decodeCommandOutput(): array
    {
        $payload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);
        self::assertIsArray($payload);

        return $payload;
    }
}
