<?php

namespace DNDark\LogicMap\Tests\Feature;

use DNDark\LogicMap\Contracts\SemanticGraphRepository;
use DNDark\LogicMap\Repositories\Sqlite\SqliteConnectionFactory;
use DNDark\LogicMap\Repositories\Sqlite\SqliteGraphRepository;
use DNDark\LogicMap\Services\Indexing\IndexLogicMapService;
use DNDark\LogicMap\Services\Indexing\IndexOptions;
use DNDark\LogicMap\Support\NodeIdCodec;
use DNDark\LogicMap\Support\RepositoryFileDiscovery;
use DNDark\LogicMap\Tests\Support\CommerceFixtureTestCase;
use Illuminate\Support\Facades\File;

final class V2ViewerContractTest extends CommerceFixtureTestCase
{
    private string $temporaryRoot;

    protected function setUp(): void
    {
        parent::setUp();
        $this->temporaryRoot = sys_get_temp_dir().'/logic-map-v2-view-contract-'.bin2hex(random_bytes(6));
        File::makeDirectory($this->temporaryRoot, 0755, true);
        $this->app->instance(SemanticGraphRepository::class, new SqliteGraphRepository(
            new SqliteConnectionFactory($this->temporaryRoot.'/index.sqlite'),
        ));
        $this->app->instance(RepositoryFileDiscovery::class, new RepositoryFileDiscovery($this->fixtureRoot()));
        config()->set('logic-map.http.enabled', true);
        config()->set('logic-map.http.allowed_environments', ['testing']);
        config()->set('logic-map.http.middleware', ['web']);
        $this->app->make(IndexLogicMapService::class)->index(new IndexOptions(
            ['app', 'routes', 'tests'],
            [],
            true,
        ));
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->temporaryRoot);
        parent::tearDown();
    }

    public function test_workflow_and_impact_exports_are_projected_on_the_server(): void
    {
        $codec = new NodeIdCodec();
        $route = $codec->encode('route:POST:orders/{order}/cancel');

        $this->getJson('/logic-map/api/workflows/'.$route.'?format=markdown')
            ->assertOk()
            ->assertJsonPath('data.format', 'markdown')
            ->assertJsonPath('data.content', fn ($value): bool => is_string($value)
                && str_contains($value, '# Workflow')
                && str_contains($value, '## Transactions'));

        $this->getJson('/logic-map/api/workflows/'.$route.'?format=mermaid')
            ->assertOk()
            ->assertJsonPath('data.format', 'mermaid')
            ->assertJsonPath('data.content', fn ($value): bool => is_string($value)
                && str_starts_with($value, "flowchart TD\n")
                && str_contains($value, '-.'));

        $symbol = 'method:Fixtures\\CommerceApp\\Services\\OrderService::cancel';
        $this->postJson('/logic-map/api/impact', ['symbol' => $symbol, 'format' => 'markdown'])
            ->assertOk()
            ->assertJsonPath('data.format', 'markdown')
            ->assertJsonPath('data.content', fn ($value): bool => is_string($value)
                && str_contains($value, '# Change impact')
                && str_contains($value, '## Shared resources')
                && str_contains($value, '## Selected tests'));
    }

    public function test_mapper_assets_lock_the_workflow_and_impact_visual_contract(): void
    {
        $root = dirname(__DIR__, 2).'/resources/dist/v2/js';
        $workflow = file_get_contents($root.'/workflow-view.js');
        $impact = file_get_contents($root.'/impact-view.js');
        $app = file_get_contents($root.'/app.js');

        self::assertIsString($workflow);
        self::assertIsString($impact);
        self::assertIsString($app);

        foreach (['diamond', 'dashed', 'barrel', 'module-lane', 'cycle', 'gap', 'transactionIds'] as $token) {
            self::assertStringContainsString($token, $workflow);
        }

        foreach (['changed', 'affected', 'reason-path', 'resource_node_id', 'category', 'frontier', 'evidenceIds'] as $token) {
            self::assertStringContainsString($token, $impact);
        }

        foreach (['workflowExport', 'impactExport', 'impactFilters', 'downloadText', 'copyText'] as $token) {
            self::assertStringContainsString($token, $app);
        }

        self::assertStringNotContainsString('cdn.jsdelivr.net', $workflow.$impact.$app);
        self::assertStringNotContainsString('cdnjs.cloudflare.com', $workflow.$impact.$app);
    }
}
