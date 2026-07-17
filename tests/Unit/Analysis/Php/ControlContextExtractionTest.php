<?php

namespace DNDark\LogicMap\Tests\Unit\Analysis\Php;

use DNDark\LogicMap\Analysis\Laravel\Facts\BranchConditionFactCollector;
use DNDark\LogicMap\Analysis\Php\PhpFileParser;
use PhpParser\NodeTraverser;
use PHPUnit\Framework\TestCase;

final class ControlContextExtractionTest extends TestCase
{
    public function test_one_traversal_attaches_branch_loop_try_match_and_transaction_contexts(): void
    {
        $source = <<<'PHP'
<?php
namespace App;
use Illuminate\Support\Facades\DB;
final class Flow
{
    public function run(object $order, array $items): void
    {
        if (!$order->canBeCancelled()) {
            reject($order);
        } else {
            accept($order);
        }
        foreach ($items as $item) {
            process($item);
        }
        try {
            risky();
        } catch (\RuntimeException $exception) {
            recover();
        } finally {
            cleanup();
        }
        $state = match ($order->status) {
            'open' => opened(),
            default => closed(),
        };
        DB::transaction(function (): void {
            persist();
        });
        outside();
    }
}
PHP;
        $traverser = new class extends NodeTraverser
        {
            public int $traversals = 0;

            public function traverse(array $nodes): array
            {
                $this->traversals++;

                return parent::traverse($nodes);
            }
        };
        $parsed = (new PhpFileParser(
            [new BranchConditionFactCollector()],
            static fn (): NodeTraverser => $traverser,
        ))->parse('app/Flow.php', $source);
        self::assertSame(1, $traverser->traversals);

        $calls = [];

        foreach ($parsed->callSites as $call) {
            $calls[$call->targetName] = $call->controlContexts;
        }

        self::assertSame('true', $calls['App\\reject'][0]['branch']);
        self::assertSame('false', $calls['App\\accept'][0]['branch']);
        self::assertSame('loop', $calls['App\\process'][0]['kind']);
        self::assertSame('try', $calls['App\\risky'][0]['kind']);
        self::assertSame('catch', $calls['App\\recover'][0]['kind']);
        self::assertSame('finally', $calls['App\\cleanup'][0]['kind']);
        self::assertSame('match_arm', $calls['App\\opened'][0]['kind']);
        self::assertSame('transaction', $calls['App\\persist'][0]['kind']);
        self::assertSame([], $calls['App\\outside']);

        $branchFacts = $parsed->facts('branch_condition');
        self::assertNotEmpty($branchFacts);
        self::assertNotEmpty($branchFacts[0]->controlContexts);
        self::assertStringNotContainsString('PhpParser\\Node', serialize([
            $parsed->callSites,
            $parsed->semanticFacts,
        ]));
    }
}
