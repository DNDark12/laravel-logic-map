<?php

namespace dndark\LogicMap\Tests\Unit;

use dndark\LogicMap\Analysis\Support\ModuleExtractor;
use dndark\LogicMap\Tests\TestCase;
use PHPUnit\Framework\Attributes\Test;

class ModuleExtractorTest extends TestCase
{
    #[Test]
    public function it_extracts_module_from_method_id()
    {
        $module = ModuleExtractor::moduleOf('method:App\\Services\\Alert\\AlertService@syncOrders');

        $this->assertSame('Alert', $module);
    }

    #[Test]
    public function it_extracts_module_from_class_id()
    {
        $module = ModuleExtractor::moduleOf('class:App\\Http\\Controllers\\Payment\\WebhookController');

        $this->assertSame('Payment', $module);
    }

    #[Test]
    public function it_falls_back_to_class_name_when_namespace_is_generic()
    {
        $module = ModuleExtractor::moduleOf('class:App\\Services\\SyncService');

        $this->assertSame('Sync', $module);
    }

    #[Test]
    public function it_maps_route_ids_to_route_module()
    {
        $module = ModuleExtractor::moduleOf('route:/logic-map/overview');

        $this->assertSame('Route', $module);
    }
}
