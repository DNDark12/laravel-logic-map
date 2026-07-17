<?php

namespace DNDark\LogicMap\Services\Indexing;

use DNDark\LogicMap\Support\RelativePath;
use InvalidArgumentException;

final readonly class IndexOptions
{
    /** @var list<string> */
    public array $scanPaths;

    /** @var list<string> */
    public array $excludes;

    public function __construct(
        array $scanPaths,
        array $excludes = [],
        public bool $force = false,
        public bool $bootLaravel = true,
    ) {
        if ($scanPaths === []) {
            throw new InvalidArgumentException('V2 index scan paths must be non-empty.');
        }

        $this->scanPaths = self::normalizePaths($scanPaths);
        $this->excludes = self::normalizePaths($excludes);
    }

    public function fingerprintData(): array
    {
        return [
            'scan_paths' => $this->scanPaths,
            'excludes' => $this->excludes,
            'boot_laravel' => $this->bootLaravel,
        ];
    }

    /** @return list<string> */
    private static function normalizePaths(array $paths): array
    {
        $normalized = [];

        foreach ($paths as $path) {
            if (! is_string($path)) {
                throw new InvalidArgumentException('V2 index paths must be strings.');
            }

            $normalized[] = RelativePath::normalize($path);
        }

        $normalized = array_values(array_unique($normalized));
        sort($normalized, SORT_STRING);

        return $normalized;
    }
}
