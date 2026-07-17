<?php

namespace DNDark\LogicMap\Analysis\Php;

use DNDark\LogicMap\Analysis\Facts\CallSiteFact;
use DNDark\LogicMap\Analysis\Facts\InheritanceFact;
use DNDark\LogicMap\Domain\Graph\NodeId;
use DNDark\LogicMap\Domain\Graph\NodeKind;
use DNDark\LogicMap\Domain\Graph\SourceLocation;
use PhpParser\Node;
use PhpParser\Node\Arg;
use PhpParser\Node\Expr;
use PhpParser\Node\Identifier;
use PhpParser\Node\Name;
use PhpParser\Node\Scalar\Float_;
use PhpParser\Node\Scalar\Int_;
use PhpParser\Node\Scalar\String_;
use PhpParser\Node\Stmt;
use PhpParser\NodeVisitorAbstract;
use PhpParser\PrettyPrinter\Standard;

final class PhpSymbolCallVisitor extends NodeVisitorAbstract
{
    /** @var list<SymbolDefinition> */
    private array $symbols = [];

    /** @var list<InheritanceFact> */
    private array $inheritanceFacts = [];

    /** @var list<CallSiteFact> */
    private array $callSites = [];

    /** @var array<string, string> */
    private array $imports = [];

    private string $namespace = '';

    /** @var array<int, array<string, mixed>> */
    private array $classes = [];

    /** @var list<int> */
    private array $classStack = [];

    /** @var list<array{id: NodeId, parameter_types: array<string, string>}> */
    private array $methodStack = [];

    /** @var array<int, string> */
    private array $anonymousIdentities = [];

    private int $anonymousOrdinal = 0;

    private readonly Standard $printer;

    public function __construct(
        private readonly string $file,
        private readonly int $expressionMaxLength = 500,
        private readonly ?ControlContextStack $controlContexts = null,
    ) {
        $this->printer = new Standard();
    }

    public function enterNode(Node $node): null
    {
        if ($node instanceof Stmt\Namespace_) {
            $this->namespace = $node->name?->toString() ?? '';
        } elseif ($node instanceof Stmt\Use_) {
            $this->recordUse($node);
        } elseif ($node instanceof Stmt\GroupUse) {
            $this->recordGroupUse($node);
        } elseif ($node instanceof Expr\New_) {
            $this->prepareAnonymousIdentity($node);
            $this->recordCall($node);
        } elseif ($this->isClassLike($node)) {
            $this->enterClassLike($node);
        } elseif ($node instanceof Stmt\TraitUse) {
            $this->recordTraitUse($node);
        } elseif ($node instanceof Stmt\Property) {
            $this->recordPropertyTypes($node);
        } elseif ($node instanceof Stmt\ClassMethod) {
            $this->enterMethod($node);
        } elseif (
            $node instanceof Expr\MethodCall
            || $node instanceof Expr\NullsafeMethodCall
            || $node instanceof Expr\StaticCall
            || $node instanceof Expr\FuncCall
        ) {
            $this->recordCall($node);
        }

        return null;
    }

    public function leaveNode(Node $node): null
    {
        if ($node instanceof Stmt\ClassMethod) {
            array_pop($this->methodStack);
        } elseif ($this->isClassLike($node)) {
            $this->leaveClassLike($node);
        }

        return null;
    }

    /** @return list<SymbolDefinition> */
    public function symbols(): array
    {
        $symbols = $this->symbols;
        usort($symbols, self::compareSymbols(...));

        return $symbols;
    }

    /** @return array<string, string> */
    public function imports(): array
    {
        $imports = $this->imports;
        ksort($imports, SORT_STRING);

        return $imports;
    }

    /** @return list<InheritanceFact> */
    public function inheritanceFacts(): array
    {
        $facts = $this->inheritanceFacts;
        usort($facts, static fn (InheritanceFact $left, InheritanceFact $right): int => [
            $left->startLine,
            $left->endLine,
            $left->relation,
            $left->targetQualifiedName,
        ] <=> [
            $right->startLine,
            $right->endLine,
            $right->relation,
            $right->targetQualifiedName,
        ]);

        return $facts;
    }

    /** @return list<CallSiteFact> */
    public function callSites(): array
    {
        $calls = $this->callSites;
        usort($calls, static fn (CallSiteFact $left, CallSiteFact $right): int => [
            $left->startLine,
            $left->endLine,
            $left->normalizedExpression,
        ] <=> [
            $right->startLine,
            $right->endLine,
            $right->normalizedExpression,
        ]);

        return $calls;
    }

    private function recordUse(Stmt\Use_ $node): void
    {
        foreach ($node->uses as $use) {
            $qualifiedName = PhpNameResolver::name($use->name);
            $this->imports[$use->getAlias()->toString()] = $qualifiedName;
        }
    }

