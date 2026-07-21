<?php

namespace DNDark\LogicMap\Analysis\Laravel\Detectors;

use DNDark\LogicMap\Analysis\Facts\SemanticFact;
use DNDark\LogicMap\Analysis\Laravel\ExternalEffectFactory;
use DNDark\LogicMap\Domain\Graph\Certainty;
use DNDark\LogicMap\Domain\Graph\EdgeType;
use DNDark\LogicMap\Domain\Snapshot\Diagnostic;
use DNDark\LogicMap\Domain\Snapshot\DiagnosticCode;
use DNDark\LogicMap\Support\TemplateNormalizer;

final class HttpClientEffectDetector
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
            if (! $fact instanceof SemanticFact || ($fact->attributes['family'] ?? null) !== 'http') {
                continue;
            }

            $endpoint = $this->normalizer->normalize($fact->attributes['arguments'][0] ?? null);

            if ($endpoint === null || preg_replace('/\{[^}]+\}/', '', $endpoint) === '') {
                $diagnostics[] = new Diagnostic(
                    DiagnosticCode::DynamicClassString,
                    'laravel_semantics',
                    $fact->file,
                    $fact->startLine,
                    $fact->endLine,
                    'HTTP endpoint has no stable prefix.',
                    ['method' => $fact->attributes['method']],
                );

                continue;
            }

            $effects[] = ExternalEffectFactory::make(
                $fact,
                EdgeType::CallsExternal,
                'external_endpoint',
                $endpoint,
                str_contains($endpoint, '{') ? Certainty::Probable : Certainty::Certain,
                ['method' => strtoupper($fact->attributes['method'])],
            );
        }

        return new ExternalEffectDetectionResult($effects, $diagnostics);
    }
}
