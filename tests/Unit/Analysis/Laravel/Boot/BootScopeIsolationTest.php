<?php

namespace DNDark\LogicMap\Tests\Unit\Analysis\Laravel\Boot;

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
