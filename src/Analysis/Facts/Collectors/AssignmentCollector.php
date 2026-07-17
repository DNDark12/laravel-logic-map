<?php

namespace DNDark\LogicMap\Analysis\Facts\Collectors;

use DNDark\LogicMap\Analysis\Facts\FactCollector;
use DNDark\LogicMap\Analysis\Facts\SemanticFact;
use PhpParser\Node;
use PhpParser\Node\Expr\Assign;
use PhpParser\NodeVisitorAbstract;
use PhpParser\PrettyPrinter\Standard;

final class AssignmentCollector extends NodeVisitorAbstract implements FactCollector
{
    /** @var list<SemanticFact> */
    private array $facts = [];

    public function __construct(private readonly string $file)
    {
    }

    public function name(): string
    {
        return 'assignment';
    }

    public function enterNode(Node $node): null
    {
        if ($node instanceof Assign) {
            $printer = new Standard();
            $this->facts[] = new SemanticFact(
                'assignment',
                $this->file,
                $node->getStartLine(),
                $node->getEndLine(),
                [
                    'target' => $printer->prettyPrintExpr($node->var),
                    'value' => $printer->prettyPrintExpr($node->expr),
                    'expression' => $printer->prettyPrintExpr($node),
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
}
