<?php

namespace dndark\LogicMap\Contracts;

use dndark\LogicMap\Domain\Graph;
use dndark\LogicMap\Domain\Violation;

interface ViolationAnalyzer
{
    /**
     * Analyze the graph and return any violations found.
     *
     * @return Violation[]
     */
    public function analyze(Graph $graph): array;

    /**
     * Get the unique name of this analyzer.
     */
    public function getName(): string;

    /**
     * Check if this analyzer is enabled via config.
     */
    public function isEnabled(): bool;
}
