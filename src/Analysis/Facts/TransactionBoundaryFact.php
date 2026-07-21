<?php

namespace DNDark\LogicMap\Analysis\Facts;

use DNDark\LogicMap\Support\RelativePath;
use InvalidArgumentException;

final readonly class TransactionBoundaryFact
{
    public string $file;

    public string $boundaryId;

    public function __construct(
        string $file,
        public int $startLine,
        public int $endLine,
        public string $enclosingSymbol,
        public string $operation,
        public ?int $bodyStartLine,
        public ?int $bodyEndLine,
        public array $controlContexts = [],
    ) {
        if (! in_array($operation, ['closure', 'begin', 'commit', 'rollback'], true)) {
            throw new InvalidArgumentException('Unsupported transaction boundary operation.');
        }

        if (($bodyStartLine === null) !== ($bodyEndLine === null)) {
            throw new InvalidArgumentException('Transaction body spans require both bounds.');
        }

        $this->file = RelativePath::normalize($file);
        $context = new ControlContext(
            ControlKind::Transaction,
            $operation === 'closure' ? 'DB::transaction' : 'DB::'.$operation,
            $operation === 'closure' ? 'body' : $operation,
            $bodyStartLine ?? $startLine,
            $bodyEndLine ?? $endLine,
        );
        $this->boundaryId = $context->boundaryId;
    }

    public function toArray(): array
    {
        return [
            'file' => $this->file,
            'boundary_id' => $this->boundaryId,
            'start_line' => $this->startLine,
            'end_line' => $this->endLine,
            'enclosing_symbol' => $this->enclosingSymbol,
            'operation' => $this->operation,
            'body_start_line' => $this->bodyStartLine,
            'body_end_line' => $this->bodyEndLine,
            'control_contexts' => $this->controlContexts,
        ];
    }
}
