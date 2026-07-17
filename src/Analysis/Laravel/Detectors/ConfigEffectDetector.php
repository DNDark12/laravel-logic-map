<?php

namespace DNDark\LogicMap\Analysis\Laravel\Detectors;

use DNDark\LogicMap\Analysis\Facts\SemanticFact;
use DNDark\LogicMap\Analysis\Laravel\ExternalEffectFactory;
use DNDark\LogicMap\Domain\Graph\EdgeType;
use DNDark\LogicMap\Domain\Snapshot\Diagnostic;
use DNDark\LogicMap\Domain\Snapshot\DiagnosticCode;
use DNDark\LogicMap\Support\TemplateNormalizer;

final class ConfigEffectDetector
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
            if (! $fact instanceof SemanticFact || ($fact->attributes['family'] ?? null) !== 'config') {
                continue;
            }

            $key = $this->normalizer->normalize($fact->attributes['arguments'][0] ?? null);

            if ($key === null || str_contains($key, '{')) {
                $diagnostics[] = $this->diagnostic($fact);

                continue;
            }

            $effects[] = ExternalEffectFactory::make(
                $fact,
                EdgeType::ReadsConfig,
                'config_key',
                $key,
                attributes: ['operation' => 'get'],
            );
        }

        return new ExternalEffectDetectionResult($effects, $diagnostics);
    }

    private function diagnostic(SemanticFact $fact): Diagnostic
    {
        return new Diagnostic(
            DiagnosticCode::DynamicClassString,
            'laravel_semantics',
            $fact->file,
            $fact->startLine,
            $fact->endLine,
            'Configuration key is dynamic.',
            ['family' => 'config'],
        );
    }
}
