<?php

namespace DNDark\LogicMap\Tests\Unit;

use PHPUnit\Framework\TestCase;

final class PlatformRequirementsTest extends TestCase
{
    public function test_composer_manifest_requires_pdo_sqlite(): void
    {
        $manifest = json_decode(
            (string) file_get_contents(__DIR__.'/../../composer.json'),
            true,
            512,
            JSON_THROW_ON_ERROR,
        );

        self::assertArrayHasKey('ext-pdo_sqlite', $manifest['require']);
    }

    public function test_default_scan_paths_include_tests_for_impact_test_scope(): void
    {
        $config = require __DIR__.'/../../config/logic-map.php';

        self::assertContains('tests', $config['scan_paths']);
    }
}
