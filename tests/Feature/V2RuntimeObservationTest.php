<?php

namespace DNDark\LogicMap\Tests\Feature;

use DateTimeImmutable;
use DNDark\LogicMap\Analysis\Runtime\LaravelRuntimeSubscriber;
use DNDark\LogicMap\Analysis\Runtime\QueueTracePayload;
use DNDark\LogicMap\Analysis\Runtime\RuntimeSanitizer;
use DNDark\LogicMap\Analysis\Runtime\RuntimeTraceContext;
use DNDark\LogicMap\Analysis\Runtime\SqlTableObservationParser;
use DNDark\LogicMap\Contracts\RuntimeEvidenceRepository;
use DNDark\LogicMap\Contracts\SemanticGraphRepository;
use DNDark\LogicMap\Domain\Graph\KnowledgeGraph;
use DNDark\LogicMap\Domain\Snapshot\GraphSnapshot;
use DNDark\LogicMap\Domain\Snapshot\RuntimeSession;
use DNDark\LogicMap\Http\Middleware\LogicMapRuntimeTrace;
use DNDark\LogicMap\LogicMapServiceProvider;
use DNDark\LogicMap\Repositories\Sqlite\SqliteConnectionFactory;
use DNDark\LogicMap\Repositories\Sqlite\SqliteGraphRepository;
use DNDark\LogicMap\Repositories\Sqlite\SqliteRuntimeEvidenceRepository;
use DNDark\LogicMap\Repositories\Sqlite\SqliteSchema;
use DNDark\LogicMap\Tests\TestCase;
use GuzzleHttp\Psr7\Request as PsrRequest;
use GuzzleHttp\Psr7\Response as PsrResponse;
use Illuminate\Cache\Events\CacheHit;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Http\Client\Events\RequestSending;
use Illuminate\Http\Client\Events\ResponseReceived;
use Illuminate\Http\Client\Request;
use Illuminate\Http\Client\Response;
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Queue\Events\JobProcessed;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Queue\Queue;
use Illuminate\Support\Facades\Route;
use ReflectionClass;
use RuntimeException;

final class V2RuntimeObservationTest extends TestCase
{
    private string $databasePath;
    private SqliteConnectionFactory $factory;
    private SqliteRuntimeEvidenceRepository $runtime;
    private SqliteGraphRepository $graph;
    private RuntimeTraceContext $context;
    private string $snapshotId;

    protected function setUp(): void
    {
        parent::setUp();
        $path = tempnam(sys_get_temp_dir(), 'logic-map-runtime-feature-');
        self::assertIsString($path);
        $this->databasePath = $path;
        $this->factory = new SqliteConnectionFactory($path);
        $this->graph = new SqliteGraphRepository($this->factory);
        $this->runtime = new SqliteRuntimeEvidenceRepository($this->factory, new RuntimeSanitizer());
        $this->context = new RuntimeTraceContext();
        $this->app->instance(SemanticGraphRepository::class, $this->graph);
        $this->app->instance(RuntimeEvidenceRepository::class, $this->runtime);
        $this->app->instance(RuntimeTraceContext::class, $this->context);
        $this->snapshotId = $this->activateSnapshot();
        Queue::createPayloadUsing(null);
    }

    protected function tearDown(): void
    {
        Queue::createPayloadUsing(null);
        unset($this->runtime, $this->graph, $this->factory);
        @unlink($this->databasePath);
        @unlink($this->databasePath.'-shm');
        @unlink($this->databasePath.'-wal');
        parent::tearDown();
    }

    public function test_runtime_collection_is_disabled_by_default_and_registers_no_hooks(): void
    {
        self::assertFalse((bool) config('logic-map.runtime.enabled', false));
        $before = $this->payloadCallbackCount();
        (new LogicMapServiceProvider($this->app))->boot();

        self::assertSame($before, $this->payloadCallbackCount());
        foreach (['web', 'api'] as $group) {
            self::assertNotContains(LogicMapRuntimeTrace::class, $this->app['router']->getMiddlewareGroups()[$group] ?? []);
        }
        self::assertSame([], $this->runtime->sessionsForSnapshot($this->snapshotId));
    }

