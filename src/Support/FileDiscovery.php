<?php

namespace dndark\LogicMap\Support;

use Symfony\Component\Finder\Finder;

class FileDiscovery
{
    /**
     * Discover all PHP files in the given paths.
     *
     * @param array $paths
     * @return array<string>
     */
    public function findFiles(array $paths): array
    {
        $files = [];

        foreach ($paths as $path) {
            if (! is_dir($path) && ! is_file($path)) {
                continue;
            }

            if (is_file($path) && str_ends_with($path, '.php')) {
                $files[] = $path;
                continue;
            }

            $finder = new Finder();
            $finder->files()->in($path)->name('*.php');

            foreach ($finder as $file) {
                $files[] = $file->getRealPath();
            }
        }

        sort($files);

        return $files;
    }
}
