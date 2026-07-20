<?php

namespace DNDark\LogicMap\Tests\Unit\Services\Query;

use DateTimeImmutable;
use DNDark\LogicMap\Contracts\RuntimeEvidenceRepository;
use DNDark\LogicMap\Domain\Graph\Certainty;
use DNDark\LogicMap\Domain\Graph\EdgeType;
use DNDark\LogicMap\Domain\Graph\EvidenceOrigin;
use DNDark\LogicMap\Domain\Graph\EvidenceRecord;
use DNDark\LogicMap\Domain\Graph\GraphEdge;
use DNDark\LogicMap\Domain\Graph\GraphNode;
use DNDark\LogicMap\Domain\Graph\KnowledgeGraph;
use DNDark\LogicMap\Domain\Graph\NodeId;
use DNDark\LogicMap\Domain\Graph\NodeKind;
use DNDark\LogicMap\Domain\Snapshot\GraphSnapshot;
use DNDark\LogicMap\Domain\Snapshot\RuntimeObservation;
use DNDark\LogicMap\Domain\Snapshot\RuntimeSession;
use DNDark\LogicMap\Services\Query\RuntimeEvidenceMerger;
use PHPUnit\Framework\TestCase;

final class RuntimeEvidenceMergerTest extends TestCase
{
    public function test_it_keeps_static_and_runtime_provenance_separate(): void
    {
        [$snapshot, $edge] = $this->snapshot('current');
        $repository = $this->repository(
            [
                $this->session('selected', $snapshot->id),
                $this->session('other', $snapshot->id),
            ],
            [
                $this->observation('selected', EdgeType::Calls, 'method:App\\A::run', 'method:App\\B::run'),
                $this->observation('selected', EdgeType::ReadsTable, 'method:App\\B::run', 'table:orders'),
                $this->observation('other', EdgeType::WritesTable, 'method:App\\B::run', 'table:orders'),
            ],
        );

        $result = (new RuntimeEvidenceMerger($repository))->merge(
            $snapshot,
            ['selected'],
            ['method:App\\A::run', 'method:App\\B::run'],
        );
        $matched = $this->relation($result, 'method:App\\A::run', 'method:App\\B::run', EdgeType::Calls);
        $runtimeOnly = $this->relation($result, 'method:App\\B::run', 'table:orders', EdgeType::ReadsTable);

        self::assertSame(['runtime', 'static_ast'], $matched['evidence_origins']);
        self::assertSame(Certainty::Certain->value, $matched['static_certainty']);
        self::assertFalse($matched['runtime_only']);
        self::assertSame('Observed in 1 selected runtime sessions', $matched['coverage']);
        self::assertSame($edge->id, $result['overlays'][$matched['relation_key']]->id);
        self::assertSame(['runtime'], $runtimeOnly['evidence_origins']);
        self::assertNull($runtimeOnly['static_certainty']);
        self::assertTrue($runtimeOnly['runtime_only']);
        self::assertSame('Observed in 1 selected runtime sessions', $runtimeOnly['coverage']);
        self::assertArrayNotHasKey(
            RuntimeEvidenceMerger::relationKey('method:App\\B::run', 'table:orders', EdgeType::WritesTable),
            $result['overlays'],
        );
    }

    public function test_missing_observation_does_not_lower_static_certainty(): void
    {
        [$snapshot] = $this->snapshot('current');
        $repository = $this->repository([$this->session('selected', $snapshot->id)], []);

        $result = (new RuntimeEvidenceMerger($repository))->merge(
            $snapshot,
            ['selected'],
            ['method:App\\A::run', 'method:App\\B::run'],
        );
        $relation = $this->relation($result, 'method:App\\A::run', 'method:App\\B::run', EdgeType::Calls);

        self::assertSame(Certainty::Certain->value, $relation['static_certainty']);
        self::assertFalse($relation['observed']);
        self::assertSame('Not observed in selected sessions', $relation['coverage']);
        self::assertSame(['static_ast'], $relation['evidence_origins']);
    }

    public function test_no_sessions_reports_unknown_coverage_instead_of_never_executed(): void
    {
        [$snapshot] = $this->snapshot('current');
        $result = (new RuntimeEvidenceMerger($this->repository([], [])))->merge(
            $snapshot,
            null,
            ['method:App\\A::run', 'method:App\\B::run'],
        );
        $relation = $this->relation($result, 'method:App\\A::run', 'method:App\\B::run', EdgeType::Calls);

        self::assertSame('No runtime data available', $result['coverage']);
        self::assertSame('No runtime data available', $relation['coverage']);
        self::assertStringNotContainsString('never executed', strtolower(json_encode($result, JSON_THROW_ON_ERROR)));
    }

