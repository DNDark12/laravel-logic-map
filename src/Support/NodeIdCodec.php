<?php

namespace DNDark\LogicMap\Support;

final class NodeIdCodec
{
    public function encode(string $canonicalId): string
    {
        $this->assertDecoded($canonicalId);

        return rtrim(strtr(base64_encode($canonicalId), '+/', '-_'), '=');
    }

    public function decode(string $encoded): string
    {
        if ($encoded === ''
            || preg_match('/^[A-Za-z0-9_-]+$/D', $encoded) !== 1
            || strlen($encoded) % 4 === 1) {
            throw new InvalidNodeIdEncoding('Encoded logic-map ID is not valid unpadded base64url.');
        }

        $padding = (4 - strlen($encoded) % 4) % 4;
        $decoded = base64_decode(strtr($encoded, '-_', '+/').str_repeat('=', $padding), true);

        if (! is_string($decoded)) {
            throw new InvalidNodeIdEncoding('Encoded logic-map ID could not be decoded.');
        }

        $this->assertDecoded($decoded);

        if (! hash_equals($encoded, $this->encode($decoded))) {
            throw new InvalidNodeIdEncoding('Encoded logic-map ID is not canonical.');
        }

        return $decoded;
    }

    private function assertDecoded(string $canonicalId): void
    {
        if ($canonicalId === ''
            || preg_match('//u', $canonicalId) !== 1
            || preg_match('/[\x00-\x1F\x7F]/', $canonicalId) === 1) {
            throw new InvalidNodeIdEncoding('Canonical logic-map ID must contain valid printable UTF-8.');
        }
    }
}
