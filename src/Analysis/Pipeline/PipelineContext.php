<?php

namespace DNDark\LogicMap\Analysis\Pipeline;

use DNDark\LogicMap\Domain\Graph\KnowledgeGraph;

final readonly class PipelineContext
{
    public function __construct(
        public KnowledgeGraph $graph,
        public array $inputs = [],
    ) {
    }

    public function input(string $key, mixed $default = null): mixed
    {
        return $this->inputs[$key] ?? $default;
    }
}
