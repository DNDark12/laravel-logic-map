<?php

namespace DNDark\LogicMap\Tests\Feature;

use DNDark\LogicMap\LogicMapServiceProvider;
use DNDark\LogicMap\Tests\TestCase;
use Illuminate\Support\ServiceProvider;

final class V2ViewerShellTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config()->set('logic-map.http.enabled', true);
        config()->set('logic-map.http.allowed_environments', ['testing']);
        config()->set('logic-map.http.middleware', ['web']);
    }

    public function test_transitional_viewer_is_a_data_free_module_shell(): void
    {
        $response = $this->get('/logic-map')->assertOk();
        $html = $response->getContent();

        foreach ([
            'logic-map-app',
            'logic-map-search',
            'logic-map-status',
            'logic-map-graph',
            'logic-map-detail',
            'logic-map-evidence',
            'logic-map-mode-symbol',
            'logic-map-mode-workflow',
            'logic-map-mode-impact',
        ] as $id) {
            self::assertStringContainsString('id="'.$id.'"', $html);
        }

        self::assertMatchesRegularExpression(
            '#<script\s+type="module"\s+src="[^"]*/vendor/logic-map/v2/js/app\.js\?v=[^"]+"\s*></script>#',
            $html,
        );
        self::assertStringContainsString('/vendor/logic-map/v2/css/logic-map.css?v=', $html);
        self::assertStringNotContainsString('cdn.jsdelivr.net', $html);
        self::assertStringNotContainsString('cdnjs.cloudflare.com', $html);
        self::assertStringNotContainsString('window.logicMapGraph', $html);
        self::assertStringNotContainsString('application/json', $html);
    }

    public function test_v2_assets_publish_with_package_and_laravel_asset_tags(): void
    {
        $source = realpath(__DIR__.'/../../resources/dist/v2');
        self::assertIsString($source);

        foreach (['logic-map-assets', 'laravel-assets'] as $tag) {
            $paths = ServiceProvider::pathsToPublish(LogicMapServiceProvider::class, $tag);

            self::assertArrayHasKey($source, $paths);
            self::assertSame(public_path('vendor/logic-map/v2'), $paths[$source]);
        }
    }

    public function test_viewer_uses_the_same_environment_guard_as_the_api(): void
    {
        config()->set('logic-map.http.allowed_environments', ['production']);

        $this->get('/logic-map')->assertForbidden();
    }
}
