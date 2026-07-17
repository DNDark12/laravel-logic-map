<?php

namespace DNDark\LogicMap\Analysis\Facts;

use DNDark\LogicMap\Analysis\Php\ControlContextStack;
use InvalidArgumentException;
use LogicException;
use PhpParser\Node;
use PhpParser\NodeVisitorAbstract;

final class FactCollectingVisitor extends NodeVisitorAbstract
{
    /** @param list<FactCollector> $collectors */
    public function __construct(
        private readonly array $collectors,
        private readonly ?ControlContextStack $controlContexts = null,
    ) {
        $names = [];

        foreach ($collectors as $collector) {
            if (! $collector instanceof FactCollector) {
                throw new InvalidArgumentException('FactCollectingVisitor requires FactCollector values.');
            }

            if (trim($collector->name()) === '' || isset($names[$collector->name()])) {
                throw new InvalidArgumentException('Fact collector names must be non-empty and unique.');
            }

            $names[$collector->name()] = true;
        }
    }

    public function beforeTraverse(array $nodes): null
    {
        foreach ($this->collectors as $collector) {
            $this->rejectTraversalMutation($collector->beforeTraverse($nodes), $collector);
        }

        return null;
    }

    public function enterNode(Node $node): null
    {
        $this->controlContexts?->enterNode($node);

        foreach ($this->collectors as $collector) {
            $this->rejectTraversalMutation($collector->enterNode($node), $collector);
        }

        return null;
    }

    public function leaveNode(Node $node): null
    {
        foreach (array_reverse($this->collectors) as $collector) {
            $this->rejectTraversalMutation($collector->leaveNode($node), $collector);
        }

        $this->controlContexts?->leaveNode($node);

        return null;
    }

    public function afterTraverse(array $nodes): null
    {
        foreach (array_reverse($this->collectors) as $collector) {
            $this->rejectTraversalMutation($collector->afterTraverse($nodes), $collector);
        }

        return null;
    }

    /** @return list<SemanticFact> */
    public function facts(): array
    {
        $facts = [];

        foreach ($this->collectors as $collector) {
            foreach ($collector->facts() as $fact) {
                if (! $fact instanceof SemanticFact) {
                    throw new LogicException("Fact collector {$collector->name()} returned an invalid fact.");
                }

                $contexts = $this->controlContexts?->contextArraysForSpan(
                    $fact->startLine,
                    $fact->endLine,
                ) ?? [];
                $facts[] = new SemanticFact(
                    $fact->kind,
                    $fact->file,
                    $fact->startLine,
                    $fact->endLine,
                    $fact->attributes,
                    $this->mergeContexts($fact->controlContexts, $contexts),
                );
            }
        }

        usort($facts, static fn (SemanticFact $left, SemanticFact $right): int => [
            $left->file,
            $left->startLine,
            $left->endLine,
            $left->kind,
        ] <=> [
            $right->file,
            $right->startLine,
            $right->endLine,
            $right->kind,
        ]);

        return $facts;
    }

    private function rejectTraversalMutation(mixed $result, FactCollector $collector): void
    {
        if ($result !== null) {
            throw new LogicException("Fact collector {$collector->name()} attempted to alter AST traversal.");
        }
    }

    private function mergeContexts(array $existing, array $detected): array
    {
        $contexts = [];

        foreach ([...$existing, ...$detected] as $context) {
            $key = is_array($context) && is_string($context['boundary_id'] ?? null)
                ? $context['boundary_id']
                : hash('sha256', serialize($context));
            $contexts[$key] = $context;
        }

        return array_values($contexts);
    }
}
