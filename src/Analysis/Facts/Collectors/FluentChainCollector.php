<?php

namespace DNDark\LogicMap\Analysis\Facts\Collectors;

use DNDark\LogicMap\Analysis\Facts\FactCollector;
use DNDark\LogicMap\Analysis\Facts\SemanticFact;
use PhpParser\Node;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Expr\NullsafeMethodCall;
use PhpParser\Node\Identifier;
use PhpParser\NodeVisitorAbstract;
use PhpParser\PrettyPrinter\Standard;

final class FluentChainCollector extends NodeVisitorAbstract implements FactCollector
{
    /** @var list<SemanticFact> */
    private array $facts = [];

    /** @var array<int, true> */
    private array $nestedCalls = [];

    public function __construct(private readonly string $file)
    {
    }

    public function name(): string
    {
        return 'fluent_chain';
    }

    public function enterNode(Node $node): null
    {
        if (! $node instanceof MethodCall && ! $node instanceof NullsafeMethodCall) {
            return null;
        }

        if (isset($this->nestedCalls[spl_object_id($node)])) {
            return null;
        }

        $calls = [];
        $cursor = $node;

        while ($cursor instanceof MethodCall || $cursor instanceof NullsafeMethodCall) {
            $this->nestedCalls[spl_object_id($cursor)] = true;
            $calls[] = $cursor->name instanceof Identifier
                ? $cursor->name->toString()
                : (new Standard())->prettyPrintExpr($cursor->name);
            $cursor = $cursor->var;
        }

        if (count($calls) > 1) {
            $this->facts[] = new SemanticFact(
                'fluent_chain',
                $this->file,
                $node->getStartLine(),
                $node->getEndLine(),
                [
                    'methods' => array_reverse($calls),
                    'expression' => (new Standard())->prettyPrintExpr($node),
                ],
            );
        }

        return null;
    }

    public function facts(): array
    {
        $facts = $this->facts;
        $this->facts = [];
        $this->nestedCalls = [];

        return $facts;
    }
}
