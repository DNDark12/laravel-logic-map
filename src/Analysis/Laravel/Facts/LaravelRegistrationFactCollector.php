<?php

namespace DNDark\LogicMap\Analysis\Laravel\Facts;

use DNDark\LogicMap\Analysis\Facts\FileAwareFactCollector;
use DNDark\LogicMap\Analysis\Facts\SemanticFact;
use DNDark\LogicMap\Analysis\Php\PhpNameResolver;
use DNDark\LogicMap\Support\RelativePath;
use LogicException;
use PhpParser\Node;
use PhpParser\Node\Expr;
use PhpParser\Node\Identifier;
use PhpParser\Node\Name;
use PhpParser\Node\Scalar\String_;
use PhpParser\Node\Stmt;
use PhpParser\NodeVisitorAbstract;
use PhpParser\PrettyPrinter\Standard;

final class LaravelRegistrationFactCollector extends NodeVisitorAbstract implements FileAwareFactCollector
{
    /** @var list<SemanticFact> */
    private array $facts = [];

    private ?string $file = null;

    /** @var array<string, string> */
    private array $imports = [];

    private string $namespace = '';

    private Standard $printer;

    public function __construct()
    {
        $this->printer = new Standard();
    }

    public function name(): string
    {
        return 'laravel_registrations';
    }

    public function useFile(string $relativePath): void
    {
        $this->file = RelativePath::normalize($relativePath);
        $this->imports = [];
        $this->namespace = '';
    }

    public function enterNode(Node $node): null
    {
        if ($node instanceof Stmt\Namespace_) {
            $this->namespace = $node->name?->toString() ?? '';
        }

        if ($node instanceof Stmt\Use_) {
            $this->recordUse($node);
        }

        if ($node instanceof Stmt\GroupUse) {
            $this->recordGroupUse($node);
        }

        if ($node instanceof Expr\StaticCall) {
            $this->collectRoute($node);
            $this->collectPolicy($node);
        }

        if ($node instanceof Expr\MethodCall) {
            $this->collectRouteChain($node);
            $this->collectBinding($node);
        }

        return null;
    }

    public function facts(): array
    {
        $facts = $this->facts;
        $this->facts = [];

        return $facts;
    }

    private function collectRoute(Expr\StaticCall $call): void
    {
        $method = $this->callName($call->name);

        if (! $this->isFacade($call->class, 'Illuminate\Support\Facades\Route')
            || ! in_array(strtolower($method), [
                'get', 'post', 'put', 'patch', 'delete', 'options', 'any', 'match',
            ], true)) {
            return;
        }

        [$methods, $uriArgument, $actionArgument] = $this->routeArguments($call, strtolower($method));
        [$actionClass, $actionMethod] = $this->routeAction($actionArgument);
        $uri = $uriArgument instanceof String_ ? $uriArgument->value : null;
        $registrationKey = $this->routeRegistrationKey($call);

        $this->addFact('laravel_route_registration', $call, [
            'methods' => $methods,
            'uri' => $uri,
            'action_class' => $actionClass,
            'action_method' => $actionMethod,
            'dynamic' => $uri === null || $actionClass === null || $actionMethod === null,
            'registration_key' => $registrationKey,
            'expression' => $this->printer->prettyPrintExpr($call),
        ]);
    }

    private function collectRouteChain(Expr\MethodCall $call): void
    {
        $operation = strtolower($this->callName($call->name));

        if (! in_array($operation, ['middleware', 'name'], true)) {
            return;
        }

        $root = $this->routeRoot($call);

        if ($root === null) {
            return;
        }

        $attributes = [
            'operation' => $operation,
            'registration_key' => $this->routeRegistrationKey($root),
            'expression' => $this->printer->prettyPrintExpr($call),
        ];

        if ($operation === 'name') {
            $attributes['name'] = isset($call->args[0]) && $call->args[0]->value instanceof String_
                ? $call->args[0]->value->value
                : null;
        } else {
            $attributes['middleware'] = isset($call->args[0])
                ? $this->stringList($call->args[0]->value)
                : null;
        }

        $this->addFact('laravel_route_chain', $call, $attributes);
    }

    private function collectBinding(Expr\MethodCall $call): void
    {
        $method = strtolower($this->callName($call->name));

        if (! in_array($method, ['bind', 'singleton'], true) || ! $this->isApplicationProperty($call->var)) {
            return;
        }

        $abstract = isset($call->args[0]) ? $this->classConstant($call->args[0]->value) : null;
        $concreteExpression = $call->args[1]->value ?? null;
        $closure = $concreteExpression instanceof Expr\Closure || $concreteExpression instanceof Expr\ArrowFunction;
        $concrete = $concreteExpression instanceof Expr
            ? ($this->classConstant($concreteExpression) ?? $this->closureReturnedClass($concreteExpression))
            : null;

        $this->addFact('laravel_container_binding', $call, [
            'abstract' => $abstract,
            'concrete' => $concrete,
            'shared' => $method === 'singleton',
            'closure' => $closure,
            'dynamic' => $abstract === null || $concrete === null,
            'registration_key' => $abstract !== null && $concrete !== null
                ? 'binding:'.$abstract.'=>'.$concrete
                : 'binding:'.$this->file.':'.$call->getStartLine().':'.$call->getEndLine(),
            'expression' => $this->printer->prettyPrintExpr($call),
        ]);
    }

