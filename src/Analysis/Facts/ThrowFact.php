<?php

namespace DNDark\LogicMap\Analysis\Facts;

use DNDark\LogicMap\Support\RelativePath;

final readonly class ThrowFact
{
    public string $file;

    public string $boundaryId;

    public function __construct(
        string $file,
        public int $startLine,
        public int $endLine,
        public string $enclosingSymbol,
        public ?string $exceptionClass,
        public string $expression,
        public ?BranchConditionFact $guard,
        public array $controlContexts = [],
    ) {
        $this->file = RelativePath::normalize($file);
        $this->boundaryId = 'terminal:'.hash('sha256', implode("\0", [
            'throw', $this->file, (string) $startLine, (string) $endLine, $exceptionClass ?? '',
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
            'exception_class' => $this->exceptionClass,
            'expression' => $this->expression,
            'guard' => $this->guard?->toArray(),
            'control_contexts' => $this->controlContexts,
        ];
    }
}
