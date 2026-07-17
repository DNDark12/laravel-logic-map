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
}
