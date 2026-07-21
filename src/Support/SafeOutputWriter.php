<?php

namespace DNDark\LogicMap\Support;

use InvalidArgumentException;
use RuntimeException;

final readonly class SafeOutputWriter
{
    private string $repositoryRoot;

    public function __construct(string $repositoryRoot, private bool $allowAbsolutePaths)
    {
        $real = realpath($repositoryRoot);

        if ($real === false || ! is_dir($real)) {
            throw new InvalidArgumentException('Output repository root must exist.');
        }

        $this->repositoryRoot = rtrim(str_replace('\\', '/', $real), '/');
    }

    public function write(string $output, string $content, bool $force): string
    {
        if ($output === '' || str_contains($output, "\0")) {
            throw new InvalidArgumentException('Output path must be a non-empty safe path.');
        }

        $absolute = $this->isAbsolute($output);

        if ($absolute && ! $this->allowAbsolutePaths) {
            throw new InvalidArgumentException('Absolute output paths are disabled.');
        }

        $candidate = $absolute
            ? str_replace('\\', '/', $output)
            : $this->repositoryRoot.'/'.RelativePath::normalize($output);

        if (! $absolute && ! str_starts_with($candidate, $this->repositoryRoot.'/')) {
            throw new InvalidArgumentException('Output path must remain inside the repository.');
        }

        if (is_file($candidate) && ! $force) {
            throw new RuntimeException('Output file already exists; pass --force to overwrite it.');
        }

        $directory = dirname($candidate);
        $this->assertExistingAncestor($directory, $absolute);

        if (! is_dir($directory) && ! mkdir($directory, 0755, true) && ! is_dir($directory)) {
            throw new RuntimeException('Output directory could not be created.');
        }

        $realDirectory = realpath($directory);

        if ($realDirectory === false) {
            throw new RuntimeException('Output directory could not be resolved.');
        }

        $realDirectory = rtrim(str_replace('\\', '/', $realDirectory), '/');

        if (! $absolute && $realDirectory !== $this->repositoryRoot
            && ! str_starts_with($realDirectory, $this->repositoryRoot.'/')) {
            throw new InvalidArgumentException('Output directory resolves outside the repository.');
        }

        $temporary = tempnam($realDirectory, '.logic-map-');

        if (! is_string($temporary)) {
            throw new RuntimeException('Temporary output file could not be created.');
        }

        try {
            if (file_put_contents($temporary, $content, LOCK_EX) === false) {
                throw new RuntimeException('Output content could not be written.');
            }

            if (! rename($temporary, $candidate)) {
                throw new RuntimeException('Output file could not be activated atomically.');
            }
        } finally {
            if (is_file($temporary)) {
                unlink($temporary);
            }
        }

        return $candidate;
    }

    private function assertExistingAncestor(string $directory, bool $absolute): void
    {
        $ancestor = $directory;

        while (! is_dir($ancestor)) {
            $parent = dirname($ancestor);

            if ($parent === $ancestor) {
                throw new InvalidArgumentException('Output path has no resolvable ancestor.');
            }

            $ancestor = $parent;
        }

        $real = realpath($ancestor);
        $real = $real === false ? false : rtrim(str_replace('\\', '/', $real), '/');

        if ($real === false || (! $absolute && $real !== $this->repositoryRoot
            && ! str_starts_with($real, $this->repositoryRoot.'/'))) {
            throw new InvalidArgumentException('Output ancestor resolves outside the repository.');
        }
    }

    private function isAbsolute(string $path): bool
    {
        return str_starts_with($path, '/') || preg_match('/^[A-Za-z]:[\\\\\/]/', $path) === 1;
    }
}
