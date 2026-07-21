<?php

namespace DNDark\LogicMap\Tests\Unit\Support;

use DNDark\LogicMap\Support\InvalidNodeIdEncoding;
use DNDark\LogicMap\Support\NodeIdCodec;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class NodeIdCodecTest extends TestCase
{
    #[DataProvider('canonicalIds')]
    public function test_unpadded_base64url_round_trips_canonical_ids(string $canonical): void
    {
        $codec = new NodeIdCodec();
        $encoded = $codec->encode($canonical);

        self::assertMatchesRegularExpression('/^[A-Za-z0-9_-]+$/', $encoded);
        self::assertStringNotContainsString('=', $encoded);
        self::assertSame($canonical, $codec->decode($encoded));
        self::assertSame($encoded, $codec->encode($codec->decode($encoded)));
    }

    #[DataProvider('invalidEncodings')]
    public function test_decode_rejects_non_canonical_or_malformed_values(string $encoded): void
    {
        $this->expectException(InvalidNodeIdEncoding::class);

        (new NodeIdCodec())->decode($encoded);
    }

    public static function canonicalIds(): array
    {
        return [
            ['file:app/Services/OrderService.php'],
            ['method:App\\Services\\OrderService::cancel'],
            ['route:POST:orders/{order}/cancel'],
            ['module:Orders'],
        ];
    }

    public static function invalidEncodings(): array
    {
        return [
            [''],
            ['abc='],
            ['abc+'],
            ['abc/'],
            ['A'],
            ['AB'],
            ['_w'],
        ];
    }
}
