<?php

namespace dndark\LogicMap\Analysis\Visitors;

use dndark\LogicMap\Analysis\Support\IntentExtractor;
use dndark\LogicMap\Analysis\Support\ModuleExtractor;
use dndark\LogicMap\Domain\Edge;
use dndark\LogicMap\Domain\Enums\Confidence;
use dndark\LogicMap\Domain\Enums\EdgeType;
use dndark\LogicMap\Domain\Enums\NodeKind;
use dndark\LogicMap\Domain\Graph;
use dndark\LogicMap\Domain\Node as DomainNode;
use PhpParser\Node;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitor;
use PhpParser\NodeVisitorAbstract;

class ClassMethodVisitor extends NodeVisitorAbstract
{
    /** @var list<string> */
    protected const BLOCKED_NAMESPACE_PREFIXES = [
        'App\\Models\\',
        'App\\Http\\Requests\\',
        'App\\Http\\Resources\\',
        'App\\Providers\\',
        'App\\Exceptions\\',
        'Database\\Factories\\',
        'Database\\Seeders\\',
        'Illuminate\\',
        'Symfony\\',
        'Psr\\',
    ];

    /** @var list<string> */
    protected const BLOCKED_CLASS_SUFFIXES = [
        'Request', 'Resource', 'Provider', 'Middleware', 'Policy', 'Exception', 'Factory', 'Seeder', 'Model',
    ];

    /** @var list<string> */
    protected const PROXY_CLASSES = [
        'ApiResponse', 'Log', 'Cache', 'DB', 'Route', 'Auth', 'Config', 'Validator', 'Event', 'Queue',
        'Storage', 'Response', 'Request', 'Controller', 'Str', 'Arr', 'Collection',
    ];

    /** @var list<string> */
    protected const BLACKLIST_METHODS = [
        'all', 'get', 'set', 'find', 'first', 'last', 'where', 'with', 'save', 'delete', 'update',
        'create', 'make', 'query', 'pluck', 'count', 'exists',
    ];

    /** @var list<string> */
    protected const BLACKLIST_PREFIXES = ['find', 'exists', 'is', 'has', 'set', 'get'];

    protected ?string $currentClass = null;
    protected ?string $currentMethod = null;

    /** @var array<string, string> Maps property name to resolved class type */
    protected array $propertyTypes = [];

    /** @var array<string, string> Maps parameter name to resolved class type */
    protected array $constructorParams = [];

    /** @var array<string, list<string>> Maps interface FQCN to concrete implementations */
    protected array $interfaceMap = [];

    /**
     * @param array<string, list<string>> $interfaceMap
     */
    public function __construct(protected Graph $graph, array $interfaceMap = [])
    {
        $this->interfaceMap = $this->normalizeInterfaceMap($interfaceMap);
    }

    public function enterNode(Node $node)
    {
        if ($node instanceof Node\Stmt\Class_) {
            $resolvedClass = $node->namespacedName
                ? $node->namespacedName->toString()
                : (string)$node->name;
            $this->currentClass = ltrim($resolvedClass, '\\');
            $this->propertyTypes = [];
            $this->constructorParams = [];

            if (!$this->currentClass || $this->shouldSkipClass($this->currentClass)) {
                $this->currentClass = null;
                return NodeVisitor::DONT_TRAVERSE_CHILDREN;
            }

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
                'module' => ModuleExtractor::moduleOf($this->currentClass),
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
        $docIntent = IntentExtractor::extractDocIntent($node->getDocComment());
        $bodyStrings = IntentExtractor::extractBodyStrings($node);
        $resultMessages = IntentExtractor::extractResultMessages($node);
        $result = $intent['result'];
        if (($result === '' || $result === null) && !empty($resultMessages)) {
            $result = $resultMessages[0];
        }

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
                'result' => $result,
                'shortLabel' => $docIntent !== '' ? $docIntent : $intent['short'],
                'trigger' => $intent['trigger'],
                'module' => ModuleExtractor::moduleOf($this->currentClass),
                'docIntent' => $docIntent,
                'bodyStrings' => $bodyStrings,
                'resultMessages' => $resultMessages,
            ]
        );

