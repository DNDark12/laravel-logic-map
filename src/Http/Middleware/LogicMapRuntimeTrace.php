<?php

namespace DNDark\LogicMap\Http\Middleware;

use Closure;
use DateTimeImmutable;
use DNDark\LogicMap\Analysis\Runtime\RuntimeTraceContext;
use DNDark\LogicMap\Contracts\RuntimeEvidenceRepository;
use DNDark\LogicMap\Contracts\SemanticGraphRepository;
use DNDark\LogicMap\Domain\Graph\NodeId;
use DNDark\LogicMap\Domain\Snapshot\RuntimeObservation;
use DNDark\LogicMap\Domain\Snapshot\RuntimeSession;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

final readonly class LogicMapRuntimeTrace
{
    public function __construct(
        private RuntimeEvidenceRepository $repository,
        private SemanticGraphRepository $graphs,
        private RuntimeTraceContext $context,
    ) {
    }

    public function handle(Request $request, Closure $next): Response
    {
        $snapshot = $this->graphs->active();
        $sampleRate = max(0.0, min(1.0, (float) config('logic-map.runtime.sample_rate', 1.0)));

        if ($snapshot === null || ! $this->sampled($sampleRate)) {
            return $next($request);
        }

        $startedAt = new DateTimeImmutable('now');
        $startedNs = hrtime(true);
        $correlationId = bin2hex(random_bytes(16));
        $sessionId = bin2hex(random_bytes(16));

        if (! $this->repository->open(new RuntimeSession(
            $sessionId,
            $snapshot->id,
            $startedAt,
            null,
            $correlationId,
        ))) {
            return $next($request);
        }

        $this->context->begin($sessionId, $snapshot->id, $correlationId, null, $startedAt);
        $routeTemplate = $request->route()?->uri();
        $source = is_string($routeTemplate)
            ? NodeId::route($request->method(), $routeTemplate)->value
            : null;
        $this->safeRecord(new RuntimeObservation(
            $sessionId,
            $correlationId,
            null,
            $startedAt,
            'request_start',
            $source,
            null,
            null,
            null,
            ['method' => $request->method(), 'route_template' => $routeTemplate ?? $request->path()],
        ));

        try {
            $response = $next($request);
        } catch (Throwable $throwable) {
            $this->finish($sessionId, $correlationId, $source, $startedNs, false, null, $throwable);
            throw $throwable;
        }

        $this->finish($sessionId, $correlationId, $source, $startedNs, true, $response->getStatusCode());

        return $response;
    }

    private function finish(
        string $sessionId,
        string $correlationId,
        ?string $source,
        int $startedNs,
        bool $success,
        ?int $status,
        ?Throwable $throwable = null,
    ): void {
        $endedAt = new DateTimeImmutable('now');
        $attributes = ['status' => $status, 'success' => $success];

        if ($throwable !== null) {
            $attributes['exception_class'] = $throwable::class;
            $attributes['message'] = $throwable->getMessage();
        }

        $this->safeRecord(new RuntimeObservation(
            $sessionId,
            $correlationId,
            null,
            $endedAt,
            'request_complete',
            $source,
            null,
            (hrtime(true) - $startedNs) / 1_000_000,
            $success,
            $attributes,
        ));

        try {
            $this->repository->complete($sessionId, $endedAt);
        } catch (Throwable $storageFailure) {
            $this->repository->diagnose('runtime_session_complete_failed', $storageFailure->getMessage());
        } finally {
            $this->context->clear();
        }
    }

    private function safeRecord(RuntimeObservation $observation): void
    {
        try {
            $this->repository->record($observation);
        } catch (Throwable $throwable) {
            $this->repository->diagnose('runtime_observation_write_failed', $throwable->getMessage());
        }
    }

    private function sampled(float $rate): bool
    {
        if ($rate <= 0.0) return false;
        if ($rate >= 1.0) return true;

        return random_int(0, PHP_INT_MAX) / PHP_INT_MAX <= $rate;
    }
}
