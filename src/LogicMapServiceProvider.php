<?php

namespace DNDark\LogicMap;

use DNDark\LogicMap\Analysis\Laravel\Boot\CommandBootCollector;
use DNDark\LogicMap\Analysis\Laravel\Boot\ContainerBootCollector;
use DNDark\LogicMap\Analysis\Laravel\Boot\EventBootCollector;
use DNDark\LogicMap\Analysis\Laravel\Boot\LaravelBootInspector;
use DNDark\LogicMap\Analysis\Laravel\Boot\PolicyBootCollector;
use DNDark\LogicMap\Analysis\Laravel\Boot\RouteBootCollector;
use DNDark\LogicMap\Analysis\Laravel\Boot\ScheduleBootCollector;
use DNDark\LogicMap\Analysis\Laravel\Facts\BranchConditionFactCollector;
use DNDark\LogicMap\Analysis\Laravel\Facts\EloquentChainFactCollector;
use DNDark\LogicMap\Analysis\Laravel\Facts\FacadeEffectFactCollector;
use DNDark\LogicMap\Analysis\Laravel\Facts\LaravelRegistrationFactCollector;
use DNDark\LogicMap\Analysis\Laravel\LaravelSemanticAnalyzer;
use DNDark\LogicMap\Analysis\Php\PhpFileParser;
use DNDark\LogicMap\Analysis\Pipeline\Phases\BuildProcessMembershipPhase;
use DNDark\LogicMap\Analysis\Pipeline\Phases\CollectLaravelBootFactsPhase;
use DNDark\LogicMap\Analysis\Pipeline\Phases\ExtractLaravelSemanticsPhase;
use DNDark\LogicMap\Analysis\Pipeline\Phases\ParsePhpPhase;
use DNDark\LogicMap\Analysis\Pipeline\Phases\ResolvePhpPhase;
use DNDark\LogicMap\Analysis\Pipeline\PipelineRunner;
use DNDark\LogicMap\Analysis\Runtime\LaravelRuntimeSubscriber;
use DNDark\LogicMap\Analysis\Runtime\QueueTracePayload;
use DNDark\LogicMap\Analysis\Runtime\RuntimeSanitizer;
use DNDark\LogicMap\Analysis\Runtime\RuntimeTraceContext;
use DNDark\LogicMap\Analysis\Runtime\SqlTableObservationParser;
use DNDark\LogicMap\Contracts\RuntimeEvidenceRepository;
use DNDark\LogicMap\Contracts\SemanticGraphRepository;
use DNDark\LogicMap\Http\Middleware\LogicMapRuntimeTrace;
use DNDark\LogicMap\Repositories\Sqlite\SqliteConnectionFactory;
use DNDark\LogicMap\Repositories\Sqlite\SqliteGraphRepository;
use DNDark\LogicMap\Repositories\Sqlite\SqliteRuntimeEvidenceRepository;
use DNDark\LogicMap\Repositories\Sqlite\SqliteSchema;
use DNDark\LogicMap\Services\Impact\ImpactQueryService;
use DNDark\LogicMap\Services\Indexing\ClearLogicMapService;
use DNDark\LogicMap\Services\Indexing\IndexLogicMapService;
use DNDark\LogicMap\Services\Query\LogicMapStatusService;
use DNDark\LogicMap\Services\Query\ModuleQueryService;
use DNDark\LogicMap\Services\Query\ResponseLimiter;
use DNDark\LogicMap\Services\Query\RuntimeEvidenceMerger;
use DNDark\LogicMap\Services\Query\SymbolContextService;
use DNDark\LogicMap\Services\Query\SymbolSearchService;
use DNDark\LogicMap\Services\Workflow\WorkflowQueryService;
use DNDark\LogicMap\Support\AnalysisVersion;
use DNDark\LogicMap\Support\NodeIdCodec;
use DNDark\LogicMap\Support\RelativePath;
use DNDark\LogicMap\Support\RepositoryFileDiscovery;
use DNDark\LogicMap\Support\SourceFingerprint;
use Illuminate\Queue\Queue;
use Illuminate\Support\ServiceProvider;
use InvalidArgumentException;
use Throwable;

