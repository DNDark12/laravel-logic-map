<?php

namespace DNDark\LogicMap\Analysis\Facts;

use DNDark\LogicMap\Domain\Graph\Certainty;
use DNDark\LogicMap\Support\RelativePath;
use InvalidArgumentException;

final readonly class DataEffectFact
{
    public string $file;

    public function __construct(
        string $file,
        public int $startLine,
        public int $endLine,
        public string $enclosingSymbol,
        public string $effect,
        public string $resourceType,
        public string $resource,
        public Certainty $certainty,
        public array $attributes = [],
        public array $controlContexts = [],
    ) {
        if ($startLine < 1 || $endLine < $startLine || trim($enclosingSymbol) === '' || trim($resource) === '') {
            throw new InvalidArgumentException('Data effects require a symbol, resource, and valid span.');
        }

        $this->file = RelativePath::normalize($file);
    }

    public function toArray(): array
    {
        return [
            'file' => $this->file,
            'start_line' => $this->startLine,
            'end_line' => $this->endLine,
            'enclosing_symbol' => $this->enclosingSymbol,
            'effect' => $this->effect,
            'resource_type' => $this->resourceType,
            'resource' => $this->resource,
            'certainty' => $this->certainty->value,
            'attributes' => $this->attributes,
            'control_contexts' => $this->controlContexts,
        ];
    }
}
