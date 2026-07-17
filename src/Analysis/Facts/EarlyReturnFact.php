<?php

namespace DNDark\LogicMap\Analysis\Facts;

use DNDark\LogicMap\Support\RelativePath;

final readonly class EarlyReturnFact
{
    public string $file;

    public string $boundaryId;

    public function __construct(
        string $file,
        public int $startLine,
        public int $endLine,
        public string $enclosingSymbol,
        public ?string $expression,
        public ?BranchConditionFact $guard,
        public array $controlContexts = [],
    ) {
        $this->file = RelativePath::normalize($file);
        $this->boundaryId = 'terminal:'.hash('sha256', implode("\0", [
            'early_return', $this->file, (string) $startLine, (string) $endLine, $expression ?? '',
        ]));
    }

    public function toArray(): array
    {
        return [
            'file' => $this->file,
            'boundary_id' => $this->boundaryId,
            'start_line' => $this->startLine,
            'end_line' => $this->endLine,
            'enclosing_symbol' => $this->enclosingSymbol,
            'expression' => $this->expression,
            'guard' => $this->guard?->toArray(),
            'control_contexts' => $this->controlContexts,
        ];
    }
}
