<?php

namespace DNDark\LogicMap\Analysis\Laravel\Boot;

use InvalidArgumentException;

final readonly class BootFact
{
    public function __construct(
        public string $kind,
        public string $collector,
        public array $attributes,
    ) {
        if (trim($kind) === '' || trim($collector) === '') {
            throw new InvalidArgumentException('Boot facts require a kind and collector.');
        }

        self::assertSerializableData($attributes);
    }

    public function toArray(): array
    {
        return [
            'kind' => $this->kind,
            'collector' => $this->collector,
            'attributes' => $this->attributes,
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
            throw new InvalidArgumentException('Boot fact data may contain only scalars and arrays.');
        }
    }
}