    private function collectPolicy(Expr\StaticCall $call): void
    {
        if (! $this->isFacade($call->class, 'Illuminate\Support\Facades\Gate')
            || strtolower($this->callName($call->name)) !== 'policy') {
            return;
        }

        $model = isset($call->args[0]) ? $this->classConstant($call->args[0]->value) : null;
        $policy = isset($call->args[1]) ? $this->classConstant($call->args[1]->value) : null;

        $this->addFact('laravel_policy_registration', $call, [
            'model' => $model,
            'policy' => $policy,
            'dynamic' => $model === null || $policy === null,
            'registration_key' => $model !== null && $policy !== null
                ? 'policy:'.$model.'=>'.$policy
                : 'policy:'.$this->file.':'.$call->getStartLine().':'.$call->getEndLine(),
            'expression' => $this->printer->prettyPrintExpr($call),
        ]);
    }

    private function routeArguments(Expr\StaticCall $call, string $method): array
    {
        if ($method === 'match') {
            return [
                isset($call->args[0]) ? $this->httpMethods($call->args[0]->value) : [],
                $call->args[1]->value ?? null,
                $call->args[2]->value ?? null,
            ];
        }

        $methods = $method === 'any'
            ? ['GET', 'POST', 'PUT', 'PATCH', 'DELETE', 'OPTIONS']
            : [strtoupper($method)];

        return [$methods, $call->args[0]->value ?? null, $call->args[1]->value ?? null];
    }

    private function routeAction(?Expr $expression): array
    {
        if (! $expression instanceof Expr\Array_ || count($expression->items) !== 2) {
            return [null, null];
        }

        $class = $expression->items[0]?->value;
        $method = $expression->items[1]?->value;

        return [
            $class instanceof Expr ? $this->classConstant($class) : null,
            $method instanceof String_ ? $method->value : null,
        ];
    }

    private function routeRoot(Expr\MethodCall $call): ?Expr\StaticCall
    {
        $current = $call->var;

        while ($current instanceof Expr\MethodCall) {
            $current = $current->var;
        }

        if (! $current instanceof Expr\StaticCall
            || ! $this->isFacade($current->class, 'Illuminate\Support\Facades\Route')) {
            return null;
        }

        return $current;
    }

    private function routeRegistrationKey(Expr\StaticCall $call): string
    {
        return 'route:'.$this->requireFile().':'.$call->getStartLine().':'.$call->getEndLine();
    }

    private function httpMethods(Expr $expression): array
    {
        $methods = $this->stringList($expression) ?? [];
        $methods = array_values(array_unique(array_map('strtoupper', $methods)));

        return array_values(array_filter(
            $methods,
            static fn (string $method): bool => $method !== 'HEAD' || ! in_array('GET', $methods, true),
        ));
    }

    private function stringList(Expr $expression): ?array
    {
        if ($expression instanceof String_) {
            return [$expression->value];
        }

        if (! $expression instanceof Expr\Array_) {
            return null;
        }

        $values = [];

        foreach ($expression->items as $item) {
            if ($item === null || ! $item->value instanceof String_) {
                return null;
            }

            $values[] = $item->value->value;
        }

        return $values;
    }

    private function classConstant(Expr $expression): ?string
    {
        if (! $expression instanceof Expr\ClassConstFetch
            || ! $expression->name instanceof Identifier
            || strtolower($expression->name->toString()) !== 'class'
            || ! $expression->class instanceof Name) {
            return null;
        }

        return $this->resolveName($expression->class);
    }

    private function closureReturnedClass(Expr $expression): ?string
    {
        if ($expression instanceof Expr\ArrowFunction) {
            return $this->classConstant($expression->expr);
        }

        if (! $expression instanceof Expr\Closure) {
            return null;
        }

        $returns = array_values(array_filter(
            $expression->stmts,
            static fn (Stmt $statement): bool => $statement instanceof Stmt\Return_,
        ));

        if (count($returns) !== 1 || ! $returns[0]->expr instanceof Expr) {
            return null;
        }

        return $this->classConstant($returns[0]->expr);
    }

    private function isFacade(Node $receiver, string $class): bool
    {
        return $receiver instanceof Name && $this->resolveName($receiver) === $class;
    }

    private function isApplicationProperty(Expr $receiver): bool
    {
        return $receiver instanceof Expr\PropertyFetch
            && $receiver->var instanceof Expr\Variable
            && $receiver->var->name === 'this'
            && $receiver->name instanceof Identifier
            && $receiver->name->toString() === 'app';
    }

    private function callName(Node $name): string
    {
        return $name instanceof Identifier ? $name->toString() : $this->printer->prettyPrint([$name]);
    }

    private function addFact(string $kind, Node $node, array $attributes): void
    {
        $this->facts[] = new SemanticFact(
            $kind,
            $this->requireFile(),
            $node->getStartLine(),
            $node->getEndLine(),
            $attributes,
        );
    }

    private function requireFile(): string
    {
        return $this->file ?? throw new LogicException('Laravel registration collector requires a file.');
    }

    private function recordUse(Stmt\Use_ $use): void
    {
        foreach ($use->uses as $item) {
            $this->imports[$item->getAlias()->toString()] = ltrim($item->name->toString(), '\\');
        }
    }

    private function recordGroupUse(Stmt\GroupUse $use): void
    {
        foreach ($use->uses as $item) {
            $this->imports[$item->getAlias()->toString()] = ltrim(
                $use->prefix->toString().'\\'.$item->name->toString(),
                '\\',
            );
        }
    }

    private function resolveName(Name $name): string
    {
        if ($name->isFullyQualified()) {
            return ltrim($name->toString(), '\\');
        }

        $parts = $name->getParts();
        $first = $parts[0] ?? '';

        if (isset($this->imports[$first])) {
            array_shift($parts);

            return $this->imports[$first].($parts === [] ? '' : '\\'.implode('\\', $parts));
        }

        $literal = $name->toString();

        return $this->namespace === '' ? $literal : $this->namespace.'\\'.$literal;
    }
}
