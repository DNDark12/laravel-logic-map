<?php

namespace DNDark\LogicMap\Domain\Graph;

use DNDark\LogicMap\Support\RelativePath;
use InvalidArgumentException;

final readonly class SourceLocation
{
    public string $file;

    public function __construct(
        string $file,
        public int $startLine,
        public int $endLine,
    ) {
        $this->file = RelativePath::normalize($file);

        if ($startLine < 1 || $endLine < $startLine) {
            throw new InvalidArgumentException('Source locations must have a valid line range.');
        }
    }

    public function toArray(): array
    {
        return [
            'file' => $this->file,
            'start_line' => $this->startLine,
            'end_line' => $this->endLine,
        ];
    }
}
