<?php

namespace DNDark\LogicMap\Tests\Unit\Services\Impact;

use DNDark\LogicMap\Analysis\Php\PhpFileParser;
use DNDark\LogicMap\Analysis\Php\SymbolTable;
use DNDark\LogicMap\Domain\Graph\EvidenceOrigin;
use DNDark\LogicMap\Domain\Impact\ChangedFile;
use DNDark\LogicMap\Domain\Impact\ChangedHunk;
use DNDark\LogicMap\Domain\Impact\ChangeType;
use DNDark\LogicMap\Services\Impact\ChangedSymbolMapper;
use PHPUnit\Framework\TestCase;

final class ChangedSymbolMapperTest extends TestCase
{
    public function test_maps_new_hunks_to_the_smallest_active_symbol_and_retains_file_scope(): void
    {
        $source = <<<'PHP'
<?php
namespace App;
final class OrderService
{
    public function cancel(): void
    {
        $this->save();
    }

    public function save(): void {}
}
PHP;
        $parsed = (new PhpFileParser())->parse('app/OrderService.php', $source);
        $symbols = new SymbolTable();

        foreach ($parsed->symbols as $symbol) {
            $symbols->add($symbol);
        }

        $result = (new ChangedSymbolMapper($symbols))->map([
            new ChangedFile(ChangeType::Modified, 'app/OrderService.php', 'app/OrderService.php', [
                new ChangedHunk(7, 1, 7, 1),
            ]),
            new ChangedFile(ChangeType::Modified, 'config/services.php', 'config/services.php', [
                new ChangedHunk(2, 1, 2, 1),
            ]),
            new ChangedFile(ChangeType::Modified, 'routes/web.php', 'routes/web.php', [
                new ChangedHunk(3, 1, 3, 1),
            ]),
            new ChangedFile(
                ChangeType::Added,
                null,
                'database/migrations/2026_07_17_create_orders.php',
                [new ChangedHunk(0, 0, 1, 4)],
            ),
        ], str_repeat('a', 40), str_repeat('b', 40));

        self::assertSame([
            'file:config/services.php',
            'file:database/migrations/2026_07_17_create_orders.php',
            'file:routes/web.php',
            'method:App\OrderService::cancel',
        ], array_map(static fn ($change): string => $change->newNodeId?->value ?? '', $result['symbols']));

        foreach ($result['symbols'] as $change) {
            self::assertSame(EvidenceOrigin::GitDiff, $change->evidence->origin);
            self::assertSame('git-diff-symbol-mapper', $change->evidence->detector);
            self::assertSame(str_repeat('a', 40), $change->evidence->attributes['base_commit']);
            self::assertSame(str_repeat('b', 40), $change->evidence->attributes['head_commit']);
        }
    }
}
