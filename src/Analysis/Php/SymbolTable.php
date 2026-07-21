<?php

namespace DNDark\LogicMap\Analysis\Php;

use DNDark\LogicMap\Domain\Graph\NodeId;
use DNDark\LogicMap\Domain\Graph\NodeKind;
use DNDark\LogicMap\Support\RelativePath;

final class SymbolTable
{
    /** @var list<SymbolDefinition> */
    private array $symbols = [];

    public function add(SymbolDefinition $symbol): void
    {
        $this->symbols[] = $symbol;
    }

    /** @return list<SymbolDefinition> */
    public function byId(NodeId $id): array
    {
        return $this->matching(static fn (SymbolDefinition $symbol): bool => $symbol->id->equals($id));
    }

    /** @return list<SymbolDefinition> */
    public function exact(string $qualifiedName): array
    {
        $qualifiedName = ltrim($qualifiedName, '\\');

        return $this->matching(
            static fn (SymbolDefinition $symbol): bool => $symbol->qualifiedName === $qualifiedName,
        );
    }

    /** @return list<SymbolDefinition> */
    public function methods(string $class, string $method): array
    {
        $qualifiedName = ltrim($class, '\\').'::'.$method;

        return $this->matching(static fn (SymbolDefinition $symbol): bool => $symbol->structuralKind === NodeKind::Method
            && $symbol->qualifiedName === $qualifiedName);
    }

    /** @return list<SymbolDefinition> */
    public function implementations(string $interface): array
    {
        $interface = ltrim($interface, '\\');

        return $this->matching(static fn (SymbolDefinition $symbol): bool => in_array(
            $interface,
            $symbol->attributes['implements'] ?? [],
            true,
        ));
    }

    /** @return list<SymbolDefinition> */
    public function symbolsInFile(string $relativePath): array
    {
        $relativePath = RelativePath::normalize($relativePath);

        return $this->matching(
            static fn (SymbolDefinition $symbol): bool => $symbol->location->file === $relativePath,
        );
    }

    /** @return list<SymbolDefinition> */
    public function smallestEnclosing(string $relativePath, int $line): array
    {
        $candidates = array_values(array_filter(
            $this->symbolsInFile($relativePath),
            static fn (SymbolDefinition $symbol): bool => $symbol->location->startLine <= $line
                && $symbol->location->endLine >= $line,
        ));

        if ($candidates === []) {
            return [];
        }

        $smallestSpan = min(array_map(
            static fn (SymbolDefinition $symbol): int => $symbol->location->endLine - $symbol->location->startLine,
            $candidates,
        ));

        return $this->sort(array_values(array_filter(
            $candidates,
            static fn (SymbolDefinition $symbol): bool => $symbol->location->endLine
                - $symbol->location->startLine === $smallestSpan,
        )));
    }

    /** @return list<SymbolDefinition> */
    public function all(): array
    {
        return $this->sort($this->symbols);
    }

    /** @return list<SymbolDefinition> */
    private function matching(callable $predicate): array
    {
        return $this->sort(array_values(array_filter($this->symbols, $predicate)));
    }

    /**
     * @param list<SymbolDefinition> $symbols
     * @return list<SymbolDefinition>
     */
    private function sort(array $symbols): array
    {
        usort($symbols, static fn (SymbolDefinition $left, SymbolDefinition $right): int => [
            $left->id->value,
            $left->location->file,
            $left->location->startLine,
            $left->location->endLine,
        ] <=> [
            $right->id->value,
            $right->location->file,
            $right->location->startLine,
            $right->location->endLine,
        ]);

        return $symbols;
    }
}
