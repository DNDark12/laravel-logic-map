<?php

namespace DNDark\LogicMap\Analysis\Runtime;

use DNDark\LogicMap\Contracts\RuntimeEvidenceRepository;

final readonly class QueueTracePayload
{
    public function __construct(private RuntimeEvidenceRepository $repository)
    {
    }

    public function create(RuntimeTraceContext $context): array
    {
        if (! $context->active()) {
            return [];
        }

        return ['logic_map' => [
            'session_id' => $context->sessionId(),
            'snapshot_id' => $context->snapshotId(),
            'correlation_id' => bin2hex(random_bytes(16)),
            'parent_id' => $context->correlationId(),
        ]];
    }

    /** @return array{session_id:string,snapshot_id:string,correlation_id:string,parent_id:?string}|null */
    public function read(array $payload): ?array
    {
        $metadata = $payload['logic_map'] ?? null;

        if (! is_array($metadata) || array_keys($metadata) !== [
            'session_id', 'snapshot_id', 'correlation_id', 'parent_id',
        ]) {
            $this->repository->diagnose('runtime_queue_payload_invalid', 'Queue trace metadata was missing or malformed.');

            return null;
        }

        foreach (['session_id', 'snapshot_id', 'correlation_id'] as $key) {
            if (! is_string($metadata[$key]) || trim($metadata[$key]) === '') {
                $this->repository->diagnose('runtime_queue_payload_invalid', 'Queue trace metadata contained an invalid identifier.');

                return null;
            }
        }

        if ($metadata['parent_id'] !== null && (! is_string($metadata['parent_id']) || trim($metadata['parent_id']) === '')) {
            $this->repository->diagnose('runtime_queue_payload_invalid', 'Queue trace metadata contained an invalid parent identifier.');

            return null;
        }

        $session = $this->repository->session($metadata['session_id']);

        if ($session === null || ! hash_equals($session->snapshotId, $metadata['snapshot_id'])) {
            $this->repository->diagnose(
                'runtime_queue_snapshot_mismatch',
                'Queue trace metadata did not match its persisted runtime session snapshot.',
            );

            return null;
        }

        return $metadata;
    }
}
