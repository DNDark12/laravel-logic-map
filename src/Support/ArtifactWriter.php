<?php

namespace dndark\LogicMap\Support;

use Illuminate\Support\Facades\File;
use InvalidArgumentException;

class ArtifactWriter
{
    public function __construct(
        protected string $basePath
    ) {
        $this->basePath = rtrim($basePath, '/\\');
    }

    /**
     * Safely constructs a bounded path and writes the content.
     * Prevents path traversal vulnerabilities.
     */
    public function write(string $relativePath, string $content): bool
    {
        $safePath = $this->getSafePath($relativePath);
        
        $directory = dirname($safePath);
        if (!File::isDirectory($directory)) {
            File::makeDirectory($directory, 0755, true, true);
        }

        return File::put($safePath, $content) !== false;
    }

    /**
     * Delete existing output directory if it exists and we're overwriting.
     */
    public function clean(): bool
    {
        if (File::isDirectory($this->basePath)) {
            return File::deleteDirectory($this->basePath);
        }
        return true;
    }

    /**
     * Determines the exact path while guarding against traversal attacks.
     */
    protected function getSafePath(string $relativePath): string
    {
        // Remove leading slashes
        $relativePath = ltrim($relativePath, '/\\');
        
        // Prevent directory traversal sequences
        if (str_contains($relativePath, '..')) {
            throw new InvalidArgumentException("Invalid path: Path traversal sequences (..) are not allowed.");
        }

        return $this->basePath . DIRECTORY_SEPARATOR . $relativePath;
    }
}
