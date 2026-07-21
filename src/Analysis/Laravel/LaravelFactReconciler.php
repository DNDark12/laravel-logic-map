<?php

namespace DNDark\LogicMap\Analysis\Laravel;

use DNDark\LogicMap\Analysis\Php\SymbolTable;
use DNDark\LogicMap\Domain\Graph\KnowledgeGraph;

final class LaravelFactReconciler
{
    public function reconcile(
        array $files,
        SymbolTable $symbols,
        array $bootFacts,
        KnowledgeGraph $graph,
    ): array {
        return (new LaravelSemanticAnalyzer())->analyze(
            $files,
            $symbols,
            $bootFacts,
            $graph,
        )['diagnostics'];
    }
}
