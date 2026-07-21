<?php

namespace DNDark\LogicMap\Analysis\Pipeline;

interface AnalysisPhase
{
    public function name(): string;

    /** @return list<string> */
    public function dependencies(): array;

    /** @param array<string, PhaseResult> $dependencies */
    public function execute(PipelineContext $context, array $dependencies): PhaseResult;
}
