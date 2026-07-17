<?php

namespace DNDark\LogicMap\Analysis\Pipeline;

use RuntimeException;
use Throwable;

final class AnalysisPhaseFailed extends RuntimeException
{
    public function __construct(
        public readonly string $phaseName,
        Throwable $previous,
    ) {
        parent::__construct(
            "Analysis phase {$phaseName} failed: {$previous->getMessage()}",
            0,
            $previous,
        );
    }
}
