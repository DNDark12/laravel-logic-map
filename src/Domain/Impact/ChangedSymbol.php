<?php

namespace DNDark\LogicMap\Domain\Impact;

use DNDark\LogicMap\Domain\Graph\EvidenceRecord;
use DNDark\LogicMap\Domain\Graph\NodeId;
use InvalidArgumentException;

final readonly class ChangedSymbol
{
    public function __construct(
        public ChangeType $changeType,
        public ?NodeId $oldNodeId,
        public ?NodeId $newNodeId,
        public ?string $oldPath,
        public ?string $newPath,
        public ?int $oldStartLine,
        public ?int $oldEndLine,
        public ?int $newStartLine,
        public ?int $newEndLine,
        public EvidenceRecord $evidence,
        public array $attributes = [],
    ) {
        if ($oldNodeId === null && $newNodeId === null) {
            throw new InvalidArgumentException('Changed symbols require an old or new canonical node ID.');
        }
    }
}
