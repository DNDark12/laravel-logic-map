<?php

namespace DNDark\LogicMap\Analysis\Facts\Collectors;

use DNDark\LogicMap\Analysis\Facts\FactCollector;
use DNDark\LogicMap\Analysis\Facts\SemanticFact;
use PhpParser\Node;
use PhpParser\Node\Expr\Array_;
use PhpParser\NodeVisitorAbstract;
use PhpParser\PrettyPrinter\Standard;

final class ArrayLiteralCollector extends NodeVisitorAbstract implements FactCollector
{
    /** @var list<SemanticFact> */
    private array $facts = [];

    public function __construct(private readonly string $file)
    {
    }

    public function name(): string
    {
        return 'array_literal';
    }

    public function enterNode(Node $node): null
    {
        if ($node instanceof Array_) {
            $this->facts[] = new SemanticFact(
                'array_literal',
                $this->file,
                $node->getStartLine(),
                $node->getEndLine(),
                [
                    'expression' => (new Standard())->prettyPrintExpr($node),
                    'item_count' => count($node->items),
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
