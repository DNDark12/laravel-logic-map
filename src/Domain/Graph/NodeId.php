<?php

namespace DNDark\LogicMap\Domain\Graph;

use DNDark\LogicMap\Support\RelativePath;
use InvalidArgumentException;

/** Anonymous classes use class:{relativePath}@anonymous[{zeroBasedOrdinal}]. */
final readonly class NodeId
{
    private const PREFIXES = [
        'file', 'class', 'interface', 'trait', 'enum', 'method', 'route',
        'middleware', 'command', 'schedule', 'table', 'column', 'cache',
        'config', 'storage', 'external', 'view', 'module', 'process',
        'decision', 'test', 'unknown',
    ];

    private function __construct(public string $value)
    {
        $separator = strpos($value, ':');
        $prefix = $separator === false ? '' : substr($value, 0, $separator);

        if (
            $value === ''
            || $separator === false
            || $separator === strlen($value) - 1
            || ! in_array($prefix, self::PREFIXES, true)
            || preg_match('/[\x00-\x1F\x7F]/', $value) === 1
        ) {
            throw new InvalidArgumentException(
                'Node ID must use a closed non-empty prefix/key form and contain no control characters.',
            );
        }
    }

    public static function fromString(string $value): self
    {
        return new self($value);
    }

    public static function file(string $relativePath): self
    {
        return new self('file:'.RelativePath::normalize($relativePath));
    }

    public static function symbol(NodeKind $kind, string $qualifiedName): self
    {
        if (! in_array($kind, [
            NodeKind::ClassSymbol,
            NodeKind::InterfaceSymbol,
            NodeKind::TraitSymbol,
            NodeKind::EnumSymbol,
        ], true)) {
            throw new InvalidArgumentException(
                'Class-like node IDs require class, interface, trait, or enum structural kinds.',
            );
        }

        return new self($kind->value.':'.ltrim($qualifiedName, '\\'));
    }

    public static function method(string $class, string $method): self
    {
        return new self('method:'.ltrim($class, '\\').'::'.$method);
    }

    public static function route(string $method, string $uri): self
    {
        $normalizedUri = trim($uri, '/');

        return new self('route:'.strtoupper($method).':'.($normalizedUri === '' ? '/' : $normalizedUri));
    }

    public static function named(NodeKind $kind, string $key): self
    {
        $prefix = match ($kind) {
            NodeKind::Middleware => 'middleware',
            NodeKind::Command => 'command',
            NodeKind::Schedule => 'schedule',
            NodeKind::Table => 'table',
            NodeKind::Column => 'column',
            NodeKind::CacheKey => 'cache',
            NodeKind::ConfigKey => 'config',
            NodeKind::StoragePath => 'storage',
            NodeKind::ExternalEndpoint => 'external',
            NodeKind::View => 'view',
            NodeKind::Module => 'module',
            NodeKind::Process => 'process',
            NodeKind::Decision => 'decision',
            NodeKind::Test => 'test',
            NodeKind::Unknown => 'unknown',
            default => throw new InvalidArgumentException(
                'This node kind requires a structural, method, route, or file identity factory.',
            ),
        };

        $normalizedKey = trim($key);

        if ($normalizedKey === '') {
            throw new InvalidArgumentException('Named node keys must be non-empty.');
        }

        return new self($prefix.':'.$normalizedKey);
    }

    public function equals(self $other): bool
    {
        return $this->value === $other->value;
    }

    public function __toString(): string
    {
        return $this->value;
    }
}
