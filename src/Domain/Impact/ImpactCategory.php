<?php

namespace DNDark\LogicMap\Domain\Impact;

enum ImpactCategory: string
{
    case HardDependency = 'hard_dependency';
    case Workflow = 'workflow';
    case Async = 'async';
    case SharedState = 'shared_state';
    case ExternalContract = 'external_contract';
    case Module = 'module';
    case TestScope = 'test_scope';
    case Uncertainty = 'uncertainty';
}
