<?php

namespace DNDark\LogicMap\Domain\Impact;

use DNDark\LogicMap\Domain\Graph\EvidenceRecord;
use InvalidArgumentException;

final readonly class ImpactReport
{
    public array $changedSymbols;

    public array $affectedSymbols;

    public array $evidence;

    public array $selectedTests;

    public function __construct(
        array $changedSymbols,
        array $affectedSymbols,
        array $evidence,
        public array $truncation,
        array $selectedTests = [],
    ) {
        foreach ($changedSymbols as $symbol) {
            if (! $symbol instanceof ChangedSymbol) {
                throw new InvalidArgumentException('Impact changed symbols must contain ChangedSymbol values.');
            }
        }

        foreach ($affectedSymbols as $symbol) {
            if (! $symbol instanceof AffectedSymbol) {
                throw new InvalidArgumentException('Impact affected symbols must contain AffectedSymbol values.');
            }
        }

        foreach ($evidence as $record) {
            if (! $record instanceof EvidenceRecord) {
                throw new InvalidArgumentException('Impact evidence must contain EvidenceRecord values.');
            }
        }

        foreach ($selectedTests as $test) {
            if (! is_array($test)
                || ! is_string($test['test_node_id'] ?? null)
                || ! is_int($test['rank'] ?? null)
                || ! is_string($test['reason'] ?? null)
                || ! is_array($test['evidence_ids'] ?? null)) {
                throw new InvalidArgumentException('Selected tests require node ID, rank, reason, and evidence IDs.');
            }
        }

        usort($changedSymbols, static fn (ChangedSymbol $left, ChangedSymbol $right): int => [
            $left->newNodeId?->value ?? $left->oldNodeId?->value,
            $left->changeType->value,
        ] <=> [
            $right->newNodeId?->value ?? $right->oldNodeId?->value,
            $right->changeType->value,
        ]);
        usort($affectedSymbols, static fn (AffectedSymbol $left, AffectedSymbol $right): int => $left->nodeId->value <=> $right->nodeId->value);
        usort($evidence, static fn (EvidenceRecord $left, EvidenceRecord $right): int => $left->id() <=> $right->id());
        usort($selectedTests, static fn (array $left, array $right): int => [
            $left['rank'],
            $left['test_node_id'],
        ] <=> [
            $right['rank'],
            $right['test_node_id'],
        ]);

        $this->changedSymbols = array_values($changedSymbols);
        $this->affectedSymbols = array_values($affectedSymbols);
        $this->evidence = array_values($evidence);
        $this->selectedTests = array_values($selectedTests);
    }
}
