<?php

namespace dndark\LogicMap\Analysis\Visitors;

use dndark\LogicMap\Analysis\Support\IntentExtractor;
use dndark\LogicMap\Domain\Edge;
use dndark\LogicMap\Domain\Enums\Confidence;
use dndark\LogicMap\Domain\Enums\EdgeType;
use dndark\LogicMap\Domain\Enums\NodeKind;
use dndark\LogicMap\Domain\Graph;
use dndark\LogicMap\Domain\Node as DomainNode;
use PhpParser\Node;
use PhpParser\NodeVisitorAbstract;

class ClassMethodVisitor extends NodeVisitorAbstract
{
    protected ?string $currentClass = null;
    protected ?string $currentMethod = null;

    /** @var array<string, string> Maps property name to resolved class type */
    protected array $propertyTypes = [];

    /** @var array<string, string> Maps parameter name to resolved class type */
    protected array $constructorParams = [];

    public function __construct(protected Graph $graph)
    {
    }

    public function enterNode(Node $node)
    {
        if ($node instanceof Node\Stmt\Class_) {
            $this->currentClass = $node->namespacedName
                ? $node->namespacedName->toString()
                : (string)$node->name;
            $this->propertyTypes = [];
            $this->constructorParams = [];
            $this->processClass($node);
            $this->extractPropertyTypes($node);
        }

        if ($this->currentClass && $node instanceof Node\Stmt\ClassMethod) {
            $this->currentMethod = $node->name->toString();
            $this->processMethod($node);

            // Extract constructor parameter types for DI detection
            if ($this->currentMethod === '__construct') {
                $this->extractConstructorParams($node);
            }
        }

        // Detect method calls inside methods
        if ($this->currentClass && $this->currentMethod) {
            if ($node instanceof Node\Expr\MethodCall) {
                $this->processMethodCall($node);
            } elseif ($node instanceof Node\Expr\StaticCall) {
                $this->processStaticCall($node);
            } elseif ($node instanceof Node\Expr\New_) {
                $this->processNewExpression($node);
            }
        }

        return null;
    }

    public function leaveNode(Node $node)
    {
        if ($node instanceof Node\Stmt\Class_) {
            $this->currentClass = null;
            $this->propertyTypes = [];
            $this->constructorParams = [];
        }

        if ($node instanceof Node\Stmt\ClassMethod) {
            $this->currentMethod = null;
        }

        return null;
    }

    protected function processClass(Node\Stmt\Class_ $node): void
    {
        $kind = $this->guessKind($this->currentClass);

        $classNode = new DomainNode(
            id: 'class:' . $this->currentClass,
            kind: $kind,
            name: $this->currentClass,
            metadata: [
                'extends' => $node->extends ? $node->extends->toString() : null,
                'implements' => array_map(fn($i) => $i->toString(), $node->implements),
            ]
        );

        $this->graph->addNode($classNode);
    }

    protected function processMethod(Node\Stmt\ClassMethod $node): void
    {
        $methodId = 'method:' . $this->currentClass . '@' . $node->name->toString();
        $parentKind = $this->guessKind($this->currentClass);

        // Methods inherit kind from parent class context
        $methodKind = $parentKind !== NodeKind::UNKNOWN ? $parentKind : NodeKind::UNKNOWN;
        $intent = IntentExtractor::extractFromMethod($node->name->toString(), $this->currentClass);

        $methodNode = new DomainNode(
            id: $methodId,
            kind: $methodKind,
            name: $node->name->toString(),
            parentId: 'class:' . $this->currentClass,
            metadata: [
                'visibility' => $this->getVisibility($node),
                'is_static' => $node->isStatic(),
                'action' => $intent['action'],
                'domain' => $intent['domain'],
                'result' => $intent['result'],
                'shortLabel' => $intent['short'],
                'trigger' => $intent['trigger'],
            ]
        );

        $this->graph->addNode($methodNode);
    }

    protected function extractPropertyTypes(Node\Stmt\Class_ $node): void
    {
        foreach ($node->getProperties() as $property) {
            $type = $property->type;
            if ($type instanceof Node\Name) {
                $typeName = $type->toString();
                foreach ($property->props as $prop) {
                    $this->propertyTypes[$prop->name->toString()] = $typeName;
                }
            } elseif ($type instanceof Node\NullableType && $type->type instanceof Node\Name) {
                $typeName = $type->type->toString();
                foreach ($property->props as $prop) {
                    $this->propertyTypes[$prop->name->toString()] = $typeName;
                }
            }
            // Built-in types (Node\Identifier) are skipped intentionally
        }
    }

    protected function extractConstructorParams(Node\Stmt\ClassMethod $node): void
    {
        foreach ($node->params as $param) {
            $paramName = $param->var->name;
            $type = $param->type;

            if ($type instanceof Node\Name) {
                $this->constructorParams[$paramName] = $type->toString();
                // Also add as property if it's a promoted property
                if ($param->flags > 0) {
                    $this->propertyTypes[$paramName] = $type->toString();
                }
            } elseif ($type instanceof Node\NullableType && $type->type instanceof Node\Name) {
                $this->constructorParams[$paramName] = $type->type->toString();
                if ($param->flags > 0) {
                    $this->propertyTypes[$paramName] = $type->type->toString();
                }
            }
        }
    }

    protected function processMethodCall(Node\Expr\MethodCall $node): void
    {
        if (!$node->name instanceof Node\Identifier) {
            return; // Dynamic method name, skip
        }

        $targetMethod = $node->name->toString();
        $targetClass = $this->resolveCallTarget($node->var);

        if (!$targetClass) {
            return;
        }

        // Skip self-calls within same class (unless explicitly useful)
        if ($targetClass === $this->currentClass && $this->isSelfCallNoise($targetMethod)) {
            return;
        }

        $this->addCallEdge($targetClass, $targetMethod, $targetClass === $this->currentClass
            ? Confidence::HIGH
            : Confidence::MEDIUM);
    }

