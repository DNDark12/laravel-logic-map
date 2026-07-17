<?php

namespace DNDark\LogicMap\Tests\Unit\Analysis\Php;

use DNDark\LogicMap\Analysis\Php\SymbolDefinition;
use DNDark\LogicMap\Analysis\Php\SymbolTable;
use DNDark\LogicMap\Domain\Graph\NodeId;
use DNDark\LogicMap\Domain\Graph\NodeKind;
use DNDark\LogicMap\Domain\Graph\SourceLocation;
use PHPUnit\Framework\TestCase;

final class SymbolTableTest extends TestCase
{
    public function test_returns_deterministic_candidate_lists_without_hiding_ambiguity(): void
    {
        $table = new SymbolTable();
        $interface = $this->symbol(
            NodeId::symbol(NodeKind::InterfaceSymbol, 'App\Contracts\Gateway'),
            NodeKind::InterfaceSymbol,
            'App\Contracts\Gateway',
            'app/Contracts/Gateway.php',
            3,
            8,
        );
        $implementationA = $this->symbol(
            NodeId::symbol(NodeKind::ClassSymbol, 'App\Repositories\AGateway'),
            NodeKind::ClassSymbol,
            'App\Repositories\AGateway',
            'app/Repositories/AGateway.php',
            5,
            30,
            ['implements' => ['App\Contracts\Gateway']],
        );
        $implementationB = $this->symbol(
            NodeId::symbol(NodeKind::ClassSymbol, 'App\Repositories\BGateway'),
            NodeKind::ClassSymbol,
            'App\Repositories\BGateway',
            'app/Repositories/BGateway.php',
            5,
            30,
            ['implements' => ['App\Contracts\Gateway']],
        );
        $duplicateOne = $this->symbol(
            NodeId::symbol(NodeKind::ClassSymbol, 'App\Duplicate'),
            NodeKind::ClassSymbol,
            'App\Duplicate',
            'app/Duplicate.php',
            1,
            20,
        );
        $duplicateTwo = $this->symbol(
            NodeId::symbol(NodeKind::ClassSymbol, 'App\Duplicate'),
            NodeKind::ClassSymbol,
            'App\Duplicate',
            'app/Duplicate.php',
            30,
            50,
        );
        $method = $this->symbol(
            NodeId::method('App\Duplicate', 'run'),
            NodeKind::Method,
            'App\Duplicate::run',
            'app/Duplicate.php',
            5,
            10,
        );

        foreach ([$implementationB, $duplicateTwo, $interface, $method, $implementationA, $duplicateOne] as $symbol) {
            $table->add($symbol);
        }

        self::assertCount(2, $table->exact('App\Duplicate'));
        self::assertSame(
            ['class:App\Repositories\AGateway', 'class:App\Repositories\BGateway'],
            array_map(static fn ($symbol): string => $symbol->id->value, $table->implementations('App\Contracts\Gateway')),
        );
        self::assertSame([$method], $table->methods('App\Duplicate', 'run'));
        self::assertSame([$method], $table->smallestEnclosing('app/Duplicate.php', 7));
        self::assertSame([], $table->smallestEnclosing('app/Missing.php', 7));
        self::assertCount(3, $table->symbolsInFile('app/Duplicate.php'));
        self::assertCount(2, $table->byId(NodeId::symbol(NodeKind::ClassSymbol, 'App\Duplicate')));
    }

    private function symbol(
        NodeId $id,
        NodeKind $kind,
        string $qualifiedName,
        string $file,
        int $start,
        int $end,
        array $attributes = [],
    ): SymbolDefinition {
        return new SymbolDefinition(
            $id,
            $kind,
            basename(str_replace('\\', '/', $qualifiedName)),
            $qualifiedName,
            new SourceLocation($file, $start, $end),
            [],
            [],
            null,
            $attributes,
        );
    }
}
