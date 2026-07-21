<?php

namespace DNDark\LogicMap\Services\Workflow;

use DNDark\LogicMap\Domain\Workflow\TransactionSegment;

final class TransactionSegmentBuilder
{
    public function build(array $memberships, array $stepEvidence): array
    {
        $segments = [];

        foreach ($memberships as $boundaryId => $stepIds) {
            $stepIds = array_values(array_unique($stepIds));
            $evidence = [];

            foreach ($stepIds as $stepId) {
                $evidence = [...$evidence, ...($stepEvidence[$stepId] ?? [])];
            }

            $evidence = array_values(array_unique($evidence));

            if ($stepIds !== [] && $evidence !== []) {
                $segments[] = new TransactionSegment($boundaryId, $stepIds, $evidence);
            }
        }

        usort($segments, static fn (TransactionSegment $left, TransactionSegment $right): int => $left->id <=> $right->id);

        return $segments;
    }
}
