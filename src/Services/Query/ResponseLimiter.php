<?php

namespace DNDark\LogicMap\Services\Query;

use InvalidArgumentException;

final readonly class ResponseLimiter
{
    private const TRIMMABLE_KEYS = [
        'evidence', 'affected_symbols', 'uncertainty', 'tests', 'external_contracts',
        'shared_resources', 'workflows', 'modules', 'transitions', 'steps', 'members',
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
                    if ($this->trimKey($data, $key)) {
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

    private function trimKey(array &$value, string $key): bool
    {
        if (isset($value[$key]) && is_array($value[$key])) {
            if (array_is_list($value[$key]) && $value[$key] !== []) {
                array_pop($value[$key]);

                return true;
            }

            foreach ($value[$key] as &$group) {
                if (is_array($group) && array_is_list($group) && $group !== []) {
                    array_pop($group);

                    return true;
                }
            }
            unset($group);
        }

        foreach ($value as &$child) {
            if (is_array($child) && $this->trimKey($child, $key)) {
                return true;
            }
        }
        unset($child);

        return false;
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
