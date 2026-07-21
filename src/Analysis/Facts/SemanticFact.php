<?php

namespace DNDark\LogicMap\Analysis\Facts;

use DNDark\LogicMap\Support\RelativePath;
use InvalidArgumentException;

final readonly class SemanticFact
{
    public string $file;

    public function __construct(
        public string $kind,
        string $file,
        public int $startLine,
        public int $endLine,
        public array $attributes = [],
        public array $controlContexts = [],
    ) {
        if ($kind === '' || $startLine < 1 || $endLine < $startLine) {
            throw new InvalidArgumentException('Semantic facts require a kind and valid source span.');
        }

        self::assertSerializableData($attributes);
        self::assertSerializableData($controlContexts);
        $this->file = RelativePath::normalize($file);
    }

    public function toArray(): array
    {
        return [
            'kind' => $this->kind,
            'file' => $this->file,
            'start_line' => $this->startLine,
            'end_line' => $this->endLine,
            'attributes' => $this->attributes,
            'control_contexts' => $this->controlContexts,
        ];
    }

    private static function assertSerializableData(mixed $value): void
    {
        if (is_array($value)) {
            foreach ($value as $item) {
                self::assertSerializableData($item);
            }

            return;
        }

        if (! is_null($value) && ! is_scalar($value)) {
            throw new InvalidArgumentException('Semantic fact data may contain only scalars and arrays.');
        }
    }
}
