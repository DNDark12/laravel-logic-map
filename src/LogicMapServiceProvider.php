<?php

namespace dndark\LogicMap;

use dndark\LogicMap\Analysis\ArchitectureAnalyzer;
use dndark\LogicMap\Analysis\AstParser;
use dndark\LogicMap\Analysis\MetricsCalculator;
use dndark\LogicMap\Analysis\RiskCalculator;
use dndark\LogicMap\Analysis\Runtime\CoverageMetadataCollector;
use dndark\LogicMap\Contracts\GraphExtractor;
use dndark\LogicMap\Contracts\GraphProjector;
use dndark\LogicMap\Contracts\GraphRepository;
use dndark\LogicMap\Projectors\MetaProjector;
use dndark\LogicMap\Projectors\OverviewProjector;
use dndark\LogicMap\Projectors\GraphDiffProjector;
use dndark\LogicMap\Projectors\SearchProjector;
use dndark\LogicMap\Projectors\SubgraphProjector;
use dndark\LogicMap\Repositories\CacheGraphRepository;
use dndark\LogicMap\Services\AnalysisReadService;
use dndark\LogicMap\Services\BuildLogicMapService;
use dndark\LogicMap\Services\ExportReadService;
use dndark\LogicMap\Services\GraphReadService;
use dndark\LogicMap\Services\HealthPayloadBuilder;
use dndark\LogicMap\Services\HotspotsBuilder;
use dndark\LogicMap\Services\ImpactReadService;
use dndark\LogicMap\Services\Impact\ImpactProjector;
use dndark\LogicMap\Services\QueryLogicMapService;
use dndark\LogicMap\Services\SnapshotResolver;
use dndark\LogicMap\Services\Trace\TraceProjector;
use dndark\LogicMap\Services\TraceReadService;
use dndark\LogicMap\Support\FileDiscovery;
use dndark\LogicMap\Support\Fingerprint;
use dndark\LogicMap\Support\Traversal\GraphWalker;
use Illuminate\Support\Facades\View;
use Illuminate\Support\ServiceProvider;

class LogicMapServiceProvider extends ServiceProvider
{
    /**
     * Register services.
     */
    public function register(): void
    {
        $this->mergeConfigFrom(
            __DIR__ . '/../config/logic-map.php', 'logic-map'
        );

        // Core contracts
        $this->app->singleton(GraphRepository::class, CacheGraphRepository::class);
        $this->app->singleton(GraphExtractor::class, AstParser::class);
        $this->app->singleton(GraphProjector::class, OverviewProjector::class);

        // Support services
        $this->app->singleton(FileDiscovery::class);
        $this->app->singleton(Fingerprint::class);

        // Projectors
        $this->app->singleton(OverviewProjector::class);
        $this->app->singleton(SubgraphProjector::class);
        $this->app->singleton(SearchProjector::class);
        $this->app->singleton(MetaProjector::class);
        $this->app->singleton(GraphDiffProjector::class);

        // Analysis services (Sprint 4)
        $this->app->singleton(MetricsCalculator::class);
        $this->app->singleton(ArchitectureAnalyzer::class);
        $this->app->singleton(RiskCalculator::class);
        $this->app->singleton(CoverageMetadataCollector::class);

        // Application services
        $this->app->singleton(BuildLogicMapService::class);
        $this->app->singleton(SnapshotResolver::class);
        $this->app->singleton(GraphWalker::class);
        $this->app->singleton(GraphReadService::class);
        $this->app->singleton(HealthPayloadBuilder::class);
        $this->app->singleton(HotspotsBuilder::class);
        $this->app->singleton(AnalysisReadService::class);
        $this->app->singleton(ExportReadService::class);
        // Change Intelligence (v1.3)
        $this->app->singleton(ImpactProjector::class);
        $this->app->singleton(ImpactReadService::class);
        $this->app->singleton(TraceProjector::class);
        $this->app->singleton(TraceReadService::class);
        $this->app->singleton(QueryLogicMapService::class);
    }

