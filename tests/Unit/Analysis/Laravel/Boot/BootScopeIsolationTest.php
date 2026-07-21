<?php

namespace DNDark\LogicMap\Tests\Unit\Analysis\Laravel\Boot;

use DNDark\LogicMap\Analysis\Laravel\Boot\BootCollectionResult;
use DNDark\LogicMap\Analysis\Laravel\Boot\BootCollector;
use DNDark\LogicMap\Analysis\Laravel\Boot\BootFact;
use DNDark\LogicMap\Analysis\Laravel\Boot\ContainerBootCollector;
use DNDark\LogicMap\Analysis\Laravel\Boot\LaravelBootInspector;
use DNDark\LogicMap\Analysis\Laravel\Boot\PolicyBootCollector;
use DNDark\LogicMap\Analysis\Laravel\Boot\RouteBootCollector;
use DNDark\LogicMap\Analysis\Php\PhpFileParser;
use DNDark\LogicMap\Analysis\Php\SymbolTable;
use DNDark\LogicMap\Tests\Support\CommerceFixtureTestCase;

final class BootScopeIsolationTest extends CommerceFixtureTestCase
{
    public function test_boot_facts_are_reconciled_to_the_authoritative_fixture_symbol_table(): void
    {
        [$table, $parsedFiles] = $this->fixtureSymbols();
        $result = (new LaravelBootInspector(
            fn () => $this->app,
            [
                new RouteBootCollector(),
                new ContainerBootCollector(),
                new PolicyBootCollector(),
            ],
        ))->inspect($table, $parsedFiles);
        $routes = array_values(array_filter(
            $result->facts,
            static fn ($fact): bool => $fact->kind === 'route',
        ));

        self::assertSame(
            ['dashboard.sales', 'orders.cancel', 'orders.ship', 'orders.show'],
            array_values(array_map(
                static fn ($fact): ?string => $fact->attributes['name'],
                $routes,
            )),
        );

        foreach ($routes as $route) {
            self::assertStringNotContainsString('logic-map', $route->attributes['uri']);
            self::assertStringNotContainsString('DNDark\LogicMap', $route->attributes['action_class']);
            self::assertCount(1, $table->exact($route->attributes['action_class']));
        }

        foreach ($result->facts as $fact) {
            if ($fact->kind === 'container_binding') {
                self::assertCount(1, $table->exact($fact->attributes['abstract']));
                self::assertCount(1, $table->exact($fact->attributes['concrete']));
            }

            if ($fact->kind === 'policy') {
                self::assertCount(1, $table->exact($fact->attributes['model']));
                self::assertCount(1, $table->exact($fact->attributes['policy']));
            }
        }
    }

    public function test_command_with_arguments_is_reconciled_by_its_artisan_name(): void
    {
        $parsed = (new PhpFileParser())->parse('app/Console/Commands/ExportOrders.php', <<<'PHP'
<?php
namespace App\Console\Commands;

final class ExportOrders extends \Illuminate\Console\Command
{
    protected $signature = 'orders:export {tenant} {--force}';
}
PHP);
        $symbols = new SymbolTable();

        foreach ($parsed->symbols as $symbol) {
            $symbols->add($symbol);
        }

        $collector = new class implements BootCollector
        {
            public function name(): string
            {
                return 'fixture_commands';
            }

            public function collect(\Illuminate\Foundation\Application $application): BootCollectionResult
            {
                return new BootCollectionResult([
                    new BootFact('command', $this->name(), ['name' => 'orders:export']),
                ]);
            }
        };
        $result = (new LaravelBootInspector(fn () => $this->app, [$collector]))
            ->inspect($symbols, [$parsed]);

        self::assertCount(1, $result->facts);
        self::assertSame('orders:export', $result->facts[0]->attributes['name']);
    }

    private function fixtureSymbols(): array
    {
        $parser = new PhpFileParser();
        $table = new SymbolTable();
        $files = [];
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(
            $this->fixtureRoot(),
            \FilesystemIterator::SKIP_DOTS,
        ));

        foreach ($iterator as $file) {
            if (! $file->isFile() || $file->getExtension() !== 'php') {
                continue;
            }

            $source = file_get_contents($file->getPathname());
            self::assertIsString($source);
            $relative = str_replace('\\', '/', substr($file->getPathname(), strlen($this->fixtureRoot()) + 1));
            $parsed = $parser->parse($relative, $source);
            $files[] = $parsed;

            foreach ($parsed->symbols as $symbol) {
                $table->add($symbol);
            }
        }

        return [$table, $files];
    }
}
