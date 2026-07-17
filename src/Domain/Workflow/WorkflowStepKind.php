<?php

namespace DNDark\LogicMap\Domain\Workflow;

enum WorkflowStepKind: string
{
    case Entry = 'entry';
    case Symbol = 'symbol';
    case Decision = 'decision';
    case Effect = 'effect';
    case AsyncBoundary = 'async_boundary';
    case Terminal = 'terminal';
    case Cycle = 'cycle';
    case Gap = 'gap';
}
