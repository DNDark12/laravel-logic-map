<?php

namespace DNDark\LogicMap\Tests\Unit\Support;

use DNDark\LogicMap\Support\MemoryLimit;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class MemoryLimitTest extends TestCase
{
    private string $originalLimit;

    protected function setUp(): void
    {
        $this->originalLimit = (string) ini_get('memory_limit');
    }

    protected function tearDown(): void
    {
        ini_set('memory_limit', $this->originalLimit);
    }

    #[DataProvider('shorthandValues')]
    public function test_to_bytes_parses_ini_shorthand(string $value, int $expected): void
    {
        self::assertSame($expected, MemoryLimit::toBytes($value));
    }

    public static function shorthandValues(): array
    {
        return [
            'bytes' => ['134217728', 134_217_728],
            'kilobytes' => ['512K', 524_288],
            'megabytes' => ['128M', 134_217_728],
            'gigabytes' => ['2G', 2_147_483_648],
            'lowercase' => ['1g', 1_073_741_824],
            'unlimited' => ['-1', PHP_INT_MAX],
        ];
    }

    public function test_ensure_at_least_raises_a_lower_limit(): void
    {
        ini_set('memory_limit', '128M');

        MemoryLimit::ensureAtLeast('1G');

        self::assertSame('1G', ini_get('memory_limit'));
    }

    public function test_ensure_at_least_never_lowers_a_higher_limit(): void
    {
        ini_set('memory_limit', '2G');

        MemoryLimit::ensureAtLeast('1G');

        self::assertSame('2G', ini_get('memory_limit'));
    }

    public function test_ensure_at_least_keeps_unlimited_untouched(): void
    {
        ini_set('memory_limit', '-1');

        MemoryLimit::ensureAtLeast('1G');

        self::assertSame('-1', ini_get('memory_limit'));
    }

    public function test_ensure_at_least_ignores_null_and_empty(): void
    {
        ini_set('memory_limit', '128M');

        MemoryLimit::ensureAtLeast(null);
        MemoryLimit::ensureAtLeast('');

        self::assertSame('128M', ini_get('memory_limit'));
    }

    public function test_ensure_at_least_supports_unlimited_requirement(): void
    {
        ini_set('memory_limit', '128M');

        MemoryLimit::ensureAtLeast('-1');

        self::assertSame('-1', ini_get('memory_limit'));
    }
}
