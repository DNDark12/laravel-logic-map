<?php

namespace DNDark\LogicMap\Tests\Unit;

use PHPUnit\Framework\TestCase;

final class PlatformRequirementsTest extends TestCase
{
    public function test_composer_manifest_uses_the_application_database_without_forcing_sqlite(): void
    {
        $manifest = json_decode(
            (string) file_get_contents(__DIR__.'/../../composer.json'),
            true,
            512,
            JSON_THROW_ON_ERROR,
        );

        self::assertArrayHasKey('ext-pdo', $manifest['require']);
        self::assertArrayNotHasKey('ext-pdo_sqlite', $manifest['require']);
        self::assertDirectoryDoesNotExist(__DIR__.'/../../src/Repositories/Sqlite');
    }

    public function test_default_scan_paths_include_tests_for_impact_test_scope(): void
    {
        $config = require __DIR__.'/../../config/logic-map.php';

        self::assertContains('tests', $config['scan_paths']);
    }
}