final class LogicMapServiceProvider extends ServiceProvider
{
    public const ASSET_VERSION = '2.0.0';

    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/logic-map.php', 'logic-map');

        $this->app->singleton(SqliteConnectionFactory::class, fn (): SqliteConnectionFactory =>
            new SqliteConnectionFactory($this->resolveSqlitePath()));
        $this->app->singleton(SemanticGraphRepository::class, fn ($app): SemanticGraphRepository =>
            new SqliteGraphRepository($app->make(SqliteConnectionFactory::class)));
        $this->app->singleton(RuntimeSanitizer::class, fn (): RuntimeSanitizer =>
            new RuntimeSanitizer((int) config('logic-map.evidence.expression_max_length', 500)));
        $this->app->singleton(RuntimeEvidenceRepository::class, fn ($app): RuntimeEvidenceRepository =>
            new SqliteRuntimeEvidenceRepository(
                $app->make(SqliteConnectionFactory::class),
                $app->make(RuntimeSanitizer::class),
                (int) config('logic-map.runtime.retention_days', 7),
                (int) config('logic-map.runtime.max_sessions', 1000),
                (int) config('logic-map.runtime.max_observations_per_session', 5000),
            ));

        $this->app->singleton(RepositoryFileDiscovery::class, fn (): RepositoryFileDiscovery =>
            new RepositoryFileDiscovery(base_path()));
        $this->app->singleton(SourceFingerprint::class, fn (): SourceFingerprint =>
            new SourceFingerprint(AnalysisVersion::CURRENT, SqliteSchema::VERSION));
        $this->app->singleton(PhpFileParser::class, fn (): PhpFileParser => new PhpFileParser(
            [
                new LaravelRegistrationFactCollector(),
                new BranchConditionFactCollector(),
                new EloquentChainFactCollector(),
                new FacadeEffectFactCollector(),
            ],
            null,
            (int) config('logic-map.evidence.expression_max_length', 500),
        ));
        $this->app->singleton(LaravelBootInspector::class, fn ($app): LaravelBootInspector =>
            new LaravelBootInspector(
                fn () => $app,
                [
                    new RouteBootCollector(),
                    new ContainerBootCollector(),
                    new EventBootCollector(),
                    new PolicyBootCollector(),
                    new ScheduleBootCollector(),
                    new CommandBootCollector(),
                ],
            ));
        $this->app->singleton(LaravelSemanticAnalyzer::class);
        $this->app->singleton(ParsePhpPhase::class);
        $this->app->singleton(ResolvePhpPhase::class);
        $this->app->singleton(CollectLaravelBootFactsPhase::class);
        $this->app->singleton(ExtractLaravelSemanticsPhase::class);
        $this->app->singleton(BuildProcessMembershipPhase::class, fn (): BuildProcessMembershipPhase =>
            new BuildProcessMembershipPhase(
                (int) config('logic-map.query.max_nodes', 500),
                (int) config('logic-map.query.max_depth', 12),
            ));
        $this->app->singleton(PipelineRunner::class, fn ($app): PipelineRunner => new PipelineRunner([
            $app->make(ParsePhpPhase::class),
            $app->make(ResolvePhpPhase::class),
            $app->make(CollectLaravelBootFactsPhase::class),
            $app->make(ExtractLaravelSemanticsPhase::class),
            $app->make(BuildProcessMembershipPhase::class),
        ]));

