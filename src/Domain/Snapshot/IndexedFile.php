<?php

namespace DNDark\LogicMap\Domain\Snapshot;

use DNDark\LogicMap\Support\RelativePath;
use InvalidArgumentException;

final readonly class IndexedFile
{
    public string $path;

    public function __construct(
        string $path,
        public string $contentHash,
        public int $size,
    ) {
        $this->path = RelativePath::normalize($path);

        if (preg_match('/^[a-f0-9]{64}$/', $contentHash) !== 1) {
            throw new InvalidArgumentException('Indexed file content hashes must be lowercase SHA-256 values.');
        }

        if ($size < 0) {
            throw new InvalidArgumentException('Indexed file sizes must be non-negative.');
        }
    }

    public function toArray(): array
    {
        return [
            'path' => $this->path,
            'content_hash' => $this->contentHash,
            'size' => $this->size,
        ];
    }
}
