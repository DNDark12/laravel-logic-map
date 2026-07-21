<?php

namespace DNDark\LogicMap\Analysis\Php;

use PhpParser\Node;
use PhpParser\Node\Identifier;
use PhpParser\Node\IntersectionType;
use PhpParser\Node\Name;
use PhpParser\Node\NullableType;
use PhpParser\Node\UnionType;

final class PhpNameResolver
{
    public static function name(Name $name): string
    {
        $resolved = $name->getAttribute('resolvedName') ?? $name->getAttribute('namespacedName');

        return ltrim((string) ($resolved instanceof Name ? $resolved : $name), '\\');
    }

    public static function type(?Node $type): ?string
    {
        if ($type === null) {
            return null;
        }

        if ($type instanceof Identifier) {
            return $type->toString();
        }

        if ($type instanceof Name) {
            return self::name($type);
        }

        if ($type instanceof NullableType) {
            return '?'.self::type($type->type);
        }

        if ($type instanceof IntersectionType) {
            return implode('&', array_map(
                static fn (Node $part): string => (string) self::type($part),
                $type->types,
            ));
        }

        if ($type instanceof UnionType) {
            return implode('|', array_map(static function (Node $part): string {
                $rendered = (string) self::type($part);

                return $part instanceof IntersectionType ? '('.$rendered.')' : $rendered;
            }, $type->types));
        }

        return null;
    }
}