    private function recordGroupUse(Stmt\GroupUse $node): void
    {
        foreach ($node->uses as $use) {
            $qualifiedName = PhpNameResolver::name(Name::concat($node->prefix, $use->name));
            $this->imports[$use->getAlias()->toString()] = $qualifiedName;
        }
    }

    private function prepareAnonymousIdentity(Expr\New_ $node): void
    {
        if (! $node->class instanceof Stmt\Class_) {
            return;
        }

        $key = spl_object_id($node->class);

        if (! isset($this->anonymousIdentities[$key])) {
            $this->anonymousIdentities[$key] = $this->file.'@anonymous['.$this->anonymousOrdinal++.']';
        }
    }

    private function enterClassLike(Stmt\ClassLike $node): void
    {
        $key = spl_object_id($node);
        $kind = match (true) {
            $node instanceof Stmt\Interface_ => NodeKind::InterfaceSymbol,
            $node instanceof Stmt\Trait_ => NodeKind::TraitSymbol,
            $node instanceof Stmt\Enum_ => NodeKind::EnumSymbol,
            default => NodeKind::ClassSymbol,
        };
        $anonymous = $node instanceof Stmt\Class_ && $node->name === null;
        $identity = $anonymous
            ? ($this->anonymousIdentities[$key] ??= $this->file.'@anonymous['.$this->anonymousOrdinal++.']')
            : ltrim((string) $node->namespacedName, '\\');
        $id = $anonymous
            ? NodeId::fromString('class:'.$identity)
            : NodeId::symbol($kind, $identity);
        $name = $anonymous ? substr($identity, strrpos($identity, '@') + 1) : $node->name->toString();

        $this->classes[$key] = [
            'id' => $id,
            'kind' => $kind,
            'name' => $name,
            'identity' => $identity,
            'qualified_name' => $anonymous ? null : $identity,
            'location' => new SourceLocation($this->file, $node->getStartLine(), $node->getEndLine()),
            'property_types' => [],
            'extends' => [],
            'implements' => [],
            'uses_traits' => [],
            'attributes' => [
                'anonymous' => $anonymous,
                'abstract' => method_exists($node, 'isAbstract') && $node->isAbstract(),
                'final' => method_exists($node, 'isFinal') && $node->isFinal(),
                'readonly' => method_exists($node, 'isReadonly') && $node->isReadonly(),
            ],
        ];
        $this->classStack[] = $key;
        $this->recordDeclaredInheritance($node, $key);
    }

    private function recordDeclaredInheritance(Stmt\ClassLike $node, int $key): void
    {
        $relations = [];

        if ($node instanceof Stmt\Class_ && $node->extends !== null) {
            $relations[] = ['extends', $node->extends];
        }

        if ($node instanceof Stmt\Interface_) {
            foreach ($node->extends as $target) {
                $relations[] = ['extends', $target];
            }
        }

        if ($node instanceof Stmt\Class_ || $node instanceof Stmt\Enum_) {
            foreach ($node->implements as $target) {
                $relations[] = ['implements', $target];
            }
        }

        foreach ($relations as [$relation, $target]) {
            $qualifiedName = PhpNameResolver::name($target);
            $attribute = $relation === 'implements' ? 'implements' : 'extends';
            $this->classes[$key][$attribute][] = $qualifiedName;
            $this->inheritanceFacts[] = new InheritanceFact(
                $this->file,
                $target->getStartLine(),
                $target->getEndLine(),
                $this->classes[$key]['id'],
                $relation,
                $qualifiedName,
            );
        }
    }

    private function recordTraitUse(Stmt\TraitUse $node): void
    {
        $key = $this->currentClassKey();

        if ($key === null) {
            return;
        }

        foreach ($node->traits as $trait) {
            $qualifiedName = PhpNameResolver::name($trait);
            $this->classes[$key]['uses_traits'][] = $qualifiedName;
            $this->inheritanceFacts[] = new InheritanceFact(
                $this->file,
                $trait->getStartLine(),
                $trait->getEndLine(),
                $this->classes[$key]['id'],
                'uses_trait',
                $qualifiedName,
            );
        }
    }

    private function recordPropertyTypes(Stmt\Property $node): void
    {
        $key = $this->currentClassKey();

        if ($key === null) {
            return;
        }

        $type = PhpNameResolver::type($node->type);

        foreach ($node->props as $property) {
            if ($type !== null) {
                $this->classes[$key]['property_types'][$property->name->toString()] = $type;
            }
        }
    }

