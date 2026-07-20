<?php

namespace DNDark\LogicMap\Support;

/**
 * Shared symbol-search term semantics for the in-memory and database-backed
 * graph readers. A node matches when the whole needle appears in one of its
 * searchable fields, or when every token appears somewhere in them.
 */
final readonly class SymbolSearchTerms
{
    public string $needle;

    /** @var list<string> */
    public array $tokens;

    public function __construct(string $term)
    {
        $this->needle = strtolower(trim($term));
        $this->tokens = preg_split('/[^a-z0-9_\\:-]+/i', $this->needle, -1, PREG_SPLIT_NO_EMPTY) ?: [];
    }

    /** @param list<string> $fields lowercase searchable fields */
    public function matches(array $fields): bool
    {
        $haystack = implode(' ', $fields);

        if (str_contains($haystack, $this->needle)) {
            return true;
        }

        if ($this->tokens === []) {
            return false;
        }

        foreach ($this->tokens as $token) {
            if (! str_contains($haystack, $token)) {
                return false;
            }
        }

        return true;
    }
}
