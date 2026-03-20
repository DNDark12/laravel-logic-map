<?php

namespace dndark\LogicMap\Support;

class RiskCopyFormatter
{
    /**
     * Returns a human-readable risk explanation rather than just a bare risk level.
     *
     * @param  string|null $risk      e.g. "high", "medium", "low", "critical"
     * @param  string|null $kind      node kind for context
     * @param  int|null    $score     numeric risk score
     */
    public static function format(?string $risk, ?string $kind = null, ?int $score = null): string
    {
        $risk = strtolower($risk ?? 'none');
        $kindLabel = $kind ? HumanLabelResolver::formatKind($kind) : 'component';

        return match ($risk) {
            'critical' => "Critical risk — changes here may cascade through multiple {$kindLabel} dependencies and likely require immediate attention.",
            'high'     => "High risk — this {$kindLabel} touches multiple downstream workflow steps. Recommend full review and test coverage.",
            'medium'   => "Medium risk — part of a core analysis setup flow. Changes here may affect dependent components in limited paths.",
            'low'      => "Low risk — helper used in a limited downstream path. Changes likely have a contained blast radius.",
            'healthy'  => "Healthy — well-isolated component with limited dependencies. Changes are unlikely to cascade.",
            default    => "Risk level undetermined — review may still be warranted depending on modification scope.",
        };
    }

    /**
     * Returns a short badge-friendly label (no explanation).
     */
    public static function label(?string $risk): string
    {
        return match (strtolower($risk ?? 'none')) {
            'critical' => 'Critical Risk',
            'high'     => 'High Risk',
            'medium'   => 'Medium Risk',
            'low'      => 'Low Risk',
            'healthy'  => 'Healthy',
            default    => 'Unknown Risk',
        };
    }
}
