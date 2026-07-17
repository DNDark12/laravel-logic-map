<?php

namespace DNDark\LogicMap\Analysis\Facts;

use DNDark\LogicMap\Domain\Graph\NodeId;
use DNDark\LogicMap\Support\RelativePath;
use InvalidArgumentException;

final readonly class InheritanceFact
{
    public string $file;

    public function __construct(
        string $file,
        public int $startLine,
        public int $endLine,
        public NodeId $sourceSymbolId,
        public string $relation,
        public string $targetQualifiedName,
    ) {
        $this->file = RelativePath::normalize($file);

        if (
            $startLine < 1
            || $endLine < $startLine
            || ! in_array($relation, ['extends', 'implements', 'uses_trait'], true)
            || trim($targetQualifiedName) === ''
        ) {
            throw new InvalidArgumentException('Inheritance facts require a valid relation, target, and source span.');
        }
    }

    public function toArray(): array
    {
        return [
            'file' => $this->file,
            'start_line' => $this->startLine,
            'end_line' => $this->endLine,
            'source_symbol_id' => $this->sourceSymbolId->value,
            'relation' => $this->relation,
            'target_qualified_name' => $this->targetQualifiedName,
        ];
    }
}
