<?php

namespace DNDark\LogicMap\Tests\Unit\Services\Query;

use DNDark\LogicMap\Services\Query\ResponseLimiter;
use PHPUnit\Framework\TestCase;

final class ResponseLimiterTest extends TestCase
{
    public function test_large_list_is_bounded_without_item_by_item_json_reencoding(): void
    {
        $data = ['results' => array_map(
            static fn (int $index): array => ['id' => $index, 'value' => str_repeat('x', 100)],
            range(0, 1999),
        )];
        $startedAt = hrtime(true);
        $result = (new ResponseLimiter(3000))->limit($data);
        $durationSeconds = (hrtime(true) - $startedAt) / 1_000_000_000;
        $remaining = $result['data']['results'];
        $envelope = json_encode([
            'ok' => true,
            'data' => $result['data'],
            'message' => null,
            'errors' => null,
            'meta' => $result['meta'],
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        self::assertTrue($result['meta']['truncated']);
        self::assertNotEmpty($remaining);
        self::assertLessThan(2000, count($remaining));
        self::assertSame(range(0, count($remaining) - 1), array_column($remaining, 'id'));
        self::assertLessThanOrEqual(3000, strlen($envelope));
        self::assertLessThan(0.75, $durationSeconds, 'Response limiting regressed to item-by-item JSON encoding.');
    }
}
