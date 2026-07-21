<?php

namespace DNDark\LogicMap\Domain\Snapshot;

enum DiagnosticCode: string
{
    case UnresolvedReceiver = 'unresolved_receiver';
    case AmbiguousTarget = 'ambiguous_target';
    case DynamicClassString = 'dynamic_class_string';
    case DynamicRouteAction = 'dynamic_route_action';
    case RouteRegistrationMismatch = 'route_registration_mismatch';
    case ClosureContainerBinding = 'closure_container_binding';
    case UnknownTable = 'unknown_table';
    case UnknownColumnSet = 'unknown_column_set';
    case UnparsedRawSql = 'unparsed_raw_sql';
    case UnknownCacheKey = 'unknown_cache_key';
    case UnsupportedMacro = 'unsupported_macro';
    case BootInspectionFailed = 'boot_inspection_failed';
    case RuntimeTraceGap = 'runtime_trace_gap';
    case QueryTruncated = 'query_truncated';
    case ParseError = 'parse_error';
    case DuplicateSymbol = 'duplicate_symbol';
    case GitObjectUnreadable = 'git_object_unreadable';
    case BaseParseFailed = 'base_parse_failed';
    case BaseSymbolAmbiguous = 'base_symbol_ambiguous';
    case UnresolvedTarget = 'unresolved_target';
}
