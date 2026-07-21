<?php

namespace DNDark\LogicMap\Tests\Unit\Analysis\Laravel;

use DNDark\LogicMap\Analysis\Laravel\Detectors\CacheEffectDetector;
use DNDark\LogicMap\Analysis\Laravel\Detectors\ConfigEffectDetector;
use DNDark\LogicMap\Analysis\Laravel\Detectors\HttpClientEffectDetector;
use DNDark\LogicMap\Analysis\Laravel\Detectors\StorageEffectDetector;
use DNDark\LogicMap\Analysis\Laravel\Detectors\ViewEffectDetector;
use DNDark\LogicMap\Analysis\Laravel\Facts\FacadeEffectFactCollector;
use DNDark\LogicMap\Analysis\Php\PhpFileParser;
use DNDark\LogicMap\Domain\Graph\EdgeType;
use DNDark\LogicMap\Domain\Graph\GraphEdge;
use DNDark\LogicMap\Domain\Graph\KnowledgeGraph;
use DNDark\LogicMap\Support\TemplateNormalizer;
use DNDark\LogicMap\Tests\Support\CommerceFixtureTestCase;

final class ExternalEffectDetectorTest extends CommerceFixtureTestCase
{
    public function test_template_normalizer_removes_credentials_query_secrets_and_dynamic_values(): void
    {
        $normalizer = new TemplateNormalizer();

        self::assertSame(
            'https://example.com/orders',
            $normalizer->normalize(['literal' => 'https://user:password@example.com/orders?token=secret']),
        );
        self::assertSame(
            'orders/{id}/audit.json',
            $normalizer->normalize(['concat' => [
                ['literal' => 'orders/'],
                ['placeholder' => 'id'],
                ['literal' => '/audit.json'],
            ]]),
        );
    }

    public function test_facade_effects_are_normalized_and_never_persist_request_bodies_or_tokens(): void
    {
        $source = <<<'PHP'
<?php
namespace App;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;

final class EffectService
{
    public function run(int $id, string $path, string $token): void
    {
        Cache::remember("order:$id", 60, fn () => null);
        Cache::forget("order:$id");
        config('services.erp.base_url');
        Storage::disk('s3')->put($path, 'super-secret-storage-body');
        view('orders.show', ['token' => 'super-secret-view-data']);
        Http::withToken($token)->post(
            config('services.erp.base_url').'/orders/'.$id.'/cancel',
            ['token' => 'super-secret-request-body'],
        );
    }
}
PHP;
        $parsed = (new PhpFileParser([new FacadeEffectFactCollector()]))
            ->parse('app/EffectService.php', $source);
        $facts = $parsed->facts('facade_effect');
        $results = [
            (new CacheEffectDetector())->detect($facts),
            (new ConfigEffectDetector())->detect($facts),
            (new StorageEffectDetector())->detect($facts),
            (new ViewEffectDetector())->detect($facts),
            (new HttpClientEffectDetector())->detect($facts),
        ];
        $effects = array_merge(...array_map(static fn ($result): array => $result->facts, $results));
        $keys = array_map(
            static fn ($effect): string => $effect->effect.'|'.$effect->resource,
            $effects,
        );
        sort($keys, SORT_STRING);

        self::assertSame([
            'calls_external|{config:services.erp.base_url}/orders/{id}/cancel',
            'invalidates_cache|order:{id}',
            'reads_cache|order:{id}',
            'reads_config|services.erp.base_url',
            'reads_config|services.erp.base_url',
            'renders_view|orders.show',
            'writes_cache|order:{id}',
            'writes_storage|s3:{path}',
        ], $keys);
        $serialized = serialize($results);
        self::assertStringNotContainsString('super-secret', $serialized);
        self::assertStringNotContainsString('password', $serialized);
        self::assertStringNotContainsString('token=secret', $serialized);
    }

    public function test_commerce_fixture_effects_are_materialized_as_graph_resources(): void
    {
        [$graph] = $this->buildSemanticGraph();

        self::assertCount(1, $this->edges(
            $graph,
            'method:Fixtures\CommerceApp\Services\OrderService::cancel',
            EdgeType::InvalidatesCache,
            'cache:order-summary:{id}',
        ));
        self::assertCount(1, $this->edges(
            $graph,
            'method:Fixtures\CommerceApp\Services\OrderArtifactService::writeAudit',
            EdgeType::ReadsConfig,
            'config:logic-map.fixture.audit_disk',
        ));
        self::assertCount(1, $this->edges(
            $graph,
            'method:Fixtures\CommerceApp\Services\OrderArtifactService::writeAudit',
            EdgeType::WritesStorage,
            'storage:{disk}:orders/{id}/audit.json',
        ));
        self::assertCount(1, $this->edges(
            $graph,
            'method:Fixtures\CommerceApp\Http\Controllers\OrderController::show',
            EdgeType::RendersView,
            'view:orders.show',
        ));
        self::assertCount(1, $this->edges(
            $graph,
            'method:Fixtures\CommerceApp\Listeners\SendCancellationWebhook::handle',
            EdgeType::CallsExternal,
            'external:{config:services.erp.base_url}/orders/{id}/cancel',
        ));
    }

    private function edges(KnowledgeGraph $graph, string $source, EdgeType $type, string $target): array
    {
        return array_values(array_filter(
            $graph->edges(),
            static fn (GraphEdge $edge): bool => $edge->source->value === $source
                && $edge->type === $type
                && $edge->target->value === $target,
        ));
    }
}
