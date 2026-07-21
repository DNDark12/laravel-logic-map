<?php

namespace DNDark\LogicMap\Tests\Unit\Analysis\Laravel;

use DNDark\LogicMap\Analysis\Laravel\Facts\BranchConditionFactCollector;
use DNDark\LogicMap\Analysis\Laravel\Facts\ControlFlowFactMapper;
use DNDark\LogicMap\Analysis\Laravel\Facts\TransactionBoundaryMapper;
use DNDark\LogicMap\Analysis\Php\PhpFileParser;
use PhpParser\NodeTraverser;
use PHPUnit\Framework\TestCase;

final class ControlFlowFactMapperTest extends TestCase
{
    public function test_one_traversal_maps_guarded_terminals_and_transaction_boundary(): void
    {
        $source = <<<'PHP'
<?php
namespace App;

use Illuminate\Support\Facades\DB;
use RuntimeException;

final class Service
{
    public function run(bool $ready, bool $skip): void
    {
        if (! $ready) {
            throw new RuntimeException('not ready');
        }

        if ($skip) {
            return;
        }

        DB::transaction(function (): void {
            DB::table('orders')->update(['status' => 'ready']);
        });
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
        ))->parse('app/Service.php', $source);
        $flow = (new ControlFlowFactMapper())->map($parsed);
        $transactions = (new TransactionBoundaryMapper())->map($parsed);

        self::assertSame(1, $traverser->traversals);
        self::assertCount(2, $flow['branches']);
        self::assertCount(1, $flow['throws']);
        self::assertCount(1, $flow['early_returns']);
        self::assertSame('!$ready', $flow['throws'][0]->guard?->expression);
        self::assertSame('$skip', $flow['early_returns'][0]->guard?->expression);
        self::assertSame('RuntimeException', $flow['throws'][0]->exceptionClass);
        self::assertSame('method:App\Service::run', $flow['throws'][0]->enclosingSymbol);
        self::assertCount(1, $transactions);
        self::assertSame('closure', $transactions[0]->operation);
        self::assertSame(20, $transactions[0]->bodyStartLine);
        self::assertSame(20, $transactions[0]->bodyEndLine);
        self::assertStringNotContainsString('PhpParser\Node', serialize([$flow, $transactions]));
    }
}
