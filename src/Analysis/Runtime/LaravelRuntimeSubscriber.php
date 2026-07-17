<?php

namespace DNDark\LogicMap\Analysis\Runtime;

use DateTimeImmutable;
use DNDark\LogicMap\Contracts\RuntimeEvidenceRepository;
use DNDark\LogicMap\Domain\Snapshot\RuntimeObservation;
use Illuminate\Cache\Events\CacheEvent;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Http\Client\Events\ConnectionFailed;
use Illuminate\Http\Client\Events\RequestSending;
use Illuminate\Http\Client\Events\ResponseReceived;
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Queue\Events\JobProcessed;
use Illuminate\Queue\Events\JobProcessing;
use Throwable;

final readonly class LaravelRuntimeSubscriber
{
    public function __construct(
        private RuntimeEvidenceRepository $repository,
        private RuntimeTraceContext $context,
        private SqlTableObservationParser $sqlParser,
        private QueueTracePayload $queuePayload,
        private bool $collectCacheEvents,
        private string $applicationNamespace,
    ) {
    }

    public function onQuery(QueryExecuted $event): void
    {
        $parsed = $this->sqlParser->parse((string) $event->sql);

        if ($parsed === null) {
            $this->record('diagnostic', [
                'code' => 'unparsed_runtime_sql',
                'message' => 'A runtime SQL statement could not be conservatively mapped to a table.',
            ], durationMs: is_numeric($event->time) ? (float) $event->time : null);

            return;
        }

        $this->record(
            'sql_'.$parsed['operation'],
            ['table_names' => $parsed['table_names']],
            durationMs: is_numeric($event->time) ? (float) $event->time : null,
            success: true,
        );
    }

    public function onApplicationEvent(string|object $event, array $payload = []): void
    {
        $class = is_object($event) ? $event::class : $event;

        if (! str_starts_with($class, $this->applicationNamespace)) {
            return;
        }

        $this->record('application_event', ['event_class' => $class], success: true);
    }

    public function onRequestSending(RequestSending $event): void
    {
        $this->record('http_request', [
            'method' => $event->request->method(),
            'url_template' => $event->request->url(),
        ]);
    }

    public function onResponseReceived(ResponseReceived $event): void
    {
        $this->record('http_response', [
            'method' => $event->request->method(),
            'url_template' => $event->request->url(),
            'status' => $event->response->status(),
            'success' => $event->response->successful(),
        ], success: $event->response->successful());
    }

    public function onConnectionFailed(ConnectionFailed $event): void
    {
        $this->record('http_failed', [
            'method' => $event->request->method(),
            'url_template' => $event->request->url(),
            'exception_class' => $event->exception::class,
            'message' => $event->exception->getMessage(),
        ], success: false);
    }

    public function onCache(CacheEvent $event): void
    {
        if (! $this->collectCacheEvents) {
            return;
        }

        $kind = match (class_basename($event)) {
            'CacheHit' => 'cache_hit',
            'CacheMissed' => 'cache_missed',
            'KeyWritten' => 'cache_written',
            'KeyForgotten' => 'cache_forgotten',
            default => 'cache_event',
        };
        $this->record($kind, ['cache_key' => (string) $event->key], success: true);
    }

    public function onJobProcessing(JobProcessing $event): void
    {
        $metadata = $this->queuePayload->read($event->job->payload());

        if ($metadata === null) {
            return;
        }

        $this->context->begin(
            $metadata['session_id'],
            $metadata['snapshot_id'],
            $metadata['correlation_id'],
            $metadata['parent_id'],
            new DateTimeImmutable('now'),
        );
        $this->record('job_processing', ['job_class' => $event->job->resolveName()]);
    }

    public function onJobProcessed(JobProcessed $event): void
    {
        $metadata = $this->queuePayload->read($event->job->payload());

        if ($metadata !== null) {
            $this->adopt($metadata);
            $this->record('job_processed', ['job_class' => $event->job->resolveName()], success: true);
        }

        $this->context->clear();
    }

    public function onJobFailed(JobFailed $event): void
    {
        $metadata = $this->queuePayload->read($event->job->payload());

        if ($metadata !== null) {
            $this->adopt($metadata);
            $this->record('job_failed', [
                'job_class' => $event->job->resolveName(),
                'exception_class' => $event->exception::class,
                'message' => $event->exception->getMessage(),
            ], success: false);
        }

        $this->context->clear();
    }

    /** @param array{session_id:string,snapshot_id:string,correlation_id:string,parent_id:?string} $metadata */
    private function adopt(array $metadata): void
    {
        $this->context->begin(
            $metadata['session_id'],
            $metadata['snapshot_id'],
            $metadata['correlation_id'],
            $metadata['parent_id'],
            new DateTimeImmutable('now'),
        );
    }

    private function record(
        string $kind,
        array $attributes,
        ?string $sourceNodeId = null,
        ?string $targetNodeId = null,
        ?float $durationMs = null,
        ?bool $success = null,
    ): void {
        if (! $this->context->active()) {
            return;
        }

        try {
            $this->repository->record(new RuntimeObservation(
                (string) $this->context->sessionId(),
                (string) $this->context->correlationId(),
                $this->context->parentId(),
                new DateTimeImmutable('now'),
                $kind,
                $sourceNodeId,
                $targetNodeId,
                $durationMs,
                $success,
                $attributes,
            ));
        } catch (Throwable $throwable) {
            $this->repository->diagnose('runtime_observation_write_failed', $throwable->getMessage());
        }
    }
}
