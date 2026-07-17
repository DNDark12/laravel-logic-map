<?php

namespace DNDark\LogicMap\Support;

final class TemplateNormalizer
{
    public function __construct(private readonly int $maxLength = 500)
    {
    }

    public function normalize(mixed $structure): ?string
    {
        $value = $this->render($structure);

        if ($value === null || trim($value) === '') {
            return null;
        }

        $value = preg_replace('/[\x00-\x1F\x7F]+/', '', $value) ?? $value;
        $value = preg_replace('#^(https?://)(?:[^/@]+@)(.+)$#i', '$1$2', $value) ?? $value;

        if (preg_match('#^https?://#i', $value) === 1) {
            $value = preg_split('/[?#]/', $value, 2)[0];
        }

        return substr($value, 0, $this->maxLength);
    }

    private function render(mixed $structure): ?string
    {
        if (! is_array($structure)) {
            return null;
        }

        if (is_string($structure['literal'] ?? null)) {
            return $structure['literal'];
        }

        if (is_string($structure['placeholder'] ?? null)) {
            return '{'.$this->placeholder($structure['placeholder']).'}';
        }

        if (is_string($structure['config'] ?? null)) {
            return '{config:'.$structure['config'].'}';
        }

        if (is_array($structure['concat'] ?? null)) {
            $parts = array_map(fn (mixed $part): string => $this->render($part) ?? '', $structure['concat']);

            return implode('', $parts);
        }

        return null;
    }

    private function placeholder(string $value): string
    {
        $value = trim($value, " \t\n\r\0\x0B\$\\{}");
        $value = preg_replace('/[^A-Za-z0-9_]+/', '_', $value) ?? $value;
        $value = trim(strtolower($value), '_');

        return $value === '' ? 'value' : $value;
    }
}