    public function test_enabled_provider_registers_once_and_request_trace_is_snapshot_bound(): void
    {
        config()->set('logic-map.runtime.enabled', true);
        config()->set('logic-map.runtime.sample_rate', 1.0);
        config()->set('logic-map.runtime.middleware_groups', ['web', 'api', 'missing']);
        $this->app['router']->middlewareGroup('web', []);
        $this->app['router']->middlewareGroup('api', []);
        $provider = new LogicMapServiceProvider($this->app);
        $provider->boot();
        $provider->boot();

        foreach (['web', 'api'] as $group) {
            self::assertSame(1, count(array_filter(
                $this->app['router']->getMiddlewareGroups()[$group] ?? [],
                static fn (string $middleware): bool => $middleware === LogicMapRuntimeTrace::class,
            )));
        }
        self::assertSame(1, $this->payloadCallbackCount());
        self::assertContains('runtime_middleware_group_missing', array_column($this->runtime->diagnostics(), 'code'));

        $response = $this->app->make(LogicMapRuntimeTrace::class)->handle(
            \Illuminate\Http\Request::create('/runtime-probe', 'GET'),
            static fn () => response('ok', 202),
        );
        self::assertSame(202, $response->getStatusCode());
        $sessions = $this->runtime->sessionsForSnapshot($this->snapshotId);
        self::assertCount(1, $sessions);
        self::assertNotNull($sessions[0]->endedAt);
        self::assertSame(['request_start', 'request_complete'], array_map(
            static fn ($row): string => $row->kind,
            $this->runtime->observationsForSnapshot($this->snapshotId, $sessions[0]->id),
        ));

        $this->context->begin('queue-session', $this->snapshotId, 'queue-correlation', 'parent-correlation', new DateTimeImmutable());
        $this->runtime->open(new RuntimeSession(
            'queue-session', $this->snapshotId, new DateTimeImmutable(), null, 'queue-correlation',
        ));
        $metadata = (new QueueTracePayload($this->runtime))->create($this->context);
        self::assertSame(['session_id', 'snapshot_id', 'correlation_id', 'parent_id'], array_keys($metadata['logic_map']));
    }

