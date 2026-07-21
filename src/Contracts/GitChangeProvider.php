<?php

namespace DNDark\LogicMap\Contracts;

interface GitChangeProvider
{
    /**
     * @return array{files: array, diagnostics: array, base_commit: string, head_commit: string}
     */
    public function changes(string $base, string $head): array;
}
