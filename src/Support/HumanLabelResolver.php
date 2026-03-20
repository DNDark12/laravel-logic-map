<?php

namespace dndark\LogicMap\Support;

class HumanLabelResolver
{
    /**
     * Converts a raw Node Kind into a presentable badge label.
     */
    public static function formatKind(string $kind): string
    {
        return ucwords(str_replace('_', ' ', $kind));
    }

    /**
     * Converts risk level to a readable text representation.
     */
    public static function formatRisk(?string $risk): string
    {
        return match (strtolower($risk ?? '')) {
            'critical' => 'Critical Risk',
            'high'     => 'High Risk',
            'medium'   => 'Medium Risk',
            'low'      => 'Low Risk',
            default    => 'Healthy',
        };
    }

    /**
     * Converts a raw coverage level to a readable text representation.
     */
    public static function formatCoverage(?string $coverage): string
    {
        return match (strtolower($coverage ?? '')) {
            'high'    => 'High Coverage',
            'medium'  => 'Medium Coverage',
            'low'     => 'Low Coverage',
            default   => 'Unknown Coverage',
        };
    }
}
