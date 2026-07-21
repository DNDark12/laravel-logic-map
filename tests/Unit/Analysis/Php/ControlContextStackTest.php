<?php

namespace DNDark\LogicMap\Tests\Unit\Analysis\Php;

use DNDark\LogicMap\Analysis\Facts\ControlKind;
use DNDark\LogicMap\Analysis\Php\ControlContextStack;
use DNDark\LogicMap\Analysis\Php\ExpressionNormalizer;
use PhpParser\Node\Stmt\If_;
use PhpParser\ParserFactory;
use PHPUnit\Framework\TestCase;

final class ControlContextStackTest extends TestCase
{
    public function test_branch_spans_are_normalized_and_selected_without_retaining_ast_nodes(): void
    {
        $source = <<<'PHP'
<?php
if (!$order->canBeCancelled()) {
    reject($order);
} else {
    accept($order);
}
PHP;
        $statements = (new ParserFactory())->createForNewestSupportedVersion()->parse($source);
        self::assertInstanceOf(If_::class, $statements[0]);
        $stack = new ControlContextStack(new ExpressionNormalizer(80));
        $stack->enterNode($statements[0]);

        $truthy = $stack->contextsForSpan(3, 3);
        $falsy = $stack->contextsForSpan(5, 5);
        self::assertCount(1, $truthy);
        self::assertSame(ControlKind::Branch, $truthy[0]->kind);
        self::assertSame('!$order->canBeCancelled()', $truthy[0]->predicate);
        self::assertSame('true', $truthy[0]->branch);
        self::assertSame('false', $falsy[0]->branch);
        self::assertStringStartsWith('control:', $truthy[0]->boundaryId);
        self::assertStringNotContainsString('PhpParser\\Node', serialize([$truthy, $falsy]));
    }
}
