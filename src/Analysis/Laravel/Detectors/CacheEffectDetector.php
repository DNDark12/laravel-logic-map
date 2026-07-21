<?php

namespace DNDark\LogicMap\Analysis\Laravel\Detectors;

use DNDark\LogicMap\Analysis\Facts\SemanticFact;
use DNDark\LogicMap\Analysis\Laravel\ExternalEffectFactory;
use DNDark\LogicMap\Domain\Graph\EdgeType;
use DNDark\LogicMap\Domain\Snapshot\Diagnostic;
use DNDark\LogicMap\Domain\Snapshot\DiagnosticCode;
use DNDark\LogicMap\Support\TemplateNormalizer;

final class CacheEffectDetector
{
    private TemplateNormalizer $normalizer;

    public function __construct()
    {
        $this->normalizer = new TemplateNormalizer();
    }

    public function detect(array $facts): ExternalEffectDetectionResult
    {
        $effects = [];
        $diagnostics = [];

        foreach ($facts as $fact) {
            if (! $fact instanceof SemanticFact || ($fact->attributes['family'] ?? null) !== 'cache') {
                continue;
            }

            $method = $fact->attributes['method'];
            $key = $this->normalizer->normalize($fact->attributes['arguments'][0] ?? null);

            if ($key === null || preg_replace('/\{[^}]+\}/', '', $key) === '') {
                $diagnostics[] = new Diagnostic(
                    DiagnosticCode::UnknownCacheKey,
                    'laravel_semantics',
                    $fact->file,
                    $fact->startLine,
                    $fact->endLine,
                    'Cache key has no stable literal prefix.',
                    ['method' => $method],
                );

                continue;
            }

            if (in_array($method, ['get', 'has', 'pull', 'remember', 'rememberForever'], true)) {
                $effects[] = ExternalEffectFactory::make(
                    $fact,
                    EdgeType::ReadsCache,
                    'cache_key',
                    $key,
                    attributes: ['operation' => $method],
                );
            }

            if (in_array($method, [
                'put', 'add', 'forever', 'increment', 'decrement', 'remember', 'rememberForever',
            ], true)) {
                $effects[] = ExternalEffectFactory::make(
                    $fact,
                    EdgeType::WritesCache,
                    'cache_key',
                    $key,
                    attributes: ['operation' => $method],
                );
            }

            if (in_array($method, ['forget', 'delete', 'pull'], true)) {
                $effects[] = ExternalEffectFactory::make(
                    $fact,
                    EdgeType::InvalidatesCache,
                    'cache_key',
                    $key,
                    attributes: ['operation' => $method],
                );
            }
        }

        return new ExternalEffectDetectionResult($effects, $diagnostics);
    }
}