    private function enterMethod(Stmt\ClassMethod $node): void
    {
        $key = $this->currentClassKey();

        if ($key === null) {
            return;
        }

        $parameterTypes = [];

        foreach ($node->params as $parameter) {
            $name = $parameter->var instanceof Expr\Variable && is_string($parameter->var->name)
                ? $parameter->var->name
                : null;
            $type = PhpNameResolver::type($parameter->type);

            if ($name !== null && $type !== null) {
                $parameterTypes[$name] = $type;

                if ($parameter->flags !== 0) {
                    $this->classes[$key]['property_types'][$name] = $type;
                }
            }
        }

        ksort($parameterTypes, SORT_STRING);
        $methodName = $node->name->toString();
        $methodId = NodeId::method($this->classes[$key]['identity'], $methodName);
        $qualifiedName = $this->classes[$key]['identity'].'::'.$methodName;
        $this->symbols[] = new SymbolDefinition(
            $methodId,
            NodeKind::Method,
            $methodName,
            $qualifiedName,
            new SourceLocation($this->file, $node->getStartLine(), $node->getEndLine()),
            $parameterTypes,
            [],
            PhpNameResolver::type($node->returnType),
            [
                'owner_id' => $this->classes[$key]['id']->value,
                'visibility' => $node->isPrivate() ? 'private' : ($node->isProtected() ? 'protected' : 'public'),
                'static' => $node->isStatic(),
                'abstract' => $node->isAbstract(),
                'final' => $node->isFinal(),
            ],
        );
        $this->methodStack[] = ['id' => $methodId, 'parameter_types' => $parameterTypes];
    }

    private function leaveClassLike(Stmt\ClassLike $node): void
    {
        $key = spl_object_id($node);
        $class = $this->classes[$key];
        ksort($class['property_types'], SORT_STRING);
        sort($class['extends'], SORT_STRING);
        sort($class['implements'], SORT_STRING);
        sort($class['uses_traits'], SORT_STRING);
        $attributes = $class['attributes'] + [
            'extends' => $class['extends'],
            'implements' => $class['implements'],
            'uses_traits' => $class['uses_traits'],
        ];

        $this->symbols[] = new SymbolDefinition(
            $class['id'],
            $class['kind'],
            $class['name'],
            $class['qualified_name'],
            $class['location'],
            [],
            $class['property_types'],
            null,
            $attributes,
        );

        array_pop($this->classStack);
    }

    private function recordCall(Expr\CallLike $node): void
    {
        $enclosing = $this->currentEnclosingSymbol();

        if ($enclosing === null) {
            return;
        }

        [$kind, $receiver, $receiverType, $target, $nullsafe] = $this->describeCall($node);

        if ($target === '') {
            return;
        }

        $firstClassCallable = $node->isFirstClassCallable();
        $arguments = $firstClassCallable ? [] : array_map($this->summarizeArgument(...), $node->getArgs());
        $this->callSites[] = new CallSiteFact(
            $this->file,
            $node->getStartLine(),
            $node->getEndLine(),
            $enclosing,
            $kind,
            $receiver === null ? null : $this->bounded($receiver),
            $receiverType,
            $target,
            $arguments,
            $this->bounded($this->printer->prettyPrintExpr($node)),
            [
                'nullsafe' => $nullsafe,
                'first_class_callable' => $firstClassCallable,
            ],
            $this->controlContexts?->contextArraysForSpan(
                $node->getStartLine(),
                $node->getEndLine(),
            ) ?? [],
        );
    }

    /** @return array{string, ?string, ?string, string, bool} */
    private function describeCall(Expr\CallLike $node): array
    {
        if ($node instanceof Expr\MethodCall || $node instanceof Expr\NullsafeMethodCall) {
            return [
                $node instanceof Expr\NullsafeMethodCall ? 'nullsafe_method' : 'method',
                $this->printer->prettyPrintExpr($node->var),
                $this->receiverType($node->var),
                $this->callName($node->name),
                $node instanceof Expr\NullsafeMethodCall,
            ];
        }

        if ($node instanceof Expr\StaticCall) {
            $receiver = $node->class instanceof Name
                ? PhpNameResolver::name($node->class)
                : $this->printer->prettyPrintExpr($node->class);

            return ['static', $receiver, $receiver, $this->callName($node->name), false];
        }

        if ($node instanceof Expr\FuncCall) {
            $name = $node->name instanceof Name
                ? PhpNameResolver::name($node->name)
                : $this->printer->prettyPrintExpr($node->name);

            return ['function', null, null, $name, false];
        }

        if ($node instanceof Expr\New_) {
            if ($node->class instanceof Stmt\Class_) {
                $identity = $this->anonymousIdentities[spl_object_id($node->class)];

                return ['new', $identity, $identity, $identity, false];
            }

            $name = $node->class instanceof Name
                ? PhpNameResolver::name($node->class)
                : $this->printer->prettyPrintExpr($node->class);

            return ['new', $name, $name, $name, false];
        }

        return ['unknown', null, null, '', false];
    }

