<?php

namespace DNDark\LogicMap\Analysis\Php;

use DNDark\LogicMap\Analysis\Facts\CallSiteFact;
use DNDark\LogicMap\Analysis\Facts\InheritanceFact;
use DNDark\LogicMap\Analysis\Facts\SemanticFact;
use DNDark\LogicMap\Domain\Snapshot\Diagnostic;
use DNDark\LogicMap\Support\RelativePath;
use InvalidArgumentException;

final readonly class ParsedFile
{
    public string $relativePath;

    public function __construct(
        string $relativePath,
        public string $contentHash,
        public array $symbols,
        public array $imports,
        public array $inheritanceFacts,
        public array $callSites,
        public array $semanticFacts,
        public array $diagnostics,
    ) {
        $this->relativePath = RelativePath::normalize($relativePath);

        if (preg_match('/^[a-f0-9]{64}$/', $contentHash) !== 1) {
            throw new InvalidArgumentException('Parsed file content hashes must be lowercase SHA-256 values.');
        }

        self::assertInstances($symbols, SymbolDefinition::class, 'symbols');
        self::assertInstances($inheritanceFacts, InheritanceFact::class, 'inheritance facts');
        self::assertInstances($callSites, CallSiteFact::class, 'call sites');
        self::assertInstances($semanticFacts, SemanticFact::class, 'semantic facts');
        self::assertInstances($diagnostics, Diagnostic::class, 'diagnostics');
    }

    /** @return list<SemanticFact> */
    public function facts(?string $kind = null): array
    {
        if ($kind === null) {
            return $this->semanticFacts;
        }

        return array_values(array_filter(
            $this->semanticFacts,
            static fn (SemanticFact $fact): bool => $fact->kind === $kind,
        ));
    }

    private static function assertInstances(array $values, string $class, string $label): void
    {
        foreach ($values as $value) {
            if (! $value instanceof $class) {
                throw new InvalidArgumentException("Parsed file {$label} contain an invalid value.");
            }
        }
    }
}
