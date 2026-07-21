<?php

namespace DNDark\LogicMap\Domain\Impact;

use InvalidArgumentException;

final readonly class ChangedHunk
{
    public function __construct(
        public int $oldStart,
        public int $oldCount,
        public int $newStart,
        public int $newCount,
    ) {
        if ($oldStart < 0 || $oldCount < 0 || $newStart < 0 || $newCount < 0) {
            throw new InvalidArgumentException('Changed hunk coordinates cannot be negative.');
        }
    }

    public function oldEnd(): int
    {
        return $this->oldCount === 0 ? $this->oldStart : $this->oldStart + $this->oldCount - 1;
    }

    public function newEnd(): int
    {
        return $this->newCount === 0 ? $this->newStart : $this->newStart + $this->newCount - 1;
    }

    public function toTuple(): array
    {
        return [$this->oldStart, $this->oldCount, $this->newStart, $this->newCount];
    }
}
