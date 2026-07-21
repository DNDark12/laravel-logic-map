<?php

namespace DNDark\LogicMap\Services\Indexing;

use DNDark\LogicMap\Contracts\SemanticGraphRepository;

final readonly class ClearLogicMapService
{
    public function __construct(private SemanticGraphRepository $repository) {}

    public function clear(): void
    {
        $this->repository->clear();
    }
}
