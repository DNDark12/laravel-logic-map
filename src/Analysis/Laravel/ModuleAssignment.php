<?php

namespace DNDark\LogicMap\Analysis\Laravel;

use DNDark\LogicMap\Domain\Graph\NodeId;
use InvalidArgumentException;

final readonly class ModuleAssignment
{
    public function __construct(
        public NodeId $symbol,
        public string $module,
        public string $reason,
    ) {
        if (trim($module) === '' || trim($reason) === '') {
            throw new InvalidArgumentException('Module assignments require a module and reason.');
        }
    }
}
