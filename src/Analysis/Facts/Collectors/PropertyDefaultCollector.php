<?php

namespace DNDark\LogicMap\Analysis\Facts\Collectors;

use DNDark\LogicMap\Analysis\Facts\FactCollector;
use DNDark\LogicMap\Analysis\Facts\SemanticFact;
use PhpParser\Node;
use PhpParser\Node\Stmt\Property;
use PhpParser\NodeVisitorAbstract;
use PhpParser\PrettyPrinter\Standard;

final class PropertyDefaultCollector extends NodeVisitorAbstract implements FactCollector
{
    /** @var list<SemanticFact> */
    private array $facts = [];

    public function __construct(private readonly string $file)
    {
    }

    public function name(): string
    {
        return 'property_default';
    }

    public function enterNode(Node $node): null
    {
        if (! $node instanceof Property) {
            return null;
        }

        $printer = new Standard();

        foreach ($node->props as $property) {
            if ($property->default === null) {
                continue;
            }

            $this->facts[] = new SemanticFact(
                'property_default',
                $this->file,
                $property->getStartLine(),
                $property->getEndLine(),
                [
                    'property' => $property->name->toString(),
                    'value' => $printer->prettyPrintExpr($property->default),
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
