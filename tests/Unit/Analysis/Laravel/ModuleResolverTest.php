<?php

namespace DNDark\LogicMap\Tests\Unit\Analysis\Laravel;

use DNDark\LogicMap\Analysis\Laravel\ModuleResolver;
use DNDark\LogicMap\Analysis\Php\SymbolDefinition;
use DNDark\LogicMap\Domain\Graph\NodeId;
use DNDark\LogicMap\Domain\Graph\NodeKind;
use DNDark\LogicMap\Domain\Graph\SourceLocation;
use PHPUnit\Framework\TestCase;

final class ModuleResolverTest extends TestCase
{
    public function test_precedence_is_explicit_then_domain_conventions_then_fallback(): void
    {
        $resolver = new ModuleResolver(
            ['App\Sales\ExplicitService' => 'Chosen'],
            ['App\\' => 1],
            ['app/Modules', 'app/Domain'],
            'Core',
        );

        $cases = [
            [$this->symbol('App\Sales\ExplicitService', 'app/Modules/Wrong/ExplicitService.php'), 'Chosen', 'explicit'],
            [$this->symbol('Vendor\Thing', 'app/Modules/Billing/Thing.php'), 'Billing', 'directory_root'],
            [$this->symbol('Vendor\Thing', 'app/Domain/Catalog/Thing.php'), 'Catalog', 'directory_root'],
            [$this->symbol('App\Payments\ChargeService', 'src/ChargeService.php'), 'Payments', 'namespace_root'],
            [$this->symbol('Vendor\Thing', 'app/Services/Thing.php'), 'Services', 'application_directory'],
            [$this->symbol('Vendor\Thing', 'src/Thing.php'), 'Core', 'fallback'],
        ];

        foreach ($cases as [$symbol, $module, $reason]) {
            $assignment = $resolver->resolve($symbol);
            self::assertSame($module, $assignment->module);
            self::assertStringContainsString($reason, $assignment->reason);
            self::assertNotSame('', $assignment->reason);
        }
    }

    private function symbol(string $class, string $file): SymbolDefinition
    {
        return new SymbolDefinition(
            NodeId::symbol(NodeKind::ClassSymbol, $class),
            NodeKind::ClassSymbol,
            class_basename($class),
            $class,
            new SourceLocation($file, 1, 10),
        );
    }
}