    private function receiverType(Expr $receiver): ?string
    {
        if ($receiver instanceof Expr\Variable && is_string($receiver->name)) {
            if ($receiver->name === 'this') {
                $key = $this->currentClassKey();

                return $key === null ? null : $this->classes[$key]['identity'];
            }

            $method = $this->methodStack[array_key_last($this->methodStack)] ?? null;

            return $method['parameter_types'][$receiver->name] ?? null;
        }

        if (
            ($receiver instanceof Expr\PropertyFetch || $receiver instanceof Expr\NullsafePropertyFetch)
            && $receiver->var instanceof Expr\Variable
            && $receiver->var->name === 'this'
            && $receiver->name instanceof Identifier
        ) {
            $key = $this->currentClassKey();

            return $key === null ? null : ($this->classes[$key]['property_types'][$receiver->name->toString()] ?? null);
        }

        if ($receiver instanceof Expr\New_) {
            if ($receiver->class instanceof Name) {
                return PhpNameResolver::name($receiver->class);
            }

            if ($receiver->class instanceof Stmt\Class_) {
                return $this->anonymousIdentities[spl_object_id($receiver->class)] ?? null;
            }
        }

        return null;
    }

    private function callName(Node $name): string
    {
        if ($name instanceof Identifier) {
            return $name->toString();
        }

        if ($name instanceof Name) {
            return PhpNameResolver::name($name);
        }

        return $name instanceof Expr ? $this->printer->prettyPrintExpr($name) : (string) $name;
    }

    private function summarizeArgument(Arg $argument): mixed
    {
        return $this->summarizeExpression($argument->value);
    }

    private function summarizeExpression(Expr $expression): mixed
    {
        if ($expression instanceof String_ || $expression instanceof Int_ || $expression instanceof Float_) {
            return $expression->value;
        }

        if ($expression instanceof Expr\ConstFetch) {
            return match (strtolower($expression->name->toString())) {
                'null' => null,
                'true' => true,
                'false' => false,
                default => ['constant' => PhpNameResolver::name($expression->name)],
            };
        }

        if ($expression instanceof Expr\ClassConstFetch) {
            $class = $expression->class instanceof Name
                ? $this->resolveReferencedName($expression->class)
                : $this->printer->prettyPrintExpr($expression->class);

            return ['class_constant' => $class.'::'.$this->callName($expression->name)];
        }

        if ($expression instanceof Expr\Array_) {
            $items = [];

            foreach ($expression->items as $item) {
                if ($item === null) {
                    continue;
                }

                $key = $item->key === null ? null : $this->summarizeExpression($item->key);
                $value = $this->summarizeExpression($item->value);
                $items[] = ['key' => $key, 'value' => $value];
            }

            return ['array' => $items];
        }

        return ['expression' => $this->printer->prettyPrintExpr($expression)];
    }

    private function resolveReferencedName(Name $name): string
    {
        $resolved = PhpNameResolver::name($name);

        if ($name->isFullyQualified() || $resolved !== $name->toString()) {
            return $resolved;
        }

        $parts = $name->getParts();
        $first = $parts[0] ?? '';

        if (isset($this->imports[$first])) {
            array_shift($parts);

            return $this->imports[$first].($parts === [] ? '' : '\\'.implode('\\', $parts));
        }

        return $this->namespace === '' ? $resolved : $this->namespace.'\\'.$resolved;
    }

    private function currentClassKey(): ?int
    {
        return $this->classStack[array_key_last($this->classStack)] ?? null;
    }

    private function currentEnclosingSymbol(): ?NodeId
    {
        $method = $this->methodStack[array_key_last($this->methodStack)] ?? null;

        if ($method !== null) {
            return $method['id'];
        }

        $key = $this->currentClassKey();

        return $key === null ? null : $this->classes[$key]['id'];
    }

    private function isClassLike(Node $node): bool
    {
        return $node instanceof Stmt\Class_
            || $node instanceof Stmt\Interface_
            || $node instanceof Stmt\Trait_
            || $node instanceof Stmt\Enum_;
    }

    private static function compareSymbols(SymbolDefinition $left, SymbolDefinition $right): int
    {
        return [
            $left->location->file,
            $left->location->startLine,
            $left->location->endLine,
            $left->id->value,
        ] <=> [
            $right->location->file,
            $right->location->startLine,
            $right->location->endLine,
            $right->id->value,
        ];
    }

    private function bounded(string $value): string
    {
        return substr($value, 0, $this->expressionMaxLength);
    }
}
