<?php

namespace DNDark\LogicMap\Analysis\Laravel;

use DNDark\LogicMap\Analysis\Php\SymbolDefinition;
use DNDark\LogicMap\Analysis\Php\SymbolTable;
use DNDark\LogicMap\Domain\Graph\Certainty;
use DNDark\LogicMap\Domain\Graph\EdgeType;
use DNDark\LogicMap\Domain\Graph\EvidenceOrigin;
use DNDark\LogicMap\Domain\Graph\GraphNode;
use DNDark\LogicMap\Domain\Graph\KnowledgeGraph;
use DNDark\LogicMap\Domain\Graph\NodeId;
use DNDark\LogicMap\Domain\Graph\NodeKind;

final class ModuleResolver
{
    public function __construct(
        private readonly array $explicit,
        private readonly array $namespaceRoots,
        private readonly array $directoryRoots,
        private readonly string $fallback,
    ) {}

    public function resolve(SymbolDefinition $symbol): ModuleAssignment
    {
        $qualifiedName = $this->ownerName($symbol);
        $file = str_replace('\\', '/', $symbol->location->file);

        if (($explicit = $this->explicitModule($qualifiedName, $file)) !== null) {
            return new ModuleAssignment($symbol->id, $explicit, 'explicit mapping');
        }

        foreach ($this->directoryRoots as $root) {
            $root = trim(str_replace('\\', '/', (string) $root), '/');

            if ($root !== '' && preg_match('#^'.preg_quote($root, '#').'/([^/]+)(?:/|$)#', $file, $matches) === 1) {
                return new ModuleAssignment($symbol->id, $matches[1], 'directory_root convention: '.$root);
            }
        }

        foreach ($this->sortedNamespaceRoots() as $prefix => $position) {
            if ($qualifiedName === null || ! str_starts_with($qualifiedName, $prefix)) {
                continue;
            }

            $segments = array_values(array_filter(
                explode('\\', substr($qualifiedName, strlen($prefix))),
                static fn (string $segment): bool => $segment !== '',
            ));
            $index = max(1, (int) $position) - 1;

            if (isset($segments[$index])) {
                return new ModuleAssignment(
                    $symbol->id,
                    $segments[$index],
                    'namespace_root convention: '.$prefix,
                );
            }
        }

        if (preg_match('#^app/([^/]+)(?:/|$)#', $file, $matches) === 1) {
            return new ModuleAssignment($symbol->id, $matches[1], 'application_directory convention');
        }

        return new ModuleAssignment(
            $symbol->id,
            trim($this->fallback) !== '' ? $this->fallback : 'Core',
            'fallback module',
        );
    }

    /** @return list<ModuleAssignment> */
    public function assign(SymbolTable $symbols, KnowledgeGraph $graph): array
    {
        $assignments = [];
        $modules = [];

        foreach ($symbols->all() as $symbol) {
            $assignment = $this->resolve($symbol);
            $assignments[] = $assignment;
            $moduleId = NodeId::named(NodeKind::Module, $assignment->module);

            if (! isset($modules[$moduleId->value])) {
                $graph->addNode(new GraphNode(
                    $moduleId,
                    NodeKind::Module,
                    $assignment->module,
                    null,
                    null,
                    ['assignment' => 'deterministic'],
                ));
                $modules[$moduleId->value] = true;
            }

            SemanticEdgeFactory::add(
                $graph,
                $symbol->id,
                EdgeType::MemberOfModule,
                $moduleId,
                EvidenceOrigin::StaticAst,
                'module-resolver',
                Certainty::Certain,
                $symbol->location,
                $assignment->reason,
                null,
                'module:'.$assignment->module,
                ['reason' => $assignment->reason],
            );
        }

        return $assignments;
    }

    private function ownerName(SymbolDefinition $symbol): ?string
    {
        if ($symbol->qualifiedName === null) {
            return null;
        }

        return explode('::', ltrim($symbol->qualifiedName, '\\'), 2)[0];
    }

    private function explicitModule(?string $qualifiedName, string $file): ?string
    {
        $mappings = $this->explicit;
        uksort($mappings, static fn (string $left, string $right): int => strlen($right) <=> strlen($left));

        foreach ($mappings as $key => $module) {
            $key = trim((string) $key);
            $module = trim((string) $module);

            if ($key === '' || $module === '') {
                continue;
            }

            $pathKey = trim(str_replace('\\', '/', $key), '/');
            $namespaceKey = trim($key, '\\');
            $usesGlob = strpbrk($key, '*?[') !== false;
            $globMatch = $usesGlob && (
                ($qualifiedName !== null && fnmatch(
                    str_replace('\\', '/', $namespaceKey),
                    str_replace('\\', '/', ltrim($qualifiedName, '\\')),
                    FNM_NOESCAPE,
                ))
                || fnmatch($pathKey, $file, FNM_NOESCAPE)
            );
            $namespaceMatch = $qualifiedName !== null
                && ($qualifiedName === $namespaceKey || str_starts_with($qualifiedName, $namespaceKey.'\\'));
            $pathMatch = $file === $pathKey || str_starts_with($file, $pathKey.'/');

            if ($globMatch || $namespaceMatch || $pathMatch) {
                return $module;
            }
        }

        return null;
    }

    private function sortedNamespaceRoots(): array
    {
        $roots = [];

        foreach ($this->namespaceRoots as $prefix => $position) {
            $normalized = trim((string) $prefix, '\\').'\\';

            if ($normalized !== '\\') {
                $roots[$normalized] = $position;
            }
        }

        uksort($roots, static fn (string $left, string $right): int => strlen($right) <=> strlen($left));

        return $roots;
    }
}
