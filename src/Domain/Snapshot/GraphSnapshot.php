<?php

namespace DNDark\LogicMap\Domain\Snapshot;

use DateTimeImmutable;
use DNDark\LogicMap\Domain\Graph\KnowledgeGraph;
use DNDark\LogicMap\Domain\Graph\NodeKind;
use InvalidArgumentException;

final readonly class GraphSnapshot
{
    /** @var list<IndexedFile> */
    public array $files;

    /** @var list<Diagnostic> */
    public array $diagnostics;

    /** @var list<ProcessStepRecord> */
    public array $processSteps;

    public function __construct(
        public string $id,
        public int $schemaVersion,
        public string $analysisVersion,
        public DateTimeImmutable $indexedAt,
        public string $sourceFingerprint,
        array $files,
        public KnowledgeGraph $graph,
        array $diagnostics,
        public array $phaseMetrics,
        array $processSteps = [],
    ) {
        if ($schemaVersion < 1) {
            throw new InvalidArgumentException('Snapshot schema versions must be positive.');
        }

        if (trim($analysisVersion) === '') {
            throw new InvalidArgumentException('Snapshot analysis version is required.');
        }

        if (preg_match('/^[a-f0-9]{64}$/', $sourceFingerprint) !== 1) {
            throw new InvalidArgumentException('Snapshot source fingerprints must be lowercase SHA-256 values.');
        }

        $expectedId = hash('sha256', $schemaVersion."\0".$sourceFingerprint);

        if (! hash_equals($expectedId, $id)) {
            throw new InvalidArgumentException('Snapshot ID must derive from schema version and source fingerprint.');
        }

        foreach ($files as $file) {
            if (! $file instanceof IndexedFile) {
                throw new InvalidArgumentException('Snapshot files must contain IndexedFile values.');
            }
        }

        usort($files, static fn (IndexedFile $left, IndexedFile $right): int => $left->path <=> $right->path);
        $this->files = array_values($files);

        foreach ($diagnostics as $diagnostic) {
            if (! $diagnostic instanceof Diagnostic) {
                throw new InvalidArgumentException('Snapshot diagnostics must contain Diagnostic values.');
            }
        }

        usort($diagnostics, static function (Diagnostic $left, Diagnostic $right): int {
            return [
                $left->code->value,
                $left->phase,
                $left->file ?? '',
                $left->startLine ?? 0,
                $left->endLine ?? 0,
                $left->message,
            ] <=> [
                $right->code->value,
                $right->phase,
                $right->file ?? '',
                $right->startLine ?? 0,
                $right->endLine ?? 0,
                $right->message,
            ];
        });
        $this->diagnostics = array_values($diagnostics);

        $nodeKinds = [];

        foreach ($graph->nodes() as $node) {
            $nodeKinds[$node->id->value] = $node->kind;
        }

        $identities = [];
        $ordinals = [];

        foreach ($processSteps as $step) {
            if (! $step instanceof ProcessStepRecord) {
                throw new InvalidArgumentException('Snapshot process steps must contain ProcessStepRecord values.');
            }

            if (($nodeKinds[$step->processId->value] ?? null) !== NodeKind::Process) {
                throw new InvalidArgumentException('Snapshot process steps must reference process graph nodes.');
            }

            if ($step->nodeId !== null && ! isset($nodeKinds[$step->nodeId->value])) {
                throw new InvalidArgumentException('Snapshot process steps must reference existing graph nodes.');
            }

            $identity = $step->processId->value."\0".$step->stepId;
            $ordinal = $step->processId->value."\0".$step->ordinal;

            if (isset($identities[$identity]) || isset($ordinals[$ordinal])) {
                throw new InvalidArgumentException('Snapshot process step IDs and ordinals must be unique per process.');
            }

            $identities[$identity] = true;
            $ordinals[$ordinal] = true;
        }

        usort($processSteps, static fn (ProcessStepRecord $left, ProcessStepRecord $right): int => [
            $left->processId->value,
            $left->ordinal,
            $left->stepId,
        ] <=> [
            $right->processId->value,
            $right->ordinal,
            $right->stepId,
        ]);
        $this->processSteps = array_values($processSteps);
    }
}
