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

    public function test_explicit_mappings_support_namespace_and_path_globs_for_domain_families(): void
    {
        $resolver = new ModuleResolver(
            [
                'App\\Services\\Order*' => 'Orders',
                'app/Repositories/Order*.php' => 'Orders',
            ],
            ['App\\' => 1],
            [],
            'Core',
        );

        self::assertSame(
            'Orders',
            $resolver->resolve($this->symbol('App\\Services\\OrderPricingService', 'app/Services/OrderPricingService.php'))->module,
        );
        self::assertSame(
            'Orders',
            $resolver->resolve($this->symbol('App\\Repositories\\OrderRepository', 'app/Repositories/OrderRepository.php'))->module,
        );
        self::assertSame(
            'Services',
            $resolver->resolve($this->symbol('App\\Services\\ProductService', 'app/Services/ProductService.php'))->module,
        );
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
