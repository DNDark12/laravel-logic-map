<?php

namespace DNDark\LogicMap\Analysis\Facts\Collectors;

use DNDark\LogicMap\Analysis\Facts\FactCollector;
use DNDark\LogicMap\Analysis\Facts\SemanticFact;
use PhpParser\Node;
use PhpParser\Node\Expr\Throw_;
use PhpParser\Node\Stmt\Return_;
use PhpParser\NodeVisitorAbstract;
use PhpParser\PrettyPrinter\Standard;

final class TerminalStatementCollector extends NodeVisitorAbstract implements FactCollector
{
    /** @var list<SemanticFact> */
    private array $facts = [];

    public function __construct(private readonly string $file)
    {
    }

    public function name(): string
    {
        return 'terminal';
    }

    public function enterNode(Node $node): null
    {
        if (! $node instanceof Return_ && ! $node instanceof Throw_) {
            return null;
        }

        $printer = new Standard();
        $expression = $node instanceof Return_
            ? ($node->expr === null ? 'return' : 'return '.$printer->prettyPrintExpr($node->expr))
            : 'throw '.$printer->prettyPrintExpr($node->expr);

        $this->facts[] = new SemanticFact(
            'terminal',
            $this->file,
            $node->getStartLine(),
            $node->getEndLine(),
            [
                'terminal' => $node instanceof Return_ ? 'return' : 'throw',
                'expression' => $expression,
            ],
        );

        return null;
    }

    public function facts(): array
    {
        $facts = $this->facts;
        $this->facts = [];

        return $facts;
    }
}
