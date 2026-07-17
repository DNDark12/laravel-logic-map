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
use PhpParser\Node\NullableType;
use PhpParser\Node\Scalar\Float_;
use PhpParser\Node\Scalar\Int_;
use PhpParser\Node\Scalar\String_;
use PhpParser\Node\Stmt;
use PhpParser\NodeVisitorAbstract;
use PhpParser\PrettyPrinter\Standard;

final class EloquentChainFactCollector extends NodeVisitorAbstract implements FileAwareFactCollector
{
    private const OPERATIONS = [
        'find', 'first', 'firstOrFail', 'get', 'pluck', 'count', 'exists', 'paginate', 'cursor', 'value',
        'save', 'create', 'update', 'delete', 'forceDelete', 'restore', 'increment', 'decrement', 'insert',
        'insertGetId', 'upsert', 'firstOrCreate', 'updateOrCreate', 'touch', 'attach', 'detach', 'sync',
    ];

    private const RAW_METHODS = ['select', 'statement', 'insert', 'update', 'delete', 'unprepared'];

    /** @var list<SemanticFact> */
    private array $facts = [];

    /** @var array<string, string> */
    private array $imports = [];

    /** @var list<array{identity: string, property_types: array<string, string>}> */
    private array $classStack = [];

    /** @var list<array{identity: string, parameter_types: array<string, string>}> */
    private array $methodStack = [];

    private string $namespace = '';

    private ?string $file = null;

    private Standard $printer;

    /** @var array<int, true> */
    private array $assignmentTargets = [];

    public function __construct()
    {
        $this->printer = new Standard();
    }

    public function name(): string
    {
        return 'eloquent_chains';
    }

    public function useFile(string $relativePath): void
    {
        $this->file = RelativePath::normalize($relativePath);
        $this->facts = [];
        $this->imports = [];
        $this->classStack = [];
        $this->methodStack = [];
        $this->namespace = '';
        $this->assignmentTargets = [];
    }

    public function enterNode(Node $node): null
    {
        if ($node instanceof Stmt\Namespace_) {
            $this->namespace = $node->name?->toString() ?? '';
        } elseif ($node instanceof Stmt\Use_) {
            $this->recordUse($node);
        } elseif ($node instanceof Stmt\GroupUse) {
            $this->recordGroupUse($node);
        } elseif ($node instanceof Stmt\ClassLike && $node->name !== null) {
            $this->classStack[] = [
                'identity' => ltrim((string) $node->namespacedName, '\\'),
                'property_types' => [],
            ];
        } elseif ($node instanceof Stmt\Property) {
            $this->recordProperty($node);
        } elseif ($node instanceof Stmt\ClassMethod) {
            $this->enterMethod($node);
        } elseif ($node instanceof Expr\Assign) {
            $this->assignmentTargets[spl_object_id($node->var)] = true;
        } elseif ($node instanceof Expr\PropertyFetch) {
            $this->collectPropertyRead($node);
        } elseif ($node instanceof Expr\MethodCall || $node instanceof Expr\StaticCall) {
            $this->collectCall($node);
        }

        return null;
    }

    public function leaveNode(Node $node): null
    {
        if ($node instanceof Expr\Assign) {
            unset($this->assignmentTargets[spl_object_id($node->var)]);
        } elseif ($node instanceof Stmt\ClassMethod && $this->methodStack !== []) {
            array_pop($this->methodStack);
        } elseif ($node instanceof Stmt\ClassLike && $node->name !== null && $this->classStack !== []) {
            array_pop($this->classStack);
        }

        return null;
    }

    public function facts(): array
    {
        $facts = $this->facts;
        $this->facts = [];

        return $facts;
    }

    private function collectCall(Expr\MethodCall|Expr\StaticCall $call): void
    {
        if ($this->methodStack === []) {
            return;
        }

        $terminal = $this->callName($call->name);
        $chain = $this->chain($call);

        if ($chain === null) {
            return;
        }

        $raw = $chain['receiver_class'] === 'Illuminate\Support\Facades\DB'
            && in_array($terminal, self::RAW_METHODS, true)
            && ($chain['segments'][0]['method'] ?? null) !== 'table';

        if (! in_array($terminal, self::OPERATIONS, true) && ! $raw) {
            return;
        }

        $origin = $raw
            ? 'raw_sql'
            : (($chain['receiver_class'] === 'Illuminate\Support\Facades\DB'
                && ($chain['segments'][0]['method'] ?? null) === 'table') ? 'query_builder' : 'eloquent');
        $tableArgument = $origin === 'query_builder'
            ? ($chain['segments'][0]['arguments'][0] ?? null)
            : null;
        $rawArgument = $raw ? ($chain['segments'][0]['arguments'][0] ?? null) : null;
        $this->facts[] = new SemanticFact(
            'eloquent_chain',
            $this->requireFile(),
            $call->getStartLine(),
            $call->getEndLine(),
            [
                'enclosing_symbol' => $this->methodStack[array_key_last($this->methodStack)]['identity'],
                'origin' => $origin,
                'receiver_class' => $chain['receiver_class'],
                'receiver_variable' => $chain['receiver_variable'],
                'table' => is_string($tableArgument) ? $tableArgument : null,
                'table_dynamic' => $origin === 'query_builder' && ! is_string($tableArgument),
                'terminal_method' => $terminal,
                'arguments' => $chain['segments'][array_key_last($chain['segments'])]['arguments'],
                'chain' => $chain['segments'],
                'raw_sql' => is_string($rawArgument) ? $rawArgument : null,
                'raw_sql_dynamic' => $raw && ! is_string($rawArgument),
                'expression' => $this->printer->prettyPrintExpr($call),
            ],
        );
    }

