<?php

namespace DNDark\LogicMap\Http\Middleware;

use Closure;
use DNDark\LogicMap\Services\Query\ApiResult;
use DNDark\LogicMap\Support\MemoryLimit;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class EnsureLogicMapEnabled
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! (bool) config('logic-map.http.enabled', true)) {
            return ApiResult::failure(
                'Laravel Logic Map HTTP access is disabled.',
                ['code' => 'http_disabled'],
                403,
            )->toResponse();
        }

        $allowed = (array) config('logic-map.http.allowed_environments', ['local', 'testing']);

        if (! app()->environment($allowed)) {
            return ApiResult::failure(
                'Laravel Logic Map is not enabled in this environment.',
                ['code' => 'environment_not_allowed', 'environment' => app()->environment()],
                403,
            )->toResponse();
        }

        // Viewer endpoints hydrate the semantic graph; PHP's default 128M is
        // not enough for real applications. Local/testing-only, so raising is safe.
        MemoryLimit::ensureAtLeast(config('logic-map.http.memory_limit', '1G'));

        return $next($request);
    }
}
