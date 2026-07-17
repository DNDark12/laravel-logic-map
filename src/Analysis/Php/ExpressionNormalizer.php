<?php

namespace DNDark\LogicMap\Analysis\Php;

use PhpParser\Node;
use PhpParser\PrettyPrinter\Standard;

final readonly class ExpressionNormalizer
{
    public function __construct(private int $maxLength = 500) {}

    public function normalize(Node|string $expression): string
    {
        $value = $expression instanceof Node
            ? (new Standard())->prettyPrintExpr($expression)
            : $expression;
        $value = preg_replace_callback(
            '/([\'\"])(.{41,}?)\1/s',
            static fn (array $matches): string => $matches[1].'{literal}'.$matches[1],
            $value,
        ) ?? $value;
        $value = preg_replace('/\s+/u', ' ', trim($value)) ?? trim($value);
        $value = preg_replace('/\s*(\?->|->|::)\s*/u', '$1', $value) ?? $value;

        return substr($value, 0, max(1, $this->maxLength));
    }
}