        $this->graph->addNode($methodNode);
    }

    protected function extractPropertyTypes(Node\Stmt\Class_ $node): void
    {
        foreach ($node->getProperties() as $property) {
            $type = $property->type;
            if ($type instanceof Node\Name) {
                $typeName = $this->resolveConcreteClass($type->toString());
                foreach ($property->props as $prop) {
                    $this->propertyTypes[$prop->name->toString()] = $typeName;
                }
            } elseif ($type instanceof Node\NullableType && $type->type instanceof Node\Name) {
                $typeName = $this->resolveConcreteClass($type->type->toString());
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
                $resolved = $this->resolveConcreteClass($type->toString());
                $this->constructorParams[$paramName] = $resolved;
                // Also add as property if it's a promoted property
                if ($param->flags > 0) {
                    $this->propertyTypes[$paramName] = $resolved;
                }
            } elseif ($type instanceof Node\NullableType && $type->type instanceof Node\Name) {
                $resolved = $this->resolveConcreteClass($type->type->toString());
                $this->constructorParams[$paramName] = $resolved;
                if ($param->flags > 0) {
                    $this->propertyTypes[$paramName] = $resolved;
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

        if ($this->shouldSkipClass($targetClass) || $this->shouldSkipCall($targetClass, $targetMethod)) {
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

        $targetClass = ltrim($targetClass, '\\');

        // Skip common framework facades with dynamic forwarding
        if ($this->isFrameworkFacade($targetClass) || $this->shouldSkipClass($targetClass) || $this->shouldSkipCall($targetClass, $targetMethod)) {
            return;
        }

        $this->addCallEdge($targetClass, $targetMethod, Confidence::HIGH);
    }

    protected function processNewExpression(Node\Expr\New_ $node): void
    {
        if (!$node->class instanceof Node\Name) {
            return;
        }

        $targetClass = ltrim($node->class->toString(), '\\');

        // Skip common value objects and framework classes
        if ($this->isCommonInstantiation($targetClass) || $this->shouldSkipClass($targetClass)) {
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

        $short = $this->shortClassName($className);
        return in_array($className, $facades, true) || in_array($short, $facades, true);
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

    protected function shouldSkipClass(string $className): bool
    {
        $className = ltrim($className, '\\');
        if ($className === '') {
            return true;
        }

        $customIgnored = config('logic-map.ignore_namespaces', []);
        if (is_array($customIgnored)) {
            foreach ($customIgnored as $prefix) {
                if (!is_string($prefix) || $prefix === '') {
                    continue;
                }

                $normalized = ltrim(trim($prefix), '\\');
                if ($normalized !== '' && str_starts_with($className, $normalized)) {
                    return true;
                }
            }
        }

        foreach (self::BLOCKED_NAMESPACE_PREFIXES as $prefix) {
            if (str_starts_with($className, $prefix)) {
                return true;
            }
        }

        $short = $this->shortClassName($className);
        if (in_array($short, self::PROXY_CLASSES, true)) {
            return true;
        }

        foreach (self::BLOCKED_CLASS_SUFFIXES as $suffix) {
            if (str_ends_with($short, $suffix)) {
                return true;
            }
        }

        return false;
    }

    protected function shouldSkipCall(string $targetClass, string $targetMethod): bool
    {
        $targetClass = ltrim($targetClass, '\\');
        $method = strtolower($targetMethod);

        $shortClass = $this->shortClassName($targetClass);
        if (in_array($shortClass, self::PROXY_CLASSES, true)) {
            return true;
        }

        $targetKind = $this->guessKind($targetClass);
        $isBusinessFlowKind = in_array($targetKind, [
            NodeKind::CONTROLLER,
            NodeKind::SERVICE,
            NodeKind::JOB,
            NodeKind::EVENT,
            NodeKind::LISTENER,
            NodeKind::COMPONENT,
            NodeKind::ACTION,
            NodeKind::HELPER,
            NodeKind::OBSERVER,
            NodeKind::POLICY,
            NodeKind::MIDDLEWARE,
            NodeKind::RULE,
            NodeKind::CONSOLE,
        ], true);

        if ($isBusinessFlowKind) {
            return false;
        }

        if (in_array($method, self::BLACKLIST_METHODS, true)) {
            return true;
        }

        foreach (self::BLACKLIST_PREFIXES as $prefix) {
            if (str_starts_with($method, $prefix)) {
                return true;
            }
        }

        return false;
    }

    protected function shortClassName(string $className): string
    {
        $className = ltrim($className, '\\');
        if (!str_contains($className, '\\')) {
            return $className;
        }

        $parts = explode('\\', $className);
        return end($parts) ?: $className;
    }

    /**
     * @param array<string, mixed> $interfaceMap
     * @return array<string, list<string>>
     */
    protected function normalizeInterfaceMap(array $interfaceMap): array
    {
        $normalized = [];

        foreach ($interfaceMap as $interface => $implementations) {
            $interfaceKey = ltrim((string)$interface, '\\');
            if ($interfaceKey === '') {
                continue;
            }

            $list = is_array($implementations) ? $implementations : [$implementations];
            $list = array_values(array_unique(array_filter(array_map(
                fn($item) => ltrim((string)$item, '\\'),
                $list
            ), fn($item) => $item !== '')));

            if (!empty($list)) {
                $normalized[$interfaceKey] = $list;
            }
        }

        return $normalized;
    }

    protected function resolveConcreteClass(string $typeName): string
    {
        $normalized = ltrim($typeName, '\\');
        if ($normalized === '') {
            return $normalized;
        }

        $candidates = $this->interfaceMap[$normalized] ?? null;
        if (!is_array($candidates) || empty($candidates)) {
            return $normalized;
        }

        return $candidates[0];
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
        if (str_ends_with($className, 'Action')) return NodeKind::ACTION;
        if (str_ends_with($className, 'Helper')) return NodeKind::HELPER;
        if (str_ends_with($className, 'Repository')) return NodeKind::REPOSITORY;
        if (str_ends_with($className, 'Job')) return NodeKind::JOB;
        if (str_ends_with($className, 'Event')) return NodeKind::EVENT;
        if (str_ends_with($className, 'Listener')) return NodeKind::LISTENER;
        if (str_ends_with($className, 'Observer')) return NodeKind::OBSERVER;
        if (str_ends_with($className, 'Policy')) return NodeKind::POLICY;
        if (str_ends_with($className, 'Middleware')) return NodeKind::MIDDLEWARE;
        if (str_ends_with($className, 'Rule')) return NodeKind::RULE;
        if (str_ends_with($className, 'Exception')) return NodeKind::EXCEPTION;
        if (str_ends_with($className, 'Provider')) return NodeKind::PROVIDER;
        if (str_ends_with($className, 'Resource')) return NodeKind::RESOURCE;
        if (str_ends_with($className, 'Command')) return NodeKind::CONSOLE;
        if (str_ends_with($className, 'Analyzer')) return NodeKind::COMPONENT;
        if (str_ends_with($className, 'Projector')) return NodeKind::COMPONENT;
        if (str_ends_with($className, 'Resolver')) return NodeKind::COMPONENT;
        if (str_ends_with($className, 'Builder')) return NodeKind::COMPONENT;
        if (str_ends_with($className, 'Parser')) return NodeKind::COMPONENT;
        if (str_ends_with($className, 'Extractor')) return NodeKind::COMPONENT;
        if (str_ends_with($className, 'Visitor')) return NodeKind::COMPONENT;
        if (str_ends_with($className, 'Writer')) return NodeKind::COMPONENT;
        if (str_ends_with($className, 'Calculator')) return NodeKind::COMPONENT;

        // Check namespace patterns
        if (str_contains($className, '\\Controllers\\')) return NodeKind::CONTROLLER;
        if (str_contains($className, '\\Services\\')) return NodeKind::SERVICE;
        if (str_contains($className, '\\Actions\\')) return NodeKind::ACTION;
        if (str_contains($className, '\\Helpers\\')) return NodeKind::HELPER;
        if (str_contains($className, '\\Repositories\\')) return NodeKind::REPOSITORY;
        if (str_contains($className, '\\Jobs\\')) return NodeKind::JOB;
        if (str_contains($className, '\\Events\\')) return NodeKind::EVENT;
        if (str_contains($className, '\\Listeners\\')) return NodeKind::LISTENER;
        if (str_contains($className, '\\Models\\')) return NodeKind::MODEL;
        if (str_contains($className, '\\Observers\\')) return NodeKind::OBSERVER;
        if (str_contains($className, '\\Policies\\')) return NodeKind::POLICY;
        if (str_contains($className, '\\Middleware\\')) return NodeKind::MIDDLEWARE;
        if (str_contains($className, '\\Rules\\')) return NodeKind::RULE;
        if (str_contains($className, '\\Exceptions\\')) return NodeKind::EXCEPTION;
        if (str_contains($className, '\\Providers\\')) return NodeKind::PROVIDER;
        if (str_contains($className, '\\Resources\\')) return NodeKind::RESOURCE;
        if (str_contains($className, '\\Console\\')) return NodeKind::CONSOLE;
        if (str_contains($className, '\\Analysis\\')) return NodeKind::COMPONENT;
        if (str_contains($className, '\\Projectors\\')) return NodeKind::COMPONENT;
        if (str_contains($className, '\\Support\\')) return NodeKind::COMPONENT;

        return NodeKind::UNKNOWN;
    }
}