    public function test_subscriber_records_sanitized_sql_event_job_http_and_opt_in_cache_observations(): void
    {
        $session = new RuntimeSession('subscriber', $this->snapshotId, new DateTimeImmutable(), null, 'root');
        $this->runtime->open($session);
        $this->context->begin('subscriber', $this->snapshotId, 'root', null, new DateTimeImmutable());
        $subscriber = new LaravelRuntimeSubscriber(
            $this->runtime,
            $this->context,
            new SqlTableObservationParser(),
            new QueueTracePayload($this->runtime),
            false,
            'App\\',
        );

        $subscriber->onQuery(new QueryExecuted(
            'select * from "orders" where id = ?',
            ['binding-secret'],
            2.5,
            $this->app['db']->connection(),
        ));
        $subscriber->onQuery(new QueryExecuted(
            'pragma table_info(?)',
            ['sql-secret'],
            1.0,
            $this->app['db']->connection(),
        ));
        $subscriber->onApplicationEvent('App\\Events\\OrderSaved', [new \stdClass()]);
        $subscriber->onCache($this->cacheHit('order:token=cache-secret', ['value-secret']));

        $request = new Request(new PsrRequest('POST', 'https://api.example.test/orders/42?token=http-secret'));
        $subscriber->onRequestSending(new RequestSending($request));
        $subscriber->onResponseReceived(new ResponseReceived($request, new Response(new PsrResponse(204))));

        $payload = ['logic_map' => [
            'session_id' => 'subscriber', 'snapshot_id' => $this->snapshotId,
            'correlation_id' => 'job-correlation', 'parent_id' => 'root',
        ]];
        $job = new class($payload)
        {
            public function __construct(private array $payload) {}
            public function payload(): array { return $this->payload; }
            public function resolveName(): string { return 'App\\Jobs\\SyncOrder'; }
        };
        $subscriber->onJobProcessing(new JobProcessing('sync', $job));
        $subscriber->onJobProcessed(new JobProcessed('sync', $job));
        $subscriber->onJobFailed(new JobFailed('sync', $job, new RuntimeException('password=job-secret')));

        $withoutCache = $this->runtime->observationsForSnapshot($this->snapshotId, 'subscriber');
        self::assertNotContains('cache_hit', array_column(array_map(static fn ($row) => $row->toArray(), $withoutCache), 'kind'));
        $serialized = strtolower(json_encode(array_map(static fn ($row) => $row->toArray(), $withoutCache), JSON_THROW_ON_ERROR));
        foreach (['binding-secret', 'sql-secret', 'http-secret', 'job-secret', 'value-secret'] as $secret) {
            self::assertStringNotContainsString($secret, $serialized);
        }
        self::assertStringContainsString('unparsed_runtime_sql', $serialized);
        self::assertStringContainsString('orders', $serialized);
        self::assertStringContainsString('app\\\\events\\\\ordersaved', $serialized);
        self::assertStringContainsString('app\\\\jobs\\\\syncorder', $serialized);

        $cacheSubscriber = new LaravelRuntimeSubscriber(
            $this->runtime,
            $this->context,
            new SqlTableObservationParser(),
            new QueueTracePayload($this->runtime),
            true,
            'App\\',
        );
        $this->context->begin('subscriber', $this->snapshotId, 'cache-correlation', null, new DateTimeImmutable());
        $cacheSubscriber->onCache($this->cacheHit('order-summary:{id}', ['never-store-this']));
        $withCache = strtolower(json_encode(array_map(
            static fn ($row) => $row->toArray(),
            $this->runtime->observationsForSnapshot($this->snapshotId, 'subscriber'),
        ), JSON_THROW_ON_ERROR));
        self::assertStringContainsString('order-summary:{id}', $withCache);
        self::assertStringNotContainsString('never-store-this', $withCache);

        $mismatch = ['logic_map' => [
            'session_id' => 'subscriber', 'snapshot_id' => 'another-snapshot',
            'correlation_id' => 'bad', 'parent_id' => null,
        ]];
        self::assertNull((new QueueTracePayload($this->runtime))->read($mismatch));
        self::assertContains('runtime_queue_snapshot_mismatch', array_column($this->runtime->diagnostics(), 'code'));
    }

    private function activateSnapshot(): string
    {
        $fingerprint = hash('sha256', 'runtime-feature');
        $id = hash('sha256', SqliteSchema::VERSION."\0".$fingerprint);
        $snapshot = new GraphSnapshot(
            $id,
            SqliteSchema::VERSION,
            '2.0-runtime-test',
            new DateTimeImmutable('2026-07-17T00:00:00+00:00'),
            $fingerprint,
            [],
            new KnowledgeGraph(),
            [],
            [],
        );
        $this->graph->store($snapshot);
        $this->graph->activate($id);

        return $id;
    }

    private function cacheHit(string $key, mixed $value): CacheHit
    {
        $constructor = (new ReflectionClass(CacheHit::class))->getConstructor();

        return ($constructor?->getNumberOfParameters() ?? 0) >= 4
            ? new CacheHit('array', $key, $value)
            : new CacheHit($key, $value);
    }

    private function payloadCallbackCount(): int
    {
        $reflection = new ReflectionClass(Queue::class);
        $property = $reflection->getProperty('createPayloadCallbacks');
        $property->setAccessible(true);

        return count($property->getValue());
    }
}
