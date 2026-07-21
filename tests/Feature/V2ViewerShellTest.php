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
            'logic-map-interaction-view',
            'logic-map-interaction-arrange',
        ] as $id) {
            self::assertStringContainsString('id="'.$id.'"', $html);
        }

        self::assertStringContainsString('aria-label="Node interaction mode"', $html);
        self::assertStringContainsString('aria-pressed="true"', $html);
        self::assertStringContainsString('class="lm-command-bar"', $html);
        self::assertStringContainsString('/vendor/logic-map/images/logo.png', $html);
        self::assertStringContainsString('class="lm-search-help"', $html);
        self::assertStringContainsString('<details class="lm-module-browser"', $html);

        self::assertMatchesRegularExpression(
            '#<script\s+type="module"\s+src="[^"]*/vendor/logic-map/js/app\.js\?v=[^"]+"\s*></script>#',
            $html,
        );
        self::assertStringContainsString('/vendor/logic-map/css/logic-map.css?v=', $html);
        self::assertStringNotContainsString('cdn.jsdelivr.net', $html);
        self::assertStringNotContainsString('cdnjs.cloudflare.com', $html);
        self::assertStringNotContainsString('window.logicMapGraph', $html);
        self::assertStringNotContainsString('application/json', $html);
    }

    public function test_v2_assets_publish_with_package_and_laravel_asset_tags(): void
    {
        $source = realpath(__DIR__.'/../../resources/dist');
        $logo = realpath(__DIR__.'/../../art/logo.png');
        self::assertIsString($source);
        self::assertIsString($logo);

        foreach (['logic-map-assets', 'laravel-assets'] as $tag) {
            $paths = ServiceProvider::pathsToPublish(LogicMapServiceProvider::class, $tag);

            self::assertArrayHasKey($source, $paths);
            self::assertSame(public_path('vendor/logic-map'), $paths[$source]);
            self::assertArrayHasKey($logo, $paths);
            self::assertSame(public_path('vendor/logic-map/images/logo.png'), $paths[$logo]);
        }
    }

    public function test_viewer_uses_the_same_environment_guard_as_the_api(): void
    {
        config()->set('logic-map.http.allowed_environments', ['production']);

        $this->get('/logic-map')->assertForbidden();
    }
}
