<?php

namespace dndark\LogicMap\Support;

use Illuminate\Support\Str;

class ArtifactSlugger
{
    /**
     * Converts a node ID to a filesystem-safe slug.
     * E.g., 'class:App\Services\Foo' -> 'class-app-services-foo'
     */
    public static function slugify(string $nodeId): string
    {
        $safe = str_replace([':', '\\', '/', '@', '()'], '-', $nodeId);
        $safe = preg_replace('/-+/', '-', $safe);
        
        return Str::slug($safe);
    }
}
