<?php

namespace dndark\LogicMap\Support;

class Fingerprint
{
    /**
     * Generate a deterministic fingerprint for a set of files.
     *
     * @param array<string> $files
     * @return string
     */
    public function generate(array $files): string
    {
        $hashes = [];

        foreach ($files as $file) {
            $hashes[] = $file . ':' . filemtime($file) . ':' . filesize($file);
        }

        return sha1(implode('|', $hashes));
    }
}