    /**
     * Bootstrap services.
     */
    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__ . '/../config/logic-map.php' => config_path('logic-map.php'),
            ], 'logic-map-config');

            $this->publishes([
                __DIR__ . '/../resources/views' => resource_path('views/logic-map'),
            ], 'logic-map-views');

            $this->publishes([
                __DIR__ . '/../resources/views' => resource_path('views/logic-map'),
                __DIR__ . '/../resources/dist' => resource_path('views/logic-map'),
            ], 'logic-map-full');

            $this->commands([
                Commands\BuildLogicMapCommand::class,
                Commands\ClearLogicMapCacheCommand::class,
                Commands\AnalyzeLogicMapCommand::class,
                Commands\ExportDocsCommand::class,
                Commands\ExportNoteCommand::class,
            ]);
        }

        $this->loadRoutesFrom(__DIR__ . '/../routes/web.php');
        $this->loadViewsFrom(__DIR__ . '/../resources/views', 'logic-map');

        $this->autoPublishAssets();

        // Share resource paths with views (Allow local override in resources/views/logic-map)
        View::composer('logic-map::*', function ($view) {
            $customCss = resource_path('views/logic-map/logic-map.css');
            $customJs  = resource_path('views/logic-map/logic-map.js');

            $jsPath  = file_exists($customJs)  ? $customJs  : __DIR__ . '/../resources/dist/js/core.js';

            $reportCssPath = __DIR__ . '/../resources/dist/css/report-page.css';
            $reportJsPath  = __DIR__ . '/../resources/dist/js/report-page.js';

            // Resolve CSS (Inline User Custom OR Explicitly Structured Core CSS)
            $cssContent = '';
            if (file_exists($customCss)) {
                $cssContent = file_get_contents($customCss);
            } else {
                $coreCssFiles = [
                    'variables.css',
                    'base.css',
                    'topbar.css',
                    'panels.css',
                    'dropdowns-overlays.css',
                    'health-panel.css',
                    'subgraph-mobile.css',
                ];
                foreach ($coreCssFiles as $file) {
                    $path = __DIR__ . '/../resources/dist/css/' . $file;
                    if (file_exists($path)) {
                        $cssContent .= file_get_contents($path) . "\n";
                    }
                }
            }
            $view->with('logicMapCss', $cssContent);
            
            // Support legacy inline custom JS. Otherwise expose module URL.
            if (file_exists($customJs)) {
                $view->with('logicMapJsInline', file_get_contents($customJs));
                $view->with('logicMapJsUrl', null);
            } else {
                $view->with('logicMapJsInline', null);
                // We use dynamic chunk loading base
                $view->with('logicMapJsBase', asset('vendor/logic-map/js'));
                // Appended by timestamp query var if we wanted cache busting, but autoPublishAssets relies on directory replacement
                $view->with('logicMapJsUrl', asset('vendor/logic-map/js/core.js'));
            }

            $view->with('reportPageCss', file_exists($reportCssPath) ? file_get_contents($reportCssPath) : '');
            $view->with('reportPageJs',  file_exists($reportJsPath)  ? file_get_contents($reportJsPath)  : '');
        });
    }

    private function autoPublishAssets(): void
    {
        static $publishChecked = false;
        if ($publishChecked) {
            return;
        }
        $publishChecked = true;

        $versionStamp = '1.3.1'; // Update when structure changes
        $publicDir = public_path('vendor/logic-map/js');
        $marker = $publicDir . '/.manifest-' . $versionStamp;

        if (!file_exists($marker)) {
            $distJsDir = __DIR__ . '/../resources/dist/js';
            if (is_dir($distJsDir)) {
                $this->copyDirectory($distJsDir, $publicDir);
                file_put_contents($marker, time());
            }
        }
    }

    private function copyDirectory(string $src, string $dst): void
    {
        if (!is_dir($dst)) {
            @mkdir($dst, 0777, true);
        }
        $dir = opendir($src);
        if ($dir) {
            while (false !== ($file = readdir($dir))) {
                if (($file != '.') && ($file != '..')) {
                    if (is_dir($src . '/' . $file)) {
                        $this->copyDirectory($src . '/' . $file, $dst . '/' . $file);
                    } else {
                        @copy($src . '/' . $file, $dst . '/' . $file);
                    }
                }
            }
            closedir($dir);
        }
    }
}
