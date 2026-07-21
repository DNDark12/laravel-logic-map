<?php

namespace DNDark\LogicMap\Domain\Workflow;

enum ExecutionBoundary: string
{
    case Sync = 'sync';
    case Async = 'async';
    case AfterResponse = 'after_response';
    case AfterCommit = 'after_commit';
    case Scheduled = 'scheduled';
}
