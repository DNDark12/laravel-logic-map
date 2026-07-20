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

    public function test_large_module_workflow_is_trimmed_at_the_entry_workflow_boundary(): void
    {
        $data = [
            'identity' => ['workflow_type' => 'module'],
            'entry_workflows' => array_map(
                static fn (int $entry): array => [
                    'identity' => ['workflow_id' => 'workflow:'.$entry],
                    'steps' => array_map(
                        static fn (int $step): array => [
                            'id' => "step:{$entry}:{$step}",
                            'label' => str_repeat('x', 80),
                        ],
                        range(0, 99),
                    ),
                ],
                range(0, 199),
            ),
            'inbound_relations' => ['calls' => array_fill(0, 500, ['source_id' => str_repeat('s', 80)]),],
            'outbound_relations' => ['calls' => array_fill(0, 500, ['target_id' => str_repeat('t', 80)]),],
            'diagnostics' => array_fill(0, 500, ['message' => str_repeat('d', 80)]),
        ];
        $startedAt = hrtime(true);
        $result = (new ResponseLimiter(20_000))->limit($data);
        $durationSeconds = (hrtime(true) - $startedAt) / 1_000_000_000;

        self::assertTrue($result['meta']['truncated']);
        self::assertNotEmpty($result['data']['entry_workflows']);
        self::assertLessThan(200, count($result['data']['entry_workflows']));
        self::assertLessThan(1.0, $durationSeconds, 'Module workflow limiting must trim top-level collections first.');
    }

    public function test_impact_payload_preserves_small_summary_collections_before_large_symbol_details(): void
    {
        $row = static fn (string $prefix, int $index): array => [
            'id' => "{$prefix}:{$index}",
            'label' => str_repeat($prefix, 50),
        ];
        $data = [
            'affected_symbols' => array_map(fn (int $index): array => $row('affected', $index), range(0, 99)),
            'workflows' => array_map(fn (int $index): array => $row('workflow', $index), range(0, 4)),
            'modules' => array_map(fn (int $index): array => $row('module', $index), range(0, 6)),
            'tests' => array_map(fn (int $index): array => $row('test', $index), range(0, 2)),
            'evidence' => array_map(fn (int $index): array => $row('evidence', $index), range(0, 999)),
        ];

        $result = (new ResponseLimiter(20_000))->limit($data);

        self::assertTrue($result['meta']['truncated']);
        self::assertNotEmpty($result['data']['affected_symbols']);
        self::assertLessThan(100, count($result['data']['affected_symbols']));
        self::assertCount(5, $result['data']['workflows']);
        self::assertCount(7, $result['data']['modules']);
        self::assertCount(3, $result['data']['tests']);
    }
}
