<?php

namespace DNDark\LogicMap\Analysis\Runtime;

final class SqlTableObservationParser
{
    /** @return array{operation:string,table_names:list<string>}|null */
    public function parse(string $sql): ?array
    {
        $patterns = [
            'select' => '/^\s*select\b[\s\S]*?\bfrom\s+([`"\[]?[A-Za-z_][A-Za-z0-9_.]*[`"\]]?)/i',
            'insert' => '/^\s*insert\s+into\s+([`"\[]?[A-Za-z_][A-Za-z0-9_.]*[`"\]]?)/i',
            'update' => '/^\s*update\s+([`"\[]?[A-Za-z_][A-Za-z0-9_.]*[`"\]]?)/i',
            'delete' => '/^\s*delete\s+from\s+([`"\[]?[A-Za-z_][A-Za-z0-9_.]*[`"\]]?)/i',
        ];

        foreach ($patterns as $operation => $pattern) {
            if (preg_match($pattern, $sql, $matches) !== 1) {
                continue;
            }

            $table = trim($matches[1], "`\"[]");

            return ['operation' => $operation, 'table_names' => [$table]];
        }

        return null;
    }
}
