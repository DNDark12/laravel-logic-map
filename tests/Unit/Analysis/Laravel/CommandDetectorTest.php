<?php

namespace DNDark\LogicMap\Tests\Unit\Analysis\Laravel;

use DNDark\LogicMap\Analysis\Laravel\Boot\BootFact;
use DNDark\LogicMap\Analysis\Laravel\Detectors\CommandDetector;
use DNDark\LogicMap\Analysis\Laravel\SymbolClassifier;
use DNDark\LogicMap\Analysis\Php\PhpFileParser;
use DNDark\LogicMap\Analysis\Php\StructuralGraphBuilder;
use DNDark\LogicMap\Analysis\Php\SymbolTable;
use DNDark\LogicMap\Domain\Graph\EdgeType;
use DNDark\LogicMap\Domain\Graph\GraphEdge;
use DNDark\LogicMap\Domain\Graph\KnowledgeGraph;
use DNDark\LogicMap\Tests\TestCase;

final class CommandDetectorTest extends TestCase
{
    public function test_effective_command_names_resolve_to_only_their_declaring_classes(): void
    {
        $files = [
            $this->parse('app/Console/Commands/FirstCommand.php', 'FirstCommand', 'first:run {tenant}'),
            $this->parse('app/Console/Commands/SecondCommand.php', 'SecondCommand', 'second:run'),
        ];
        $symbols = new SymbolTable();

        foreach ($files as $file) {
            foreach ($file->symbols as $symbol) {
                $symbols->add($symbol);
            }
        }

        $facts = [
            new BootFact('command', 'commands', ['name' => 'first:run']),
            new BootFact('command', 'commands', ['name' => 'second:run']),
        ];
        $graph = new KnowledgeGraph();
        (new StructuralGraphBuilder($symbols))->build($files, $graph);
        (new SymbolClassifier(config('logic-map.classifier.namespace_conventions', [])))
            ->classify($files, $symbols, $facts, $graph);

        self::assertSame([], (new CommandDetector())->detect($files, $symbols, $facts, $graph));
        self::assertTrue($graph->hasNode(\DNDark\LogicMap\Domain\Graph\NodeId::fromString('command:first:run')));
        self::assertTrue($graph->hasNode(\DNDark\LogicMap\Domain\Graph\NodeId::fromString('command:second:run')));
        self::assertCount(1, $this->edges($graph, 'command:first:run', 'class:App\Console\Commands\FirstCommand'));
        self::assertCount(1, $this->edges($graph, 'command:second:run', 'class:App\Console\Commands\SecondCommand'));
        self::assertSame([], $this->edges($graph, 'command:first:run', 'class:App\Console\Commands\SecondCommand'));
        self::assertSame([], $this->edges($graph, 'command:second:run', 'class:App\Console\Commands\FirstCommand'));
    }

    private function parse(string $file, string $class, string $signature): \DNDark\LogicMap\Analysis\Php\ParsedFile
    {
        return (new PhpFileParser())->parse($file, <<<PHP
<?php
namespace App\Console\Commands;

final class {$class} extends \\Illuminate\\Console\\Command
{
    protected \$signature = '{$signature}';

    public function handle(): int
    {
        return 0;
    }
}
PHP);
    }

    /** @return list<GraphEdge> */
    private function edges(KnowledgeGraph $graph, string $source, string $target): array
    {
        return array_values(array_filter(
            $graph->edges(),
            static fn (GraphEdge $edge): bool => $edge->source->value === $source
                && $edge->type === EdgeType::ResolvesTo
                && $edge->target->value === $target,
        ));
    }
}