        $this->app->singleton(IndexLogicMapService::class);
        $this->app->singleton(ClearLogicMapService::class);
        $this->app->singleton(NodeIdCodec::class);
        $this->app->singleton(LogicMapStatusService::class);
        $this->app->singleton(RuntimeEvidenceMerger::class);
        $this->app->singleton(ResponseLimiter::class, fn (): ResponseLimiter =>
            new ResponseLimiter((int) config('logic-map.query.max_response_bytes', 2_000_000)));
        $this->app->singleton(SymbolSearchService::class, fn ($app): SymbolSearchService =>
            new SymbolSearchService(
                $app->make(NodeIdCodec::class),
                (int) config('logic-map.query.max_search_results', 50),
            ));
        $this->app->singleton(SymbolContextService::class, fn ($app): SymbolContextService =>
            new SymbolContextService(
                $app->make(NodeIdCodec::class),
                (int) config('logic-map.query.max_edges', 1000),
                $app->make(RuntimeEvidenceMerger::class),
            ));
        $this->app->singleton(ModuleQueryService::class, fn ($app): ModuleQueryService =>
            new ModuleQueryService(
                $app->make(NodeIdCodec::class),
                (int) config('logic-map.query.max_nodes', 500),
                (int) config('logic-map.query.max_edges', 1000),
            ));
        $this->app->singleton(ImpactQueryService::class, fn ($app): ImpactQueryService =>
            new ImpactQueryService(
                base_path(),
                $app->make(PhpFileParser::class),
                (int) config('logic-map.query.max_nodes', 500),
                (int) config('logic-map.query.max_edges', 1000),
                (int) config('logic-map.query.max_depth', 12),
                (int) config('logic-map.query.max_response_bytes', 2_000_000),
            ));
        $this->app->singleton(WorkflowQueryService::class, fn (): WorkflowQueryService =>
            new WorkflowQueryService(
                (int) config('logic-map.query.max_nodes', 500),
                (int) config('logic-map.query.max_depth', 12),
            ));
    }

    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $assets = realpath(__DIR__.'/../resources/dist/v2') ?: __DIR__.'/../resources/dist/v2';

            $this->publishes([
                __DIR__.'/../config/logic-map.php' => config_path('logic-map.php'),
            ], 'logic-map-config');
            $this->publishes([
                __DIR__.'/../resources/views' => resource_path('views/vendor/logic-map'),
            ], 'logic-map-views');
            $this->publishes([
                $assets => public_path('vendor/logic-map/v2'),
            ], 'logic-map-assets');
            $this->publishes([
                $assets => public_path('vendor/logic-map/v2'),
            ], 'laravel-assets');
            $this->commands([
                Commands\IndexLogicMapCommand::class,
                Commands\StatusLogicMapCommand::class,
                Commands\WorkflowLogicMapCommand::class,
                Commands\ImpactLogicMapCommand::class,
                Commands\ClearLogicMapCommand::class,
            ]);
        }

        $this->loadRoutesFrom(__DIR__.'/../routes/web.php');
        $this->loadViewsFrom(__DIR__.'/../resources/views', 'logic-map');

        if ((bool) config('logic-map.runtime.enabled', false)) {
            $this->bootRuntimeEvidence();
        }
    }

    private function bootRuntimeEvidence(): void
    {
        $marker = 'logic-map.runtime-hooks-registered';

        if ($this->app->bound($marker)) {
            return;
        }

        $this->app->instance($marker, true);
        $this->app->singleton(RuntimeTraceContext::class);
        $this->app->singleton(SqlTableObservationParser::class);
        $this->app->singleton(QueueTracePayload::class);
        $repository = $this->app->make(RuntimeEvidenceRepository::class);
        $router = $this->app['router'];
        $groups = $router->getMiddlewareGroups();

        foreach ((array) config('logic-map.runtime.middleware_groups', ['web', 'api']) as $group) {
            if (! is_string($group) || ! array_key_exists($group, $groups)) {
                $repository->diagnose(
                    'runtime_middleware_group_missing',
                    'Configured runtime middleware group ['.(is_scalar($group) ? (string) $group : 'invalid').'] does not exist.',
                );
                continue;
            }

            if (! in_array(LogicMapRuntimeTrace::class, $groups[$group], true)) {
                $router->pushMiddlewareToGroup($group, LogicMapRuntimeTrace::class);
                $groups[$group][] = LogicMapRuntimeTrace::class;
            }
        }

        try {
            $applicationNamespace = method_exists($this->app, 'getNamespace')
                ? $this->app->getNamespace()
                : 'App\\';
        } catch (Throwable) {
            $applicationNamespace = 'App\\';
        }

        $this->app->singleton(LaravelRuntimeSubscriber::class, fn ($app): LaravelRuntimeSubscriber =>
            new LaravelRuntimeSubscriber(
                $app->make(RuntimeEvidenceRepository::class),
                $app->make(RuntimeTraceContext::class),
                $app->make(SqlTableObservationParser::class),
                $app->make(QueueTracePayload::class),
                (bool) config('logic-map.runtime.collect_cache_events', false),
                $applicationNamespace,
            ));
        $subscriber = $this->app->make(LaravelRuntimeSubscriber::class);
        $events = $this->app['events'];

        $events->listen(\Illuminate\Database\Events\QueryExecuted::class, [$subscriber, 'onQuery']);
        $events->listen(\Illuminate\Queue\Events\JobProcessing::class, [$subscriber, 'onJobProcessing']);
        $events->listen(\Illuminate\Queue\Events\JobProcessed::class, [$subscriber, 'onJobProcessed']);
        $events->listen(\Illuminate\Queue\Events\JobFailed::class, [$subscriber, 'onJobFailed']);
        $events->listen(\Illuminate\Http\Client\Events\RequestSending::class, [$subscriber, 'onRequestSending']);
        $events->listen(\Illuminate\Http\Client\Events\ResponseReceived::class, [$subscriber, 'onResponseReceived']);
        $events->listen(\Illuminate\Http\Client\Events\ConnectionFailed::class, [$subscriber, 'onConnectionFailed']);
        $events->listen(rtrim($applicationNamespace, '\\').'\\*', [$subscriber, 'onApplicationEvent']);

        if ((bool) config('logic-map.runtime.collect_cache_events', false)) {
            foreach ([
                \Illuminate\Cache\Events\CacheHit::class,
                \Illuminate\Cache\Events\CacheMissed::class,
                \Illuminate\Cache\Events\KeyWritten::class,
                \Illuminate\Cache\Events\KeyForgotten::class,
            ] as $event) {
                $events->listen($event, [$subscriber, 'onCache']);
            }
        }

        $payload = $this->app->make(QueueTracePayload::class);
        $context = $this->app->make(RuntimeTraceContext::class);
        Queue::createPayloadUsing(static fn (): array => $payload->create($context));
    }

    private function resolveSqlitePath(): string
    {
        $configured = config('logic-map.storage.sqlite_path');

        if (! is_string($configured)) {
            throw new InvalidArgumentException('SQLite storage path must be a repository-relative string.');
        }

        try {
            $relative = RelativePath::normalize($configured);
        } catch (InvalidArgumentException $exception) {
            throw new InvalidArgumentException(
                'SQLite storage path must be repository-relative and remain under storage_path().',
                0,
                $exception,
            );
        }

        $storageRoot = realpath(storage_path());

        if ($storageRoot === false) {
            throw new InvalidArgumentException('SQLite storage root does not exist.');
        }

        $storageRoot = rtrim(str_replace('\\', '/', $storageRoot), '/');
        $candidate = str_replace('\\', '/', base_path($relative));

        if (! str_starts_with($candidate, $storageRoot.'/')) {
            throw new InvalidArgumentException('SQLite storage path must remain under storage_path().');
        }

        $directory = dirname($candidate);

        if (! is_dir($directory)) {
            $parent = realpath(dirname($directory));
            $parent = $parent === false ? false : str_replace('\\', '/', $parent);

            if ($parent === false || ! str_starts_with($parent, $storageRoot)) {
                throw new InvalidArgumentException('SQLite parent directory must already exist under storage_path().');
            }

            if (! mkdir($directory, 0755) && ! is_dir($directory)) {
                throw new InvalidArgumentException('SQLite storage directory could not be created.');
            }
        }

        $realDirectory = realpath($directory);
        $realDirectory = $realDirectory === false ? false : str_replace('\\', '/', $realDirectory);

        if ($realDirectory === false || ! str_starts_with($realDirectory, $storageRoot.'/')) {
            throw new InvalidArgumentException('SQLite storage directory resolves outside storage_path().');
        }

        if (file_exists($candidate)) {
            $realFile = realpath($candidate);
            $realFile = $realFile === false ? false : str_replace('\\', '/', $realFile);

            if ($realFile === false || ! str_starts_with($realFile, $storageRoot.'/')) {
                throw new InvalidArgumentException('SQLite file resolves outside storage_path().');
            }

            return $realFile;
        }

        return $realDirectory.'/'.basename($candidate);
    }
}
