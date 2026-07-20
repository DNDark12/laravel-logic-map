<?php

namespace DNDark\LogicMap\Services\Query;

use InvalidArgumentException;

final readonly class ResponseLimiter
{
    private const TRIMMABLE_KEYS = [
        'diagnostics', 'evidence', 'uncertainty', 'shared_resources', 'external_contracts',
        'inbound_relations', 'outbound_relations', 'affected_symbols', 'entry_workflows',
        'transitions', 'steps', 'members', 'tests', 'modules', 'workflows',
        'entrypoints', 'incoming', 'outgoing', 'effects', 'processes', 'results',
    ];

    public function __construct(private int $maxBytes)
    {
        if ($maxBytes < 1) {
            throw new InvalidArgumentException('Response byte limits must be positive.');
        }
    }

    public function limit(mixed $data, array $meta = []): array
    {
        if ($this->bytes($data, $meta) <= $this->maxBytes) {
            return ['data' => $data, 'meta' => $meta];
        }

        $meta = [
            ...$meta,
            'truncated' => true,
            'truncation_reason' => 'max_response_bytes',
            'max_response_bytes' => $this->maxBytes,
        ];

        if (is_array($data)) {
            while ($this->bytes($data, $meta) > $this->maxBytes) {
                $trimmed = false;

                foreach (self::TRIMMABLE_KEYS as $key) {
                    if ($this->trimKey($data, $data, $key, $meta)) {
                        $trimmed = true;
                        break;
                    }
                }

                if (! $trimmed) {
                    $data = ['truncated' => true];
                    break;
                }
            }
        } else {
            $data = null;
        }

        return ['data' => $data, 'meta' => $meta];
    }

    private function trimKey(array &$root, array &$value, string $key, array $meta): bool
    {
        if (isset($value[$key]) && is_array($value[$key])) {
            if (array_is_list($value[$key]) && $value[$key] !== []) {
                $this->trimList($root, $value[$key], $meta);

                return true;
            }

            foreach ($value[$key] as &$group) {
                if (is_array($group) && array_is_list($group) && $group !== []) {
                    $this->trimList($root, $group, $meta);

                    return true;
                }
            }
            unset($group);
        }

        foreach ($value as &$child) {
            if (is_array($child) && $this->trimKey($root, $child, $key, $meta)) {
                return true;
            }
        }
        unset($child);

        return false;
    }

    private function trimList(array &$root, array &$list, array $meta): void
    {
        $original = $list;
        $low = 0;
        $high = count($original) - 1;
        $best = null;

        while ($low <= $high) {
            $candidate = intdiv($low + $high, 2);
            $list = array_slice($original, 0, $candidate);

            if ($this->bytes($root, $meta) <= $this->maxBytes) {
                $best = $candidate;
                $low = $candidate + 1;
            } else {
                $high = $candidate - 1;
            }
        }

        $list = $best === null ? [] : array_slice($original, 0, $best);
    }

    private function bytes(mixed $data, array $meta): int
    {
        return strlen((string) json_encode([
            'ok' => true,
            'data' => $data,
            'message' => null,
            'errors' => null,
            'meta' => $meta === [] ? (object) [] : $meta,
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    }
}
