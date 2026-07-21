<?php

namespace DNDark\LogicMap\Analysis\Facts;

use DNDark\LogicMap\Support\RelativePath;
use InvalidArgumentException;

final readonly class BranchConditionFact
{
    public string $file;

    public function __construct(
        string $file,
        public int $startLine,
        public int $endLine,
        public string $enclosingSymbol,
        public string $expression,
        public string $branch,
        public array $controlContexts = [],
    ) {
        if ($startLine < 1 || $endLine < $startLine || trim($enclosingSymbol) === '' || trim($expression) === '') {
            throw new InvalidArgumentException('Branch conditions require a symbol, expression, and valid span.');
        }

        if (! in_array($branch, ['truthy', 'falsy'], true)) {
            throw new InvalidArgumentException('Branch condition side must be truthy or falsy.');
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
            'expression' => $this->expression,
            'branch' => $this->branch,
            'control_contexts' => $this->controlContexts,
        ];
    }
}