    protected function processStaticCall(Node\Expr\StaticCall $node): void
    {
        if (!$node->class instanceof Node\Name || !$node->name instanceof Node\Identifier) {
            return;
        }

        $targetClass = $node->class->toString();
        $targetMethod = $node->name->toString();

        // Resolve self/static/parent
        if (in_array($targetClass, ['self', 'static'])) {
            $targetClass = $this->currentClass;
        } elseif ($targetClass === 'parent') {
            return; // Skip parent calls for now
        }

        // Skip common framework facades with dynamic forwarding
        if ($this->isFrameworkFacade($targetClass)) {
            return;
        }

        $this->addCallEdge($targetClass, $targetMethod, Confidence::HIGH);
    }

    protected function processNewExpression(Node\Expr\New_ $node): void
    {
        if (!$node->class instanceof Node\Name) {
            return;
        }

        $targetClass = $node->class->toString();

        // Skip common value objects and framework classes
        if ($this->isCommonInstantiation($targetClass)) {
            return;
        }

        // Create a "uses" edge for instantiation
        $sourceId = 'method:' . $this->currentClass . '@' . $this->currentMethod;
        $targetId = 'class:' . $targetClass;

        $this->graph->addEdge(new Edge(
            source: $sourceId,
            target: $targetId,
            type: EdgeType::USE,
            confidence: Confidence::HIGH,
        ));
    }

    protected function resolveCallTarget(Node\Expr $var): ?string
    {
        // $this->property->method()
        if ($var instanceof Node\Expr\PropertyFetch) {
            if ($var->var instanceof Node\Expr\Variable && $var->var->name === 'this') {
                $propName = $var->name instanceof Node\Identifier ? $var->name->toString() : null;
                if ($propName && isset($this->propertyTypes[$propName])) {
                    return $this->propertyTypes[$propName];
                }
            }
            return null;
        }

        // $this->method()
        if ($var instanceof Node\Expr\Variable && $var->name === 'this') {
            return $this->currentClass;
        }

        // $variable->method() where $variable is a parameter
        if ($var instanceof Node\Expr\Variable && is_string($var->name)) {
            if (isset($this->constructorParams[$var->name])) {
                return $this->constructorParams[$var->name];
            }
        }

        return null;
    }

    protected function addCallEdge(string $targetClass, string $targetMethod, Confidence $confidence): void
    {
        $sourceId = 'method:' . $this->currentClass . '@' . $this->currentMethod;
        $targetId = 'method:' . $targetClass . '@' . $targetMethod;

        $this->graph->addEdge(new Edge(
            source: $sourceId,
            target: $targetId,
            type: EdgeType::CALL,
            confidence: $confidence,
        ));
    }

    protected function isSelfCallNoise(string $methodName): bool
    {
        // Skip common internal methods that don't represent workflow
        $noiseMethods = [
            '__construct', '__destruct', '__get', '__set', '__call',
            '__isset', '__unset', '__sleep', '__wakeup', '__toString',
            '__invoke', '__clone', '__debugInfo',
        ];

        return in_array($methodName, $noiseMethods);
    }

    protected function isFrameworkFacade(string $className): bool
    {
        $facades = [
            'Route', 'DB', 'Cache', 'Config', 'Log', 'Event', 'Queue',
            'Storage', 'Auth', 'Session', 'Request', 'Response', 'View',
            'Validator', 'Gate', 'Mail', 'Notification', 'Bus', 'App',
        ];

        return in_array($className, $facades);
    }

    protected function isCommonInstantiation(string $className): bool
    {
        $common = [
            'stdClass', 'DateTime', 'DateTimeImmutable', 'Carbon',
            'Collection', 'Request', 'Response',
        ];

        // Also skip if it looks like a value object
        if (str_ends_with($className, 'DTO') || str_ends_with($className, 'Data')) {
            return true;
        }

        return in_array($className, $common);
    }

    protected function getVisibility(Node\Stmt\ClassMethod $node): string
    {
        if ($node->isPublic()) return 'public';
        if ($node->isProtected()) return 'protected';
        if ($node->isPrivate()) return 'private';
        return 'public';
    }

    protected function guessKind(string $className): NodeKind
    {
        // Check suffixes first (more specific)
        if (str_ends_with($className, 'Controller')) return NodeKind::CONTROLLER;
        if (str_ends_with($className, 'Service')) return NodeKind::SERVICE;
        if (str_ends_with($className, 'Repository')) return NodeKind::REPOSITORY;
        if (str_ends_with($className, 'Job')) return NodeKind::JOB;
        if (str_ends_with($className, 'Event')) return NodeKind::EVENT;
        if (str_ends_with($className, 'Listener')) return NodeKind::LISTENER;
        if (str_ends_with($className, 'Command')) return NodeKind::COMPONENT;

        // Check namespace patterns
        if (str_contains($className, '\\Controllers\\')) return NodeKind::CONTROLLER;
        if (str_contains($className, '\\Services\\')) return NodeKind::SERVICE;
        if (str_contains($className, '\\Repositories\\')) return NodeKind::REPOSITORY;
        if (str_contains($className, '\\Jobs\\')) return NodeKind::JOB;
        if (str_contains($className, '\\Events\\')) return NodeKind::EVENT;
        if (str_contains($className, '\\Listeners\\')) return NodeKind::LISTENER;
        if (str_contains($className, '\\Models\\')) return NodeKind::MODEL;
        if (str_contains($className, '\\Projectors\\')) return NodeKind::COMPONENT;

        return NodeKind::UNKNOWN;
    }
}