    public function test_stale_observations_cannot_be_retargeted_to_the_active_snapshot(): void
    {
        [$snapshot] = $this->snapshot('current');
        [$oldSnapshot] = $this->snapshot('old');
        $repository = $this->repository(
            [
                $this->session('current', $snapshot->id),
                $this->session('stale', $oldSnapshot->id),
            ],
            [
                $this->observation('stale', EdgeType::ReadsTable, 'method:App\\B::run', 'table:orders'),
            ],
        );

        $result = (new RuntimeEvidenceMerger($repository))->merge($snapshot);

        self::assertSame(['current'], $result['selected_session_ids']);
        self::assertArrayNotHasKey(
            RuntimeEvidenceMerger::relationKey('method:App\\B::run', 'table:orders', EdgeType::ReadsTable),
            $result['overlays'],
        );
    }

    private function snapshot(string $seed): array
    {
        $graph = new KnowledgeGraph();
        $source = NodeId::method('App\\A', 'run');
        $target = NodeId::method('App\\B', 'run');
        $table = NodeId::named(NodeKind::Table, 'orders');
        $graph->addNode(new GraphNode($source, NodeKind::Method, 'run', 'App\\A::run', null));
        $graph->addNode(new GraphNode($target, NodeKind::Method, 'run', 'App\\B::run', null));
        $graph->addNode(new GraphNode($table, NodeKind::Table, 'orders', null, null));
        $evidence = new EvidenceRecord(
            EvidenceOrigin::StaticAst,
            'test-static-call',
            Certainty::Certain,
            null,
            'App\\A::run -> App\\B::run',
            null,
            ['occurrence' => 'static-call-'.$seed],
        );
        $edge = GraphEdge::fromEvidence($source, $target, EdgeType::Calls, $evidence);
        $graph->addEdge($edge);
        $fingerprint = hash('sha256', $seed);
        $snapshot = new GraphSnapshot(
            hash('sha256', "2\0".$fingerprint),
            2,
            '2.0.0-test',
            new DateTimeImmutable('2026-07-17T00:00:00+00:00'),
            $fingerprint,
            [],
            $graph,
            [],
            [],
        );

        return [$snapshot, $edge];
    }

    private function session(string $id, string $snapshotId): RuntimeSession
    {
        return new RuntimeSession(
            $id,
            $snapshotId,
            new DateTimeImmutable('2026-07-17T00:00:00+00:00'),
            new DateTimeImmutable('2026-07-17T00:01:00+00:00'),
            'root-'.$id,
            1,
        );
    }

    private function observation(string $sessionId, EdgeType $type, string $source, string $target): RuntimeObservation
    {
        return new RuntimeObservation(
            $sessionId,
            'correlation-'.$sessionId.'-'.$type->value,
            null,
            new DateTimeImmutable('2026-07-17T00:00:30+00:00'),
            $type->value,
            $source,
            $target,
            1.0,
            true,
            [],
        );
    }

    private function relation(array $result, string $source, string $target, EdgeType $type): array
    {
        $key = RuntimeEvidenceMerger::relationKey($source, $target, $type);

        foreach ($result['relations'] as $relation) {
            if ($relation['relation_key'] === $key) {
                return $relation;
            }
        }

        self::fail("Missing runtime relation {$key}.");
    }

    private function repository(array $sessions, array $observations): RuntimeEvidenceRepository
    {
        return new class($sessions, $observations) implements RuntimeEvidenceRepository
        {
            public function __construct(
                private array $sessions,
                private array $observations,
            ) {
            }

            public function open(RuntimeSession $session): bool { return true; }
            public function complete(string $sessionId, DateTimeImmutable $endedAt): void {}
            public function record(RuntimeObservation $observation): bool { return true; }
            public function session(string $sessionId): ?RuntimeSession
            {
                foreach ($this->sessions as $session) {
                    if ($session->id === $sessionId) return $session;
                }

                return null;
            }
            public function sessionsForSnapshot(string $snapshotId): array { return $this->sessions; }
            public function observationsForSnapshot(string $snapshotId, ?string $sessionId = null): array
            {
                return array_values(array_filter(
                    $this->observations,
                    static fn (RuntimeObservation $observation): bool => $sessionId === null
                        || $observation->sessionId === $sessionId,
                ));
            }
            public function diagnostics(): array { return []; }
            public function diagnose(string $code, string $message): void {}
            public function clear(): void {}
        };
    }
}
