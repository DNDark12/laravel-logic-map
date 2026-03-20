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

        // Share resource paths with views (Allow local override in resources/views/logic-map)
        View::composer('logic-map::*', function ($view) {
            $customCss = resource_path('views/logic-map/logic-map.css');
            $customJs  = resource_path('views/logic-map/logic-map.js');

            $cssPath = file_exists($customCss) ? $customCss : __DIR__ . '/../resources/dist/css/logic-map.css';
            $jsPath  = file_exists($customJs)  ? $customJs  : __DIR__ . '/../resources/dist/js/logic-map.js';

            $reportCssPath = __DIR__ . '/../resources/dist/css/report-page.css';
            $reportJsPath  = __DIR__ . '/../resources/dist/js/report-page.js';

            $view->with('logicMapCss',  file_get_contents($cssPath));
            $view->with('logicMapJs',   file_get_contents($jsPath));
            $view->with('reportPageCss', file_exists($reportCssPath) ? file_get_contents($reportCssPath) : '');
            $view->with('reportPageJs',  file_exists($reportJsPath)  ? file_get_contents($reportJsPath)  : '');
        });
    }
}
