<?php

namespace DNDark\LogicMap\Analysis\Facts\Collectors;

use DNDark\LogicMap\Analysis\Facts\FactCollector;
use DNDark\LogicMap\Analysis\Facts\SemanticFact;
use PhpParser\Node;
use PhpParser\Node\Expr\ArrowFunction;
use PhpParser\Node\Expr\Closure;
use PhpParser\Node\Expr\FuncCall;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Expr\StaticCall;
use PhpParser\NodeVisitorAbstract;
use PhpParser\PrettyPrinter\Standard;

final class ClosureBoundaryCollector extends NodeVisitorAbstract implements FactCollector
{
    /** @var list<SemanticFact> */
    private array $facts = [];

    public function __construct(private readonly string $file)
    {
    }

    public function name(): string
    {
        return 'closure_boundary';
    }

    public function enterNode(Node $node): null
    {
        if (! $node instanceof FuncCall && ! $node instanceof MethodCall && ! $node instanceof StaticCall) {
            return null;
        }

        if ($node->isFirstClassCallable()) {
            return null;
        }

        $printer = new Standard();

        foreach ($node->getArgs() as $index => $argument) {
            if (! $argument->value instanceof Closure && ! $argument->value instanceof ArrowFunction) {
                continue;
            }

            $this->facts[] = new SemanticFact(
                'closure_boundary',
                $this->file,
                $argument->value->getStartLine(),
                $argument->value->getEndLine(),
                [
                    'argument_index' => $index,
                    'call_expression' => $printer->prettyPrintExpr($node),
                    'closure_expression' => $printer->prettyPrintExpr($argument->value),
                    'body_start_line' => $this->bodyStartLine($argument->value),
                    'body_end_line' => $this->bodyEndLine($argument->value),
                ],
            );
        }

        return null;
    }

    public function facts(): array
    {
        $facts = $this->facts;
        $this->facts = [];

        return $facts;
    }

    private function bodyStartLine(Closure|ArrowFunction $closure): int
    {
        if ($closure instanceof ArrowFunction) {
            return $closure->expr->getStartLine();
        }

        return $closure->stmts === []
            ? $closure->getStartLine()
            : $closure->stmts[0]->getStartLine();
    }

    private function bodyEndLine(Closure|ArrowFunction $closure): int
    {
        if ($closure instanceof ArrowFunction) {
            return $closure->expr->getEndLine();
        }

        return $closure->stmts === []
            ? $closure->getEndLine()
            : $closure->stmts[array_key_last($closure->stmts)]->getEndLine();
    }
}
