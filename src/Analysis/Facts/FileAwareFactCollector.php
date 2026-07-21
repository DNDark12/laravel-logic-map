<?php

namespace DNDark\LogicMap\Analysis\Facts;

interface FileAwareFactCollector extends FactCollector
{
    public function useFile(string $relativePath): void;
}
