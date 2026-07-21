<?php

namespace DNDark\LogicMap\Analysis\Php;

use Closure;
use DNDark\LogicMap\Analysis\Facts\Collectors\ArrayLiteralCollector;
use DNDark\LogicMap\Analysis\Facts\Collectors\AssignmentCollector;
use DNDark\LogicMap\Analysis\Facts\Collectors\ClosureBoundaryCollector;
use DNDark\LogicMap\Analysis\Facts\Collectors\FluentChainCollector;
use DNDark\LogicMap\Analysis\Facts\Collectors\PropertyDefaultCollector;
use DNDark\LogicMap\Analysis\Facts\Collectors\TerminalStatementCollector;
use DNDark\LogicMap\Analysis\Facts\FactCollector;
use DNDark\LogicMap\Analysis\Facts\FactCollectingVisitor;
use DNDark\LogicMap\Analysis\Facts\FileAwareFactCollector;
use DNDark\LogicMap\Analysis\Facts\SemanticFact;
use DNDark\LogicMap\Domain\Snapshot\Diagnostic;
use DNDark\LogicMap\Domain\Snapshot\DiagnosticCode;
use DNDark\LogicMap\Support\RelativePath;
use InvalidArgumentException;
use PhpParser\Error;
use PhpParser\NodeTraverser;
use PhpParser\NodeTraverserInterface;
use PhpParser\NodeVisitor\NameResolver;
use PhpParser\Parser;
use PhpParser\ParserFactory;

final class PhpFileParser
{
    private readonly Parser $parser;

    private readonly Closure $traverserFactory;

    /** @param list<FactCollector> $additionalCollectors */
    public function __construct(
        private readonly array $additionalCollectors = [],
        ?callable $traverserFactory = null,
        private readonly int $expressionMaxLength = 500,
    ) {
        foreach ($additionalCollectors as $collector) {
            if (! $collector instanceof FactCollector) {
                throw new InvalidArgumentException('Additional parser collectors must implement FactCollector.');
            }
        }

        if ($expressionMaxLength < 1) {
            throw new InvalidArgumentException('Expression maximum length must be positive.');
        }

        $this->parser = (new ParserFactory())->createForNewestSupportedVersion();
        $this->traverserFactory = $traverserFactory === null
            ? static fn (): NodeTraverser => new NodeTraverser()
            : Closure::fromCallable($traverserFactory);
    }

    public function parse(string $relativePath, string $source): ParsedFile
    {
        $relativePath = RelativePath::normalize($relativePath);
        $contentHash = hash('sha256', $source);

        try {
            $statements = $this->parser->parse($source) ?? [];
        } catch (Error $error) {
            $line = max(1, $error->getStartLine());

            return new ParsedFile(
                $relativePath,
                $contentHash,
                [],
                [],
                [],
                [],
                [],
                [new Diagnostic(
                    DiagnosticCode::ParseError,
                    'parse',
                    $relativePath,
                    $line,
                    $line,
                    $error->getMessage(),
                    ['exception' => $error::class],
                )],
            );
        }

        $controlContexts = new ControlContextStack(new ExpressionNormalizer($this->expressionMaxLength));
        $symbolVisitor = new PhpSymbolCallVisitor(
            $relativePath,
            $this->expressionMaxLength,
            $controlContexts,
        );

        foreach ($this->additionalCollectors as $collector) {
            if ($collector instanceof FileAwareFactCollector) {
                $collector->useFile($relativePath);
            }
        }

        $factVisitor = new FactCollectingVisitor([
            new AssignmentCollector($relativePath),
            new ArrayLiteralCollector($relativePath),
            new FluentChainCollector($relativePath),
            new PropertyDefaultCollector($relativePath),
            new TerminalStatementCollector($relativePath),
            new ClosureBoundaryCollector($relativePath),
            ...$this->additionalCollectors,
        ], $controlContexts);
        $traverser = ($this->traverserFactory)();

        if (! $traverser instanceof NodeTraverserInterface) {
            throw new InvalidArgumentException('The traverser factory must return a NodeTraverserInterface.');
        }

        $traverser->addVisitor(new NameResolver(null, [
            'preserveOriginalNames' => true,
            'replaceNodes' => false,
        ]));
        $traverser->addVisitor($symbolVisitor);
        $traverser->addVisitor($factVisitor);
        $traverser->traverse($statements);
        unset($statements);

        $symbols = $symbolVisitor->symbols();
        $diagnostics = $this->duplicateSymbolDiagnostics($relativePath, $symbols);

        return new ParsedFile(
            $relativePath,
            $contentHash,
            $symbols,
            $symbolVisitor->imports(),
            $symbolVisitor->inheritanceFacts(),
            $symbolVisitor->callSites(),
            $this->boundFacts($factVisitor->facts()),
            $diagnostics,
        );
    }

    /**
     * @param list<SemanticFact> $facts
     * @return list<SemanticFact>
     */
    private function boundFacts(array $facts): array
    {
        return array_map(fn (SemanticFact $fact): SemanticFact => new SemanticFact(
            $fact->kind,
            $fact->file,
            $fact->startLine,
            $fact->endLine,
            $this->boundAttributes($fact->attributes),
            $fact->controlContexts,
        ), $facts);
    }

    private function boundAttributes(array $attributes): array
    {
        foreach ($attributes as $key => $value) {
            if (is_array($value)) {
                $attributes[$key] = $this->boundAttributes($value);
            } elseif (is_string($value) && str_contains((string) $key, 'expression')) {
                $attributes[$key] = substr($value, 0, $this->expressionMaxLength);
            }
        }

        return $attributes;
    }

    /**
     * @param list<SymbolDefinition> $symbols
     * @return list<Diagnostic>
     */
    private function duplicateSymbolDiagnostics(string $file, array $symbols): array
    {
        $groups = [];

        foreach ($symbols as $symbol) {
            if ($symbol->qualifiedName === null) {
                continue;
            }

            $groups[$symbol->structuralKind->value.'\0'.$symbol->qualifiedName][] = $symbol;
        }

        $diagnostics = [];

        foreach ($groups as $duplicates) {
            if (count($duplicates) < 2) {
                continue;
            }

            $startLine = min(array_map(static fn (SymbolDefinition $symbol): int => $symbol->location->startLine, $duplicates));
            $endLine = max(array_map(static fn (SymbolDefinition $symbol): int => $symbol->location->endLine, $duplicates));
            $diagnostics[] = new Diagnostic(
                DiagnosticCode::DuplicateSymbol,
                'parse',
                $file,
                $startLine,
                $endLine,
                "Duplicate declaration for {$duplicates[0]->qualifiedName}.",
                [
                    'qualified_name' => $duplicates[0]->qualifiedName,
                    'count' => count($duplicates),
                    'symbol_ids' => array_map(
                        static fn (SymbolDefinition $symbol): string => $symbol->id->value,
                        $duplicates,
                    ),
                ],
            );
        }

        usort($diagnostics, static fn (Diagnostic $left, Diagnostic $right): int => [
            $left->startLine,
            $left->message,
        ] <=> [
            $right->startLine,
            $right->message,
        ]);

        return $diagnostics;
    }
}
