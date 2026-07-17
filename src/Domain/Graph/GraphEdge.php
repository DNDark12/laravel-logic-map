<?php

namespace DNDark\LogicMap\Domain\Graph;

use InvalidArgumentException;

final class GraphEdge
{
    public function __construct(
        public readonly string $id,
        public readonly NodeId $source,
        public readonly NodeId $target,
        public readonly EdgeType $type,
        public readonly string $siteKey,
        public array $evidence,
    ) {
        if (preg_match('/^[a-f0-9]{64}$/', $id) !== 1) {
            throw new InvalidArgumentException('Graph edge IDs must be lowercase hexadecimal SHA-256 strings.');
        }

        if ($siteKey === '') {
            throw new InvalidArgumentException('Graph edge site keys must be non-empty.');
        }

        if ($evidence === []) {
            throw new InvalidArgumentException('Graph edges require at least one evidence record.');
        }

        $unique = [];

        foreach ($evidence as $record) {
            if (! $record instanceof EvidenceRecord) {
                throw new InvalidArgumentException('Graph edge evidence must contain EvidenceRecord values.');
            }

            $unique[$record->id()] = $record;
        }

        ksort($unique, SORT_STRING);
        $this->evidence = array_values($unique);
    }

    public static function fromEvidence(
        NodeId $source,
        NodeId $target,
        EdgeType $type,
        EvidenceRecord $evidence,
    ): self {
        $siteKey = implode("\0", [
            $evidence->detector,
            $evidence->origin->value,
            self::occurrence($evidence),
            self::conditionHash($evidence->condition),
        ]);
        $id = hash('sha256', implode("\0", [
            $source->value,
            $target->value,
            $type->value,
            $siteKey,
        ]));

        return new self($id, $source, $target, $type, $siteKey, [$evidence]);
    }

    public function addEvidence(EvidenceRecord $evidence): void
    {
        $records = [];

        foreach ([...$this->evidence, $evidence] as $record) {
            $records[$record->id()] = $record;
        }

        ksort($records, SORT_STRING);
        $this->evidence = array_values($records);
    }

    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'source' => $this->source->value,
            'target' => $this->target->value,
            'type' => $this->type->value,
            'site_key' => $this->siteKey,
            'evidence' => array_map(
                static fn (EvidenceRecord $record): string => $record->id(),
                $this->evidence,
            ),
        ];
    }

    private static function occurrence(EvidenceRecord $evidence): string
    {
        if ($evidence->location !== null) {
            return implode(':', [
                $evidence->location->file,
                (string) $evidence->location->startLine,
                (string) $evidence->location->endLine,
                self::normalizeWhitespace($evidence->expression ?? ''),
            ]);
        }

        foreach (['registration_key', 'occurrence'] as $attribute) {
            $value = $evidence->attributes[$attribute] ?? null;

            if (is_string($value) && trim($value) !== '') {
                return self::normalizeWhitespace($value);
            }
        }

        throw new InvalidArgumentException(
            'Evidence without a source location requires a deterministic registration_key or occurrence attribute.',
        );
    }

    private static function conditionHash(?string $condition): string
    {
        if ($condition === null || trim($condition) === '') {
            return '';
        }

        return hash('sha256', self::normalizeWhitespace($condition));
    }

    private static function normalizeWhitespace(string $value): string
    {
        $normalized = preg_replace('/\s+/u', ' ', trim($value)) ?? trim($value);
        $normalized = preg_replace('/\s*(\?->|->|::)\s*/u', '$1', $normalized) ?? $normalized;

        return preg_replace('/\s*([()\[\]{},;])\s*/u', '$1', $normalized) ?? $normalized;
    }
}
