<?php

namespace dndark\LogicMap\Support;

class ReasonCopyFormatter
{
    /**
     * Transforms a raw why_included string into a PM-friendly version,
     * removing traversal jargon.
     */
    public static function format(?string $raw, ?string $kind = null): string
    {
        if (empty($raw)) {
            return 'Part of the affected flow.';
        }

        // Replace known technical phrases
        $replacements = [
            'Direct Immediate Neighbor'   => 'Directly affected by this component',
            'direct immediate neighbor'   => 'directly affected by this component',
            'Graph child'                 => 'Part of the main workflow',
            'Depth-based adjacency only'  => 'Connected within the impacted area',
            'depth-based adjacency only'  => 'connected within the impacted area',
            'Direct downstream'           => 'High-risk downstream dependency',
            'direct downstream'           => 'high-risk downstream dependency',
        ];

        $formatted = str_replace(
            array_keys($replacements),
            array_values($replacements),
            $raw
        );

        // Add period if not already there
        $formatted = rtrim($formatted, '.');
        return $formatted . '.';
    }
}
