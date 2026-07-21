<?php

namespace DNDark\LogicMap\Tests\Unit\Analysis\Php;

use DNDark\LogicMap\Analysis\Laravel\Facts\BranchConditionFactCollector;
use DNDark\LogicMap\Analysis\Laravel\Facts\EloquentChainFactCollector;
use DNDark\LogicMap\Analysis\Laravel\Facts\FacadeEffectFactCollector;
use DNDark\LogicMap\Analysis\Laravel\Facts\LaravelRegistrationFactCollector;
use DNDark\LogicMap\Analysis\Php\PhpFileParser;
use DNDark\LogicMap\Analysis\Php\SymbolTable;
use DNDark\LogicMap\Domain\Snapshot\DiagnosticCode;
use PHPUnit\Framework\TestCase;

final class PhpSyntaxCoverageTest extends TestCase
{
    public function test_parses_enums_anonymous_classes_and_dnf_types_with_stable_identity(): void
    {
        $source = <<<'PHP'
<?php
namespace Fixtures\CommerceApp\Enums;

enum Flag
{
    case Enabled;
    public function label(): string { return strtolower($this->name); }
}

enum OrderStatus: string
{
    case Open = 'open';
    public function label(): string { return strtoupper($this->value); }
}

class Complex
{
    public (\Countable&\ArrayAccess)|null $items;
    public function consume((\Countable&\ArrayAccess)|null $value): (\Countable&\ArrayAccess)|null
    {
        return $value;
    }
}

$first = new class { public function run(): void {} };
$second = new class { public function run(): void {} };
PHP;

        $parsed = (new PhpFileParser())->parse('app/Enums/Mixed.php', $source);
        $ids = array_map(static fn ($symbol): string => $symbol->id->value, $parsed->symbols);

        self::assertContains('enum:Fixtures\CommerceApp\Enums\Flag', $ids);
        self::assertContains('enum:Fixtures\CommerceApp\Enums\OrderStatus', $ids);
        self::assertContains('method:Fixtures\CommerceApp\Enums\Flag::label', $ids);
        self::assertContains('method:Fixtures\CommerceApp\Enums\OrderStatus::label', $ids);
        self::assertContains('class:app/Enums/Mixed.php@anonymous[0]', $ids);
        self::assertContains('class:app/Enums/Mixed.php@anonymous[1]', $ids);
        self::assertContains('method:app/Enums/Mixed.php@anonymous[0]::run', $ids);

        $complex = array_values(array_filter(
            $parsed->symbols,
            static fn ($symbol): bool => $symbol->qualifiedName === 'Fixtures\CommerceApp\Enums\Complex',
        ))[0];
        $consume = array_values(array_filter(
            $parsed->symbols,
            static fn ($symbol): bool => $symbol->id->value === 'method:Fixtures\CommerceApp\Enums\Complex::consume',
        ))[0];

        self::assertSame('(Countable&ArrayAccess)|null', $complex->declaredPropertyTypes['items']);
        self::assertSame('(Countable&ArrayAccess)|null', $consume->declaredParameterTypes['value']);
        self::assertSame('(Countable&ArrayAccess)|null', $consume->declaredReturnType);
    }

    public function test_duplicate_symbols_remain_candidates_and_emit_a_diagnostic(): void
    {
        $parsed = (new PhpFileParser())->parse('app/Duplicate.php', <<<'PHP'
<?php
namespace App;
class Duplicate {}
class Duplicate {}
PHP);
        $table = new SymbolTable();

        foreach ($parsed->symbols as $symbol) {
            $table->add($symbol);
        }

        self::assertCount(2, $table->exact('App\Duplicate'));
        self::assertCount(1, array_filter(
            $parsed->diagnostics,
            static fn ($diagnostic): bool => $diagnostic->code === DiagnosticCode::DuplicateSymbol,
        ));
    }

    public function test_captures_nullsafe_and_first_class_callable_syntax(): void
    {
        $parsed = (new PhpFileParser())->parse('app/Calls.php', <<<'PHP'
<?php
namespace App;
class Order { public function gateway(): self { return $this; } }
class Calls
{
    public function run(?Order $order): void
    {
        $order?->gateway();
        $callable = $this->save(...);
    }
    public function save(): void {}
}
PHP);

        $gateway = array_values(array_filter(
            $parsed->callSites,
            static fn ($call): bool => $call->targetName === 'gateway',
        ))[0];
        $callable = array_values(array_filter(
            $parsed->callSites,
            static fn ($call): bool => $call->targetName === 'save' && ($call->attributes['first_class_callable'] ?? false),
        ))[0];

        self::assertTrue($gateway->attributes['nullsafe']);
        self::assertTrue($callable->attributes['first_class_callable']);
        self::assertSame([], $callable->arguments);
    }

    public function test_production_fact_collectors_accept_first_class_callable_arguments(): void
    {
        $parser = new PhpFileParser([
            new LaravelRegistrationFactCollector(),
            new BranchConditionFactCollector(),
            new EloquentChainFactCollector(),
            new FacadeEffectFactCollector(),
        ]);

        $parsed = $parser->parse('app/Services/CsvService.php', <<<'PHP'
<?php
namespace App\Services;

final class CsvService
{
    public function export(array $headers): array
    {
        return array_map($this->quoteCsvField(...), $headers);
    }

    private function quoteCsvField(string $value): string
    {
        return $value;
    }
}
PHP);

        $callable = array_values(array_filter(
            $parsed->callSites,
            static fn ($call): bool => $call->targetName === 'quoteCsvField',
        ));

        self::assertCount(1, $callable);
        self::assertTrue($callable[0]->attributes['first_class_callable']);
        self::assertSame([], $callable[0]->arguments);
    }
}
