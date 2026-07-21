<?php

namespace DNDark\LogicMap\Tests\Unit\Analysis\Runtime;

use DNDark\LogicMap\Analysis\Runtime\RuntimeSanitizer;
use DNDark\LogicMap\Support\CanonicalJson;
use PHPUnit\Framework\TestCase;

final class RuntimeSanitizerTest extends TestCase
{
    public function test_removes_forbidden_payloads_and_redacts_secret_patterns(): void
    {
        $sanitized = (new RuntimeSanitizer(120))->sanitize([
            'method' => 'post',
            'route_template' => '/orders/{order}?token=leaked',
            'status' => 422,
            'duration_ms' => 12.34567,
            'exception_class' => 'DomainException',
            'table_names' => ['orders', 'users; DROP TABLE users'],
            'message' => 'Failed password=hunter2 Authorization: Bearer abc123 cookie=session-secret',
            'authorization' => 'Bearer top-secret',
            'proxy-authorization' => 'proxy-secret',
            'cookie' => 'session-secret',
            'set-cookie' => 'response-secret',
            'password' => 'hunter2',
            'secret' => 'secret-value',
            'token' => 'token-value',
            'api_key' => 'api-value',
            'access_key' => 'access-value',
            'bindings' => ['private-id'],
            'request_body' => ['card' => '4111111111111111'],
            'response_body' => ['token' => 'response-token'],
            'nested' => ['safe' => 'not-allowlisted'],
        ]);

        self::assertSame('POST', $sanitized['method']);
        self::assertSame('/orders/{order}', $sanitized['route_template']);
        self::assertSame(422, $sanitized['status']);
        self::assertSame(12.346, $sanitized['duration_ms']);
        self::assertSame(['orders'], $sanitized['table_names']);
        self::assertStringContainsString('[REDACTED]', $sanitized['message']);

        $serialized = strtolower(CanonicalJson::encode($sanitized));

        foreach (['hunter2', 'abc123', 'session-secret', 'top-secret', 'private-id', '4111111111111111', 'response-token'] as $forbidden) {
            self::assertStringNotContainsString(strtolower($forbidden), $serialized);
        }

        foreach (['authorization', 'cookie', 'password', 'secret', 'token', 'api_key', 'access_key', 'bindings', 'request_body', 'response_body', 'nested'] as $key) {
            self::assertArrayNotHasKey($key, $sanitized);
        }
    }

    public function test_retains_only_bounded_runtime_metadata(): void
    {
        $sanitized = (new RuntimeSanitizer(20))->sanitize([
            'url_template' => 'https://api.example.com/orders/{id}?api_key=bad',
            'job_class' => 'App\\Jobs\\SyncOrder',
            'event_class' => 'App\\Events\\OrderSynced',
            'cache_key' => 'order-summary:{id}',
            'success' => true,
            'message' => str_repeat('x', 80),
            'unknown' => 'discarded',
        ]);

        self::assertSame('https://api.example.com/orders/{id}', $sanitized['url_template']);
        self::assertSame('App\\Jobs\\SyncOrder', $sanitized['job_class']);
        self::assertSame('App\\Events\\OrderSynced', $sanitized['event_class']);
        self::assertSame('order-summary:{id}', $sanitized['cache_key']);
        self::assertTrue($sanitized['success']);
        self::assertSame(20, strlen($sanitized['message']));
        self::assertArrayNotHasKey('unknown', $sanitized);
    }
}