    private function collectPropertyRead(Expr\PropertyFetch $property): void
    {
        if ($this->methodStack === []
            || isset($this->assignmentTargets[spl_object_id($property)])
            || ! $property->var instanceof Expr\Variable
            || ! is_string($property->var->name)
            || ! $property->name instanceof Identifier) {
            return;
        }

        $method = $this->methodStack[array_key_last($this->methodStack)];
        $model = $method['parameter_types'][$property->var->name] ?? null;

        if (! is_string($model)) {
            return;
        }

        $this->facts[] = new SemanticFact(
            'eloquent_property_read',
            $this->file ?? throw new LogicException('Eloquent collector requires a file.'),
            $property->getStartLine(),
            $property->getEndLine(),
            [
                'enclosing_symbol' => $method['identity'],
                'receiver_class' => $model,
                'receiver_variable' => $property->var->name,
                'column' => $property->name->toString(),
                'expression' => $this->printer->prettyPrintExpr($property),
            ],
        );
    }

    private function chain(Expr\MethodCall|Expr\StaticCall $call): ?array
    {
        $segments = [];
        $current = $call;

        while ($current instanceof Expr\MethodCall) {
            array_unshift($segments, [
                'method' => $this->callName($current->name),
                'arguments' => array_map(fn ($argument): mixed => $this->summarize($argument->value), $current->args),
            ]);
            $current = $current->var;
        }

        if ($current instanceof Expr\StaticCall) {
            array_unshift($segments, [
                'method' => $this->callName($current->name),
                'arguments' => array_map(fn ($argument): mixed => $this->summarize($argument->value), $current->args),
            ]);
            $receiver = $current->class instanceof Name ? $this->resolveName($current->class) : null;

            return $receiver === null ? null : [
                'receiver_class' => $receiver,
                'receiver_variable' => null,
                'segments' => $segments,
            ];
        }

        if ($current instanceof Expr\Variable && is_string($current->name)) {
            $method = $this->methodStack[array_key_last($this->methodStack)];
            $class = $method['parameter_types'][$current->name] ?? null;

            return [
                'receiver_class' => $class,
                'receiver_variable' => $current->name,
                'segments' => $segments,
            ];
        }

        if ($current instanceof Expr\PropertyFetch
            && $current->var instanceof Expr\Variable
            && $current->var->name === 'this'
            && $current->name instanceof Identifier) {
            $class = $this->classStack[array_key_last($this->classStack)]['property_types'][$current->name->toString()] ?? null;

            return [
                'receiver_class' => $class,
                'receiver_variable' => null,
                'segments' => $segments,
            ];
        }

        return null;
    }

    private function enterMethod(Stmt\ClassMethod $method): void
    {
        if ($this->classStack === []) {
            return;
        }

        $parameters = [];

        foreach ($method->params as $parameter) {
            if ($parameter->var instanceof Expr\Variable && is_string($parameter->var->name)) {
                $type = $this->resolveType($parameter->type);

                if ($type !== null) {
                    $parameters[$parameter->var->name] = $type;
                }
            }
        }

        $owner = $this->classStack[array_key_last($this->classStack)]['identity'];
        $this->methodStack[] = [
            'identity' => 'method:'.$owner.'::'.$method->name->toString(),
            'parameter_types' => $parameters,
        ];
    }

    private function recordProperty(Stmt\Property $property): void
    {
        if ($this->classStack === []) {
            return;
        }

        $type = $this->resolveType($property->type);

        if ($type === null) {
            return;
        }

        $index = array_key_last($this->classStack);

        foreach ($property->props as $item) {
            $this->classStack[$index]['property_types'][$item->name->toString()] = $type;
        }
    }

    private function resolveType(?Node $type): ?string
    {
        if ($type instanceof NullableType) {
            return $this->resolveType($type->type);
        }

        if ($type instanceof Name) {
            return $this->resolveName($type);
        }

        if ($type instanceof Identifier) {
            return $type->toString();
        }

        return null;
    }

    private function summarize(Expr $expression): mixed
    {
        if ($expression instanceof String_ || $expression instanceof Int_ || $expression instanceof Float_) {
            return $expression->value;
        }

        if ($expression instanceof Expr\ConstFetch) {
            return match (strtolower($expression->name->toString())) {
                'null' => null,
                'true' => true,
                'false' => false,
                default => ['constant' => $expression->name->toString()],
            };
        }

        if ($expression instanceof Expr\Array_) {
            $items = [];

            foreach ($expression->items as $item) {
                if ($item === null) {
                    continue;
                }

                $items[] = [
                    'key' => $item->key instanceof Expr ? $this->summarize($item->key) : null,
                    'value' => $this->summarize($item->value),
                ];
            }

            return ['array' => $items];
        }

        return ['expression' => $this->printer->prettyPrintExpr($expression)];
    }

    private function callName(Node $name): string
    {
        return $name instanceof Identifier ? $name->toString() : $this->printer->prettyPrint([$name]);
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

    private function requireFile(): string
    {
        return $this->file ?? throw new LogicException('Eloquent chain collector requires a file.');
    }
}
