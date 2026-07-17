<?php

namespace DNDark\LogicMap\Domain\Impact;

use DNDark\LogicMap\Support\RelativePath;
use InvalidArgumentException;

final readonly class ChangedFile
{
    public ?string $oldPath;

    public ?string $newPath;

    /** @var list<ChangedHunk> */
    public array $hunks;

    public function __construct(
        public ChangeType $changeType,
        ?string $oldPath,
        ?string $newPath,
        array $hunks,
    ) {
        if ($oldPath === null && $newPath === null) {
            throw new InvalidArgumentException('Changed files require an old or new path.');
        }

        $this->oldPath = $oldPath === null ? null : RelativePath::normalize($oldPath);
        $this->newPath = $newPath === null ? null : RelativePath::normalize($newPath);

        foreach ($hunks as $hunk) {
            if (! $hunk instanceof ChangedHunk) {
                throw new InvalidArgumentException('Changed files require ChangedHunk values.');
            }
        }

        usort($hunks, static fn (ChangedHunk $left, ChangedHunk $right): int =>
            $left->toTuple() <=> $right->toTuple());
        $this->hunks = array_values($hunks);
    }
}
