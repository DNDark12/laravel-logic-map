<?php

namespace DNDark\LogicMap\Domain\Graph;

enum EdgeType: string
{
    case Contains = 'contains';
    case Defines = 'defines';
    case Extends = 'extends';
    case Implements = 'implements';
    case UsesTrait = 'uses_trait';
    case Calls = 'calls';
    case Instantiates = 'instantiates';
    case Injects = 'injects';
    case BindsTo = 'binds_to';
    case ResolvesTo = 'resolves_to';
    case HandlesRoute = 'handles_route';
    case AppliesMiddleware = 'applies_middleware';
    case ValidatesWith = 'validates_with';
    case AuthorizesWith = 'authorizes_with';
    case Dispatches = 'dispatches';
    case ListensTo = 'listens_to';
    case Queues = 'queues';
    case Schedules = 'schedules';
    case ReadsModel = 'reads_model';
    case WritesModel = 'writes_model';
    case ReadsTable = 'reads_table';
    case WritesTable = 'writes_table';
    case ReadsColumn = 'reads_column';
    case WritesColumn = 'writes_column';
    case ReadsCache = 'reads_cache';
    case WritesCache = 'writes_cache';
    case InvalidatesCache = 'invalidates_cache';
    case ReadsConfig = 'reads_config';
    case ReadsStorage = 'reads_storage';
    case WritesStorage = 'writes_storage';
    case RendersView = 'renders_view';
    case CallsExternal = 'calls_external';
    case SendsNotification = 'sends_notification';
    case SendsMail = 'sends_mail';
    case MemberOfModule = 'member_of_module';
    case StepInProcess = 'step_in_process';
    case BranchesTo = 'branches_to';
    case CoveredByTest = 'covered_by_test';
}
