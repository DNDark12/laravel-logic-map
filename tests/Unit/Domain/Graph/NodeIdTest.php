<?php

namespace DNDark\LogicMap\Tests\Unit\Domain\Graph;

use DNDark\LogicMap\Domain\Graph\NodeId;
use DNDark\LogicMap\Domain\Graph\NodeKind;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class NodeIdTest extends TestCase
{
    public function test_builds_stable_method_and_route_ids(): void
    {
        self::assertSame(
            'method:App\\Services\\OrderService::cancel',
            NodeId::method('App\\Services\\OrderService', 'cancel')->value,
        );

        self::assertSame(
            'route:POST:orders/{order}/cancel',
            NodeId::route('post', '/orders/{order}/cancel')->value,
        );
    }

    public function test_builds_every_non_class_identity_from_the_closed_prefix_map(): void
    {
        self::assertSame('command:orders:reconcile', NodeId::named(NodeKind::Command, 'orders:reconcile')->value);
        self::assertSame('table:default:orders', NodeId::named(NodeKind::Table, 'default:orders')->value);
        self::assertSame('column:default:orders.status', NodeId::named(NodeKind::Column, 'default:orders.status')->value);
        self::assertSame('cache:order-summary:{order}', NodeId::named(NodeKind::CacheKey, 'order-summary:{order}')->value);
        self::assertSame('config:services.erp.base_url', NodeId::named(NodeKind::ConfigKey, 'services.erp.base_url')->value);
        self::assertSame(
            'external:POST:{services.erp.base_url}/orders/{id}/cancel',
            NodeId::named(NodeKind::ExternalEndpoint, 'POST:{services.erp.base_url}/orders/{id}/cancel')->value,
        );
        self::assertSame('view:orders.show', NodeId::named(NodeKind::View, 'orders.show')->value);
        self::assertSame('module:Orders', NodeId::named(NodeKind::Module, 'Orders')->value);
        self::assertSame(
            'process:route:POST:orders/{order}/cancel',
            NodeId::named(NodeKind::Process, 'route:POST:orders/{order}/cancel')->value,
        );
    }

    public function test_class_like_symbols_use_structural_prefixes_only(): void
    {
        self::assertSame(
            'class:App\\Http\\Controllers\\OrderController',
            NodeId::symbol(NodeKind::ClassSymbol, 'App\\Http\\Controllers\\OrderController')->value,
        );
        self::assertSame(
            'enum:App\\Enums\\OrderStatus',
            NodeId::symbol(NodeKind::EnumSymbol, 'App\\Enums\\OrderStatus')->value,
        );
        self::assertSame(
            'interface:App\\Contracts\\OrderGateway',
            NodeId::symbol(NodeKind::InterfaceSymbol, 'App\\Contracts\\OrderGateway')->value,
        );
        self::assertSame(
            'trait:App\\Concerns\\Auditable',
            NodeId::symbol(NodeKind::TraitSymbol, 'App\\Concerns\\Auditable')->value,
        );
    }

    public function test_rejects_semantic_roles_as_identity_prefixes(): void
    {
        foreach (['controller', 'service', 'repository', 'job', 'listener', 'model'] as $role) {
            try {
                NodeId::fromString("{$role}:App\\Example");
                self::fail("Expected rejection of semantic role prefix: {$role}");
            } catch (InvalidArgumentException) {
                // Expected.
            }
        }

        $this->expectException(InvalidArgumentException::class);
        NodeId::symbol(NodeKind::Controller, 'App\\Http\\Controllers\\OrderController');
    }

    public function test_normalizes_file_separators_and_rejects_unsafe_paths(): void
    {
        self::assertSame(
            'file:app/Services/OrderService.php',
            NodeId::file('app\\Services\\OrderService.php')->value,
        );
        self::assertSame(
            'file:app/Services/OrderService.php',
            NodeId::file('./app//Services/OrderService.php')->value,
        );

        foreach (['/etc/passwd', 'C:\\repo\\app\\Foo.php', '\\\\server\\share\\Foo.php', 'app/../secrets.php'] as $unsafe) {
            try {
                NodeId::file($unsafe);
                self::fail("Expected rejection of unsafe path: {$unsafe}");
            } catch (InvalidArgumentException) {
                // Expected.
            }
        }
    }

    public function test_rejects_empty_unknown_or_control_character_ids(): void
    {
        foreach (['', 'bogus:value', "method:Bad\nId", 'method:'] as $invalid) {
            try {
                NodeId::fromString($invalid);
                self::fail("Expected rejection of invalid ID: {$invalid}");
            } catch (InvalidArgumentException) {
                // Expected.
            }
        }

        self::assertTrue(true);
    }
}
