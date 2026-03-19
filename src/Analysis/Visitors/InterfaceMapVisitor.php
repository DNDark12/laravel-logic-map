<?php

namespace dndark\LogicMap\Analysis\Visitors;

use PhpParser\Node;
use PhpParser\NodeVisitorAbstract;

class InterfaceMapVisitor extends NodeVisitorAbstract
{
    /** @var array<string, list<string>> */
    protected array $interfaceMap = [];

    public function enterNode(Node $node)
    {
        if (!$node instanceof Node\Stmt\Class_) {
            return null;
        }

        if ($node->isAnonymous() || empty($node->implements)) {
            return null;
        }

        $className = $node->namespacedName
            ? ltrim($node->namespacedName->toString(), '\\')
            : ($node->name ? ltrim($node->name->toString(), '\\') : '');

        if ($className === '') {
            return null;
        }

        foreach ($node->implements as $interfaceName) {
            $interface = ltrim($interfaceName->toString(), '\\');
            if ($interface === '') {
                continue;
            }

            $this->interfaceMap[$interface] ??= [];
            if (!in_array($className, $this->interfaceMap[$interface], true)) {
                $this->interfaceMap[$interface][] = $className;
            }
        }

        return null;
    }

    /**
     * @return array<string, list<string>>
     */
    public function getInterfaceMap(): array
    {
        return $this->interfaceMap;
    }
}

