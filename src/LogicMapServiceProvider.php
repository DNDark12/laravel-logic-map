<?php

namespace dndark\LogicMap;

use dndark\LogicMap\Analysis\ArchitectureAnalyzer;
use dndark\LogicMap\Analysis\AstParser;
use dndark\LogicMap\Analysis\MetricsCalculator;
use dndark\LogicMap\Analysis\RiskCalculator;
use dndark\LogicMap\Contracts\GraphExtractor;
use dndark\LogicMap\Contracts\GraphProjector;
use dndark\LogicMap\Contracts\GraphRepository;
use dndark\LogicMap\Projectors\MetaProjector;
use dndark\LogicMap\Projectors\OverviewProjector;
use dndark\LogicMap\Projectors\SearchProjector;
use dndark\LogicMap\Projectors\SubgraphProjector;
use dndark\LogicMap\Repositories\CacheGraphRepository;
use dndark\LogicMap\Services\BuildLogicMapService;
use dndark\LogicMap\Services\QueryLogicMapService;
use dndark\LogicMap\Support\FileDiscovery;
use dndark\LogicMap\Support\Fingerprint;
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

        // Analysis services (Sprint 4)
        $this->app->singleton(MetricsCalculator::class);
        $this->app->singleton(ArchitectureAnalyzer::class);
        $this->app->singleton(RiskCalculator::class);

        // Application services
        $this->app->singleton(BuildLogicMapService::class);
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
                __DIR__ . '/../resources/views' => resource_path('views/vendor/logic-map'),
            ], 'logic-map-views');

            $this->publishes([
                __DIR__ . '/../resources/dist' => public_path('vendor/logic-map'),
            ], 'logic-map-assets');

            $this->commands([
                Commands\BuildLogicMapCommand::class,
                Commands\ClearLogicMapCacheCommand::class,
                Commands\AnalyzeLogicMapCommand::class,
            ]);
        }

        $this->loadRoutesFrom(__DIR__ . '/../routes/web.php');
        $this->loadViewsFrom(__DIR__ . '/../resources/views', 'logic-map');

        // Share resource paths with views
        $packagePath = __DIR__ . '/..';
        View::composer('logic-map::*', function ($view) use ($packagePath) {
            $view->with('logicMapCss', file_get_contents($packagePath . '/resources/dist/logic-map.css'));
            $view->with('logicMapJs', file_get_contents($packagePath . '/resources/dist/logic-map.js'));
        });
    }
}
