<?php

namespace DNDark\LogicMap\Analysis\Runtime;

final readonly class RuntimeSanitizer
{
    private const ALLOWED_KEYS = [
        'code', 'method', 'route_template', 'url_template', 'status', 'duration_ms',
        'exception_class', 'table_names', 'job_class', 'event_class', 'cache_key',
        'success', 'message',
    ];

    public function __construct(private int $maxStringLength = 500)
    {
    }

    public function sanitize(array $attributes): array
    {
        $result = [];

        foreach (self::ALLOWED_KEYS as $key) {
            if (! array_key_exists($key, $attributes)) {
                continue;
            }

            $value = $this->value($key, $attributes[$key]);

            if ($value !== null) {
                $result[$key] = $value;
            }
        }

        ksort($result, SORT_STRING);

        return $result;
    }

    private function value(string $key, mixed $value): mixed
    {
        return match ($key) {
            'status' => is_int($value) && $value >= 100 && $value <= 599 ? $value : null,
            'duration_ms' => is_numeric($value) && (float) $value >= 0 ? round((float) $value, 3) : null,
            'success' => is_bool($value) ? $value : null,
            'method' => is_string($value) && preg_match('/^[A-Za-z]+$/', $value) === 1
                ? strtoupper(substr($value, 0, 16)) : null,
            'route_template', 'url_template' => is_string($value)
                ? $this->metadata((string) preg_replace('/[?#].*$/', '', $value)) : null,
            'table_names' => is_array($value) ? $this->tableNames($value) : null,
            'exception_class', 'job_class', 'event_class' => is_string($value)
                && preg_match('/^[A-Za-z_][A-Za-z0-9_\\\\]*$/', $value) === 1
                    ? $this->metadata($value) : null,
            'code' => is_string($value) && preg_match('/^[a-z0-9_:-]+$/', $value) === 1
                ? $this->metadata($value) : null,
            'cache_key' => is_string($value) ? $this->redact($this->metadata($value)) : null,
            'message' => is_string($value) ? $this->redact($this->bounded($value)) : null,
            default => null,
        };
    }

    private function tableNames(array $values): array
    {
        $tables = [];

        foreach ($values as $value) {
            if (is_string($value) && preg_match('/^[A-Za-z_][A-Za-z0-9_.]*$/', $value) === 1) {
                $tables[$this->bounded($value)] = true;
            }
        }

        $tables = array_keys($tables);
        sort($tables, SORT_STRING);

        return array_slice($tables, 0, 100);
    }

    private function redact(string $value): string
    {
        $value = preg_replace('/\bBearer\s+[A-Za-z0-9._~+\/=:-]+/i', 'Bearer [REDACTED]', $value) ?? $value;

        return preg_replace(
            '/\b(authorization|proxy-authorization|cookie|set-cookie|password|secret|token|api[_-]?key|access[_-]?key)\b\s*[:=]\s*[^\s,;]+/i',
            '$1=[REDACTED]',
            $value,
        ) ?? $value;
    }

    private function bounded(string $value): string
    {
        return substr($value, 0, max(1, $this->maxStringLength));
    }

    private function metadata(string $value): string
    {
        return substr($value, 0, 2048);
    }
}
