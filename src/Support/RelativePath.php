<?php

namespace DNDark\LogicMap\Support;

use InvalidArgumentException;

final class RelativePath
{
    public static function normalize(string $path): string
    {
        $normalized = str_replace('\\', '/', $path);

        if (
            $normalized === ''
            || str_starts_with($normalized, '/')
            || preg_match('#^[A-Za-z]:#', $normalized) === 1
            || preg_match('/[\x00-\x1F\x7F]/', $normalized) === 1
        ) {
            throw new InvalidArgumentException(
                'Paths must be repository-relative, use "/" separators, and contain no traversal segments.',
            );
        }

        $segments = [];

        foreach (explode('/', $normalized) as $segment) {
            if ($segment === '' || $segment === '.') {
                continue;
            }

            if ($segment === '..') {
                throw new InvalidArgumentException('Paths must not contain traversal segments.');
            }

            $segments[] = $segment;
        }

        if ($segments === []) {
            throw new InvalidArgumentException('A repository-relative file path is required.');
        }

        return implode('/', $segments);
    }
}
