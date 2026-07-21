<?php

namespace DNDark\LogicMap\Tests\Unit\Analysis\Facts;

use DNDark\LogicMap\Analysis\Facts\FactCollector;
use DNDark\LogicMap\Analysis\Php\PhpFileParser;
use PhpParser\Node;
use PhpParser\Node\Expr;
use PhpParser\Node\Stmt;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitorAbstract;
use PHPUnit\Framework\TestCase;

final class FactCollectorTest extends TestCase
{
    public function test_all_generic_facts_are_collected_during_one_traversal(): void
    {
        $source = <<<'PHP'
<?php

class Example
{
    protected $table = 'orders';
    protected $fillable = ['status', 'total'];

    public function run($order, $query)
    {
        $total = $order->subtotal + $order->tax;
        $query->where('status', 'open')->orderBy('id')->get();
        if (! $order->isOpen()) {
            return null;
        }
        throw new OrderClosed($order);
        DB::transaction(function () use ($order): void { $order->touch(); });
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
        $spy = new class extends NodeVisitorAbstract implements FactCollector
        {
            /** @var array<class-string<Node>, int> */
            public array $seen = [];

            public function name(): string
            {
                return 'spy';
            }

            public function enterNode(Node $node): null
            {
                foreach ([
                    Expr\Assign::class,
                    Stmt\Property::class,
                    Expr\MethodCall::class,
                    Stmt\Return_::class,
                    Expr\Throw_::class,
                    Expr\Closure::class,
                ] as $type) {
                    if ($node instanceof $type) {
                        $this->seen[$type] = ($this->seen[$type] ?? 0) + 1;
                    }
                }

                return null;
            }

            public function facts(): array
            {
                return [];
            }
        };

        $parser = new PhpFileParser(
            [$spy],
            static fn (): NodeTraverser => $traverser,
        );
        $parsed = $parser->parse('app/Example.php', $source);

        self::assertSame(1, $traverser->traversals);
        self::assertSame(1, $spy->seen[Expr\Assign::class]);
        self::assertSame(2, $spy->seen[Stmt\Property::class]);
        self::assertGreaterThanOrEqual(5, $spy->seen[Expr\MethodCall::class]);
        self::assertSame(1, $spy->seen[Stmt\Return_::class]);
        self::assertSame(1, $spy->seen[Expr\Throw_::class]);
        self::assertSame(1, $spy->seen[Expr\Closure::class]);

        $kinds = array_count_values(array_map(static fn ($fact): string => $fact->kind, $parsed->semanticFacts));
        self::assertSame(1, $kinds['assignment']);
        self::assertGreaterThanOrEqual(1, $kinds['array_literal']);
        self::assertGreaterThanOrEqual(1, $kinds['fluent_chain']);
        self::assertSame(2, $kinds['property_default']);
        self::assertSame(2, $kinds['terminal']);
        self::assertSame(1, $kinds['closure_boundary']);

        self::assertSame([5, 6], array_map(
            static fn ($fact): int => $fact->startLine,
            $parsed->facts('property_default'),
        ));
        self::assertStringNotContainsString('PhpParser\\Node', serialize($parsed));
        self::assertSame('app/Example.php', $parsed->relativePath);
    }
}
