<?php

namespace DNDark\LogicMap\Tests\Feature;

use DNDark\LogicMap\Contracts\SemanticGraphRepository;
use DNDark\LogicMap\Repositories\Database\DatabaseGraphRepository;
use DNDark\LogicMap\Services\Indexing\IndexLogicMapService;
use DNDark\LogicMap\Services\Indexing\IndexOptions;
use DNDark\LogicMap\Support\NodeIdCodec;
use DNDark\LogicMap\Support\RepositoryFileDiscovery;
use DNDark\LogicMap\Tests\Support\CommerceFixtureTestCase;
use Illuminate\Support\Facades\File;

final class V2ApiEndpointTest extends CommerceFixtureTestCase
{
    private string $temporaryRoot;

    private DatabaseGraphRepository $repository;

    protected function setUp(): void
    {
        parent::setUp();
        $this->temporaryRoot = sys_get_temp_dir().'/logic-map-v2-api-'.bin2hex(random_bytes(6));
        File::makeDirectory($this->temporaryRoot, 0755, true);
        $this->repository = new DatabaseGraphRepository($this->app->make('db')->connection());
        $this->app->instance(SemanticGraphRepository::class, $this->repository);
        $this->app->instance(RepositoryFileDiscovery::class, new RepositoryFileDiscovery($this->fixtureRoot()));
        config()->set('logic-map.scan_paths', ['app', 'routes', 'tests']);
        config()->set('logic-map.excludes', []);
        config()->set('logic-map.http.enabled', true);
        config()->set('logic-map.http.allowed_environments', ['testing']);
        config()->set('logic-map.http.middleware', ['web']);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->temporaryRoot);
        parent::tearDown();
    }

    public function test_status_and_missing_index_use_the_canonical_envelope(): void
    {
        $this->getJson('/logic-map/api/status')
            ->assertOk()
            ->assertJsonStructure($this->envelope())
            ->assertJsonPath('ok', true)
            ->assertJsonPath('data.active', false);

        $this->getJson('/logic-map/api/symbols/search?q=cancel')
            ->assertStatus(409)
            ->assertJsonStructure($this->envelope())
            ->assertJsonPath('ok', false)
            ->assertJsonPath('errors.code', 'index_missing');
    }

    public function test_search_context_workflow_and_modules_emit_canonical_and_encoded_ids(): void
    {
        $this->index();
        $codec = new NodeIdCodec();
        $controllerId = 'class:Fixtures\\CommerceApp\\Http\\Controllers\\OrderController';

        $this->getJson('/logic-map/api/symbols/search?q='.urlencode('Fixtures\\CommerceApp\\Http\\Controllers\\OrderController'))
            ->assertOk()
            ->assertJsonPath('data.results.0.id', $controllerId)
            ->assertJsonPath('data.results.0.encoded_id', $codec->encode($controllerId))
            ->assertJsonPath('data.results.0.kind', 'controller');

        $fileId = 'file:app/Services/OrderService.php';
        $this->getJson('/logic-map/api/symbols/'.$codec->encode($fileId).'/context')
            ->assertOk()
            ->assertJsonPath('data.symbol.id', $fileId)
            ->assertJsonPath('data.symbol.encoded_id', $codec->encode($fileId))
            ->assertJsonPath('data.runtime.coverage', 'No runtime data available')
            ->assertJsonStructure(['data' => ['incoming', 'outgoing', 'processes', 'modules', 'effects', 'evidence', 'runtime']]);

        $routeId = 'route:POST:orders/{order}/cancel';
        $this->getJson('/logic-map/api/workflows/'.$codec->encode($routeId))
            ->assertOk()
            ->assertJsonPath('data.entrypoint.node_id', $routeId)
            ->assertJsonPath('data.entrypoint.encoded_id', $codec->encode($routeId))
            ->assertJsonPath('data.runtime.coverage', 'No runtime data available');

        $moduleWorkflow = $this->getJson('/logic-map/api/workflows/'.$codec->encode('module:Orders'))
            ->assertOk()
            ->assertJsonPath('data.identity.workflow_type', 'module')
            ->assertJsonPath('data.module.node_id', 'module:Orders')
            ->assertJsonPath('data.module.encoded_id', $codec->encode('module:Orders'))
            ->assertJsonPath('data.summary.entrypoint_count', fn ($value): bool => is_int($value) && $value > 0)
            ->assertJsonPath('data.entry_workflows.0.entrypoint.node_id', fn ($value): bool => is_string($value) && $value !== '');
        self::assertGreaterThan(0, count($moduleWorkflow->json('data.entry_workflows')));

        $this->getJson('/logic-map/api/modules')
            ->assertOk()
            ->assertJsonFragment(['id' => 'module:Orders', 'encoded_id' => $codec->encode('module:Orders')]);

        $this->getJson('/logic-map/api/modules/'.$codec->encode('module:Orders'))
            ->assertOk()
            ->assertJsonPath('data.module.id', 'module:Orders')
            ->assertJsonPath('data.module.encoded_id', $codec->encode('module:Orders'));

        $symbol = 'method:Fixtures\\CommerceApp\\Services\\OrderService::cancel';
        $this->postJson('/logic-map/api/impact', ['symbol' => $symbol])
            ->assertOk()
            ->assertJsonPath('data.change_set.count', 1)
            ->assertJsonPath('data.changed_symbols.0.new_node_id', $symbol)
            ->assertJsonPath('data.changed_symbols.0.encoded_new_node_id', $codec->encode($symbol))
            ->assertJsonPath('data.affected_symbols.0.encoded_id', fn ($value): bool => is_string($value) && $value !== '')
            ->assertJsonPath('data.runtime.coverage', 'No runtime data available')
            ->assertJsonPath('data.evidence.0.id', fn ($value): bool => is_string($value) && $value !== '');

        $moduleImpact = $this->postJson('/logic-map/api/impact', ['symbol' => 'module:Orders'])
            ->assertOk()
            ->assertJsonPath('data.change_set.count', fn ($value): bool => is_int($value) && $value > 1)
            ->assertJsonPath('data.selection.type', 'module')
            ->assertJsonPath('data.selection.node_id', 'module:Orders');
        self::assertNotContains(
            'module:Orders',
            array_column($moduleImpact->json('data.changed_symbols'), 'new_node_id'),
        );
    }

    public function test_lookup_validation_ambiguity_truncation_and_environment_guards_are_explicit(): void
    {
        $this->index();
        $codec = new NodeIdCodec();

        $this->getJson('/logic-map/api/symbols/search')
            ->assertStatus(422)
            ->assertJsonPath('errors.code', 'validation_failed');

        $this->getJson('/logic-map/api/symbols/'.$codec->encode('class:Missing').'/context')
            ->assertNotFound()
            ->assertJsonPath('errors.code', 'symbol_not_found');

        $this->getJson('/logic-map/api/symbols/abc=/context')
            ->assertNotFound()
            ->assertJsonPath('errors.code', 'route_not_found');

        $this->getJson('/logic-map/api/symbols/A/context')
            ->assertStatus(422)
            ->assertJsonPath('errors.code', 'invalid_encoded_id');

        $ambiguous = $this->getJson('/logic-map/api/symbols/search?q=cancel')
            ->assertOk()
            ->assertJsonPath('data.selection', null)
            ->json('data.results');
        self::assertGreaterThan(1, count($ambiguous));

        $this->postJson('/logic-map/api/impact', ['base' => '--bad', 'head' => 'HEAD'])
            ->assertStatus(422)
            ->assertJsonPath('errors.code', 'validation_failed');

        config()->set('logic-map.http.allowed_environments', ['production']);
        $this->getJson('/logic-map/api/status')
            ->assertForbidden()
            ->assertJsonPath('errors.code', 'environment_not_allowed');
    }

    public function test_search_respects_the_shared_query_limit_and_reports_truncation(): void
    {
        config()->set('logic-map.query.max_search_results', 1);
        $this->index();

        $this->getJson('/logic-map/api/symbols/search?q=order')
            ->assertOk()
            ->assertJsonCount(1, 'data.results')
            ->assertJsonPath('meta.truncated', true);
    }

    public function test_context_respects_the_shared_response_byte_limit(): void
    {
        config()->set('logic-map.query.max_response_bytes', 2500);
        $this->index();
        $id = (new NodeIdCodec())->encode('method:Fixtures\\CommerceApp\\Services\\OrderService::cancel');
        $response = $this->getJson('/logic-map/api/symbols/'.$id.'/context')
            ->assertOk()
            ->assertJsonPath('meta.truncated', true)
            ->assertJsonPath('meta.truncation_reason', 'max_response_bytes');

        self::assertLessThanOrEqual(2500, strlen($response->getContent()));
    }

    private function index(): void
    {
        $this->app->make(IndexLogicMapService::class)->index(new IndexOptions(
            ['app', 'routes', 'tests'],
            [],
            true,
        ));
    }

    private function envelope(): array
    {
        return ['ok', 'data', 'message', 'errors', 'meta'];
    }
}
