<?php

namespace DNDark\LogicMap\Support;

use DNDark\LogicMap\Domain\Snapshot\IndexedFile;
use DNDark\LogicMap\Services\Indexing\IndexOptions;
use InvalidArgumentException;

final readonly class SourceFingerprint
{
    public function __construct(
        private string $analysisVersion,
        private int $schemaVersion,
        private array $semanticConfiguration = [],
    ) {
        if (trim($analysisVersion) === '' || $schemaVersion < 1) {
            throw new InvalidArgumentException('Fingerprint version inputs must be valid.');
        }
    }

    /** @param list<IndexedFile> $files */
    public function calculate(IndexOptions $options, array $files): string
    {
        usort($files, static fn (IndexedFile $left, IndexedFile $right): int => $left->path <=> $right->path);

        $payload = [
            'schema_version' => $this->schemaVersion,
            'analysis_version' => $this->analysisVersion,
            'options' => $options->fingerprintData(),
            'files' => array_map(static fn (IndexedFile $file): array => $file->toArray(), $files),
        ];

        if ($this->semanticConfiguration !== []) {
            $payload['semantic_configuration'] = $this->semanticConfiguration;
        }

        return hash('sha256', CanonicalJson::encode($payload));
    }
}
