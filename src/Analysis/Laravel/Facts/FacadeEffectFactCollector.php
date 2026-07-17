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
use PhpParser\Node\InterpolatedStringPart;
use PhpParser\Node\Name;
use PhpParser\Node\Scalar\InterpolatedString;
use PhpParser\Node\Scalar\String_;
use PhpParser\Node\Stmt;
use PhpParser\NodeVisitorAbstract;
use PhpParser\PrettyPrinter\Standard;

final class FacadeEffectFactCollector extends NodeVisitorAbstract implements FileAwareFactCollector
{
    private const FAMILIES = [
        'Illuminate\Support\Facades\Cache' => 'cache',
        'Illuminate\Support\Facades\Config' => 'config',
        'Illuminate\Support\Facades\Storage' => 'storage',
        'Illuminate\Support\Facades\View' => 'view',
        'Illuminate\Support\Facades\Http' => 'http',
    ];

    private const METHODS = [
        'cache' => ['get', 'has', 'remember', 'rememberForever', 'put', 'add', 'forever', 'increment', 'decrement', 'forget', 'delete', 'pull'],
        'config' => ['get'],
        'storage' => ['get', 'read', 'exists', 'put', 'write', 'delete', 'append', 'prepend'],
        'view' => ['make'],
        'http' => ['get', 'post', 'put', 'patch', 'delete', 'send'],
    ];

    /** @var list<SemanticFact> */
    private array $facts = [];

    /** @var array<string, string> */
    private array $imports = [];

    /** @var list<string> */
    private array $classStack = [];

    /** @var list<string> */
    private array $methodStack = [];

    private string $namespace = '';

    private ?string $file = null;

    private Standard $printer;

    public function __construct()
    {
        $this->printer = new Standard();
    }

    public function name(): string
    {
        return 'facade_effects';
    }

    public function useFile(string $relativePath): void
    {
        $this->file = RelativePath::normalize($relativePath);
        $this->facts = [];
        $this->imports = [];
        $this->classStack = [];
        $this->methodStack = [];
        $this->namespace = '';
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
            $this->classStack[] = ltrim((string) $node->namespacedName, '\\');
        } elseif ($node instanceof Stmt\ClassMethod && $this->classStack !== []) {
            $this->methodStack[] = 'method:'.$this->classStack[array_key_last($this->classStack)]
                .'::'.$node->name->toString();
        } elseif ($node instanceof Expr\MethodCall || $node instanceof Expr\StaticCall || $node instanceof Expr\FuncCall) {
            $this->collect($node);
        }

        return null;
    }

    public function leaveNode(Node $node): null
    {
        if ($node instanceof Stmt\ClassMethod && $this->methodStack !== []) {
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

    private function collect(Expr\MethodCall|Expr\StaticCall|Expr\FuncCall $call): void
    {
        if ($this->methodStack === []) {
            return;
        }

        $chain = $this->chain($call);

        if ($chain === null) {
            return;
        }

        $family = $chain['family'];
        $terminal = $chain['segments'][array_key_last($chain['segments'])]['method'];
        $allowed = $family === 'config' && $terminal === 'config'
            || $family === 'view' && $terminal === 'view'
            || in_array($terminal, self::METHODS[$family] ?? [], true);

        if (! $allowed) {
            return;
        }

        $this->facts[] = new SemanticFact(
            'facade_effect',
            $this->file ?? throw new LogicException('Facade effect collector requires a file.'),
            $call->getStartLine(),
            $call->getEndLine(),
            [
                'enclosing_symbol' => $this->methodStack[array_key_last($this->methodStack)],
                'family' => $family,
                'method' => $terminal,
                'arguments' => $chain['segments'][array_key_last($chain['segments'])]['arguments'],
                'chain' => $chain['segments'],
                'expression' => $this->printer->prettyPrintExpr($call),
            ],
        );
    }

    private function chain(Expr\MethodCall|Expr\StaticCall|Expr\FuncCall $call): ?array
    {
        $segments = [];
        $current = $call;

        while ($current instanceof Expr\MethodCall) {
            array_unshift($segments, [
                'method' => $this->callName($current->name),
                'arguments' => array_map(fn ($argument): array => $this->structure($argument->value), $current->args),
            ]);
            $current = $current->var;
        }

        if ($current instanceof Expr\StaticCall && $current->class instanceof Name) {
            $receiver = $this->resolveName($current->class);
            $family = self::FAMILIES[$receiver] ?? null;

            if ($family === null) {
                return null;
            }

            array_unshift($segments, [
                'method' => $this->callName($current->name),
                'arguments' => array_map(fn ($argument): array => $this->structure($argument->value), $current->args),
            ]);

            return ['family' => $family, 'segments' => $segments];
        }

        if ($current instanceof Expr\FuncCall && $current->name instanceof Name) {
            $name = $this->baseName($current->name->toString());

            if (! in_array($name, ['config', 'view'], true)) {
                return null;
            }

            array_unshift($segments, [
                'method' => $name,
                'arguments' => array_map(fn ($argument): array => $this->structure($argument->value), $current->args),
            ]);

            return ['family' => $name, 'segments' => $segments];
        }

        return null;
    }

    private function structure(Expr $expression): array
    {
        if ($expression instanceof String_) {
            return ['literal' => $expression->value];
        }

        if ($expression instanceof InterpolatedString) {
            $parts = [];

            foreach ($expression->parts as $part) {
                $parts[] = $part instanceof InterpolatedStringPart
                    ? ['literal' => $part->value]
                    : $this->structure($part);
            }

            return ['concat' => $parts];
        }

        if ($expression instanceof Expr\BinaryOp\Concat) {
            return ['concat' => [$this->structure($expression->left), $this->structure($expression->right)]];
        }

        if ($expression instanceof Expr\Variable && is_string($expression->name)) {
            return ['placeholder' => $expression->name];
        }

        if ($expression instanceof Expr\FuncCall
            && $expression->name instanceof Name
            && $this->baseName($expression->name->toString()) === 'config'
            && isset($expression->args[0])
            && $expression->args[0]->value instanceof String_) {
            return ['config' => $expression->args[0]->value->value];
        }

        if ($expression instanceof Expr\MethodCall) {
            $method = $this->callName($expression->name);

            return ['placeholder' => $method === 'getKey' ? 'id' : $method];
        }

        if ($expression instanceof Expr\PropertyFetch && $expression->name instanceof Identifier) {
            return ['placeholder' => $expression->name->toString()];
        }

        return ['placeholder' => 'value'];
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

    private function baseName(string $name): string
    {
        $position = strrpos($name, '\\');

        return $position === false ? $name : substr($name, $position + 1);
    }
}
