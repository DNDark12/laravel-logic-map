<?php

namespace DNDark\LogicMap\Analysis\Facts;

use DNDark\LogicMap\Domain\Graph\NodeId;
use DNDark\LogicMap\Support\RelativePath;
use InvalidArgumentException;

final readonly class CallSiteFact
{
    public string $file;

    public function __construct(
        string $file,
        public int $startLine,
        public int $endLine,
        public NodeId $enclosingSymbolId,
        public string $callKind,
        public ?string $receiverExpression,
        public ?string $receiverType,
        public string $targetName,
        public array $arguments,
        public string $normalizedExpression,
        public array $attributes = [],
        public array $controlContexts = [],
    ) {
        $this->file = RelativePath::normalize($file);

        if (
            $startLine < 1
            || $endLine < $startLine
            || trim($callKind) === ''
            || trim($targetName) === ''
            || trim($normalizedExpression) === ''
        ) {
            throw new InvalidArgumentException('Call-site facts require a kind, target, expression, and valid span.');
        }
    }

    public function toArray(): array
    {
        return [
            'file' => $this->file,
            'start_line' => $this->startLine,
            'end_line' => $this->endLine,
            'enclosing_symbol_id' => $this->enclosingSymbolId->value,
            'call_kind' => $this->callKind,
            'receiver_expression' => $this->receiverExpression,
            'receiver_type' => $this->receiverType,
            'target_name' => $this->targetName,
            'arguments' => $this->arguments,
            'normalized_expression' => $this->normalizedExpression,
            'attributes' => $this->attributes,
            'control_contexts' => $this->controlContexts,
        ];
    }
}
