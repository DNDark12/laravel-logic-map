<?php

namespace DNDark\LogicMap\Analysis\Laravel;

final class ColumnMutationResolver
{
    public function resolve(string $operation, array $arguments): ?array
    {
        if (in_array($operation, ['increment', 'decrement'], true)) {
            return isset($arguments[0]) && is_string($arguments[0]) ? [$arguments[0]] : null;
        }

        if (! in_array($operation, [
            'create', 'update', 'insert', 'insertGetId', 'upsert', 'firstOrCreate', 'updateOrCreate',
        ], true)) {
            return [];
        }

        $array = $arguments[0]['array'] ?? null;

        if (! is_array($array)) {
            return null;
        }

        $columns = [];

        foreach ($array as $item) {
            if (is_string($item['key'] ?? null)) {
                $columns[] = $item['key'];
            }
        }

        $columns = array_values(array_unique($columns));
        sort($columns, SORT_STRING);

        return $columns === [] ? null : $columns;
    }
}
