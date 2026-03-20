<?php

namespace dndark\LogicMap\Support\Traversal;

use dndark\LogicMap\Domain\Enums\EdgeType;
use dndark\LogicMap\Domain\Enums\NodeKind;

/**
 * Frozen traversal vocabulary for path-based read models.
 *
 * This class defines the canonical definitions of edge traversal order,
 * async boundary detection, and persistence node classification
 * used by both Impact and Trace read models.
 */
class TraversalPolicy
{
    /**
     * Edge traversal priority (low index = higher priority).
     * Must correspond to actual EdgeType enum values.
     *
     * @var array<string, int>
     */
    public const EDGE_PRIORITY = [
        EdgeType::ROUTE_TO_CONTROLLER->value => 0,
        EdgeType::CALL->value                => 1,
        EdgeType::DISPATCH->value            => 2,
        EdgeType::LISTEN->value              => 3,
        EdgeType::USE->value                 => 4,
    ];

    /**
     * Edge types that represent async workflow boundaries.
     * Crossing one of these starts a new segment in trace mode.
     *
     * @var string[]
     */
    public const ASYNC_BOUNDARY_TYPES = [
        EdgeType::DISPATCH->value,
        EdgeType::LISTEN->value,
    ];

    /**
     * NodeKind values that represent persistence touchpoints.
     *
     * @var string[]
     */
    public const PERSISTENCE_KINDS = [
        NodeKind::MODEL->value,
        NodeKind::REPOSITORY->value,
    ];

    /**
     * Risk levels considered "high" for critical-touch classification.
     *
     * @var string[]
     */
    public const HIGH_RISK_LEVELS = ['high', 'critical'];

    /**
     * Coverage levels considered "low" for test-focus classification.
     *
     * @var string[]
     */
    public const LOW_COVERAGE_LEVELS = ['low', 'unknown', null];

    /**
     * Return the priority integer for a given edge type value.
     * Unknown types get lowest priority (9999).
     */
    public static function edgePriority(string $edgeTypeValue): int
    {
        return self::EDGE_PRIORITY[$edgeTypeValue] ?? 9999;
    }

    /**
     * Return true if the given edge type value crosses an async boundary.
     */
    public static function isAsyncBoundary(string $edgeTypeValue): bool
    {
        return in_array($edgeTypeValue, self::ASYNC_BOUNDARY_TYPES, true);
    }

    /**
     * Return true if the given node kind value is a persistence node.
     */
    public static function isPersistenceKind(string $nodeKindValue): bool
    {
        return in_array($nodeKindValue, self::PERSISTENCE_KINDS, true);
    }

    /**
     * Return true if the given risk level is considered high.
     */
    public static function isHighRisk(?string $risk): bool
    {
        return in_array($risk, self::HIGH_RISK_LEVELS, true);
    }

    /**
     * Return true if the given coverage level is considered low.
     */
    public static function isLowCoverage(?string $coverage): bool
    {
        return in_array($coverage, self::LOW_COVERAGE_LEVELS, true);
    }
}
