<?php

namespace DNDark\LogicMap\Support;

use DNDark\LogicMap\Services\Indexing\IndexOptions;
use FilesystemIterator;
use InvalidArgumentException;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

final readonly class RepositoryFileDiscovery
{
    private const DEFAULT_EXCLUDES = [
        'vendor',
        '.git',
        'storage',
        'bootstrap/cache',
        'node_modules',
    ];

    private string $root;

    public function __construct(string $repositoryRoot)
    {
        $root = realpath($repositoryRoot);

        if ($root === false || ! is_dir($root)) {
            throw new InvalidArgumentException('Repository discovery root must be an existing directory.');
        }

        $this->root = rtrim(str_replace('\\', '/', $root), '/');
    }

    /** @return list<string> */
    public function discover(IndexOptions $options): array
    {
        $roots = $options->scanPaths;

        foreach ($this->composerRoots() as $composerRoot) {
            if ($this->withinAny($composerRoot, $options->scanPaths)) {
                $roots[] = $composerRoot;
            }
        }

        $roots = array_values(array_unique($roots));
        sort($roots, SORT_STRING);
        $excludes = array_values(array_unique([...self::DEFAULT_EXCLUDES, ...$options->excludes]));
        $files = [];

        foreach ($roots as $relativeRoot) {
            $absoluteRoot = $this->absolute($relativeRoot);

            if (is_file($absoluteRoot)) {
                $this->considerFile(new SplFileInfo($absoluteRoot), $excludes, $files);

                continue;
            }

            if (! is_dir($absoluteRoot)) {
                continue;
            }

            $iterator = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($absoluteRoot, FilesystemIterator::SKIP_DOTS),
                RecursiveIteratorIterator::LEAVES_ONLY,
            );

            foreach ($iterator as $file) {
                if ($file instanceof SplFileInfo) {
                    $this->considerFile($file, $excludes, $files);
                }
            }
        }

        $paths = array_keys($files);
        sort($paths, SORT_STRING);

        return $paths;
    }

    public function absolute(string $relativePath): string
    {
        return $this->root.'/'.RelativePath::normalize($relativePath);
    }

    /** @return list<string> */
    private function composerRoots(): array
    {
        $composerPath = $this->root.'/composer.json';

        if (! is_file($composerPath)) {
            return [];
        }

        $contents = file_get_contents($composerPath);

        if (! is_string($contents)) {
            return [];
        }

        try {
            $composer = json_decode($contents, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return [];
        }

        $roots = [];

        foreach (['autoload', 'autoload-dev'] as $section) {
            foreach (($composer[$section]['psr-4'] ?? []) as $paths) {
                foreach ((array) $paths as $path) {
                    if (is_string($path) && trim($path) !== '') {
                        $roots[] = RelativePath::normalize($path);
                    }
                }
            }
        }

        return array_values(array_unique($roots));
    }

    /**
     * @param list<string> $excludes
     * @param array<string, true> $files
     */
    private function considerFile(SplFileInfo $file, array $excludes, array &$files): void
    {
        if (! $file->isFile() || strtolower($file->getExtension()) !== 'php') {
            return;
        }

        $realPath = $file->getRealPath();

        if ($realPath === false) {
            return;
        }

        $realPath = str_replace('\\', '/', $realPath);

        if (! str_starts_with($realPath, $this->root.'/')) {
            return;
        }

        $relativePath = RelativePath::normalize(substr($realPath, strlen($this->root) + 1));

        foreach ($excludes as $exclude) {
            if ($relativePath === $exclude || str_starts_with($relativePath, $exclude.'/')) {
                return;
            }
        }

        $files[$relativePath] = true;
    }

    /** @param list<string> $parents */
    private function withinAny(string $path, array $parents): bool
    {
        foreach ($parents as $parent) {
            if ($path === $parent || str_starts_with($path, $parent.'/')) {
                return true;
            }
        }

        return false;
    }
}
