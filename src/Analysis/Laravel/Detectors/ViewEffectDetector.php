<?php

namespace DNDark\LogicMap\Analysis\Laravel\Detectors;

use DNDark\LogicMap\Analysis\Facts\SemanticFact;
use DNDark\LogicMap\Analysis\Laravel\ExternalEffectFactory;
use DNDark\LogicMap\Domain\Graph\EdgeType;
use DNDark\LogicMap\Domain\Snapshot\Diagnostic;
use DNDark\LogicMap\Domain\Snapshot\DiagnosticCode;
use DNDark\LogicMap\Support\TemplateNormalizer;

final class ViewEffectDetector
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
            if (! $fact instanceof SemanticFact || ($fact->attributes['family'] ?? null) !== 'view') {
                continue;
            }

            $view = $this->normalizer->normalize($fact->attributes['arguments'][0] ?? null);

            if ($view === null || str_contains($view, '{')) {
                $diagnostics[] = new Diagnostic(
                    DiagnosticCode::DynamicClassString,
                    'laravel_semantics',
                    $fact->file,
                    $fact->startLine,
                    $fact->endLine,
                    'View name is dynamic.',
                    ['family' => 'view'],
                );

                continue;
            }

            $effects[] = ExternalEffectFactory::make(
                $fact,
                EdgeType::RendersView,
                'view',
                $view,
                attributes: ['operation' => 'render'],
            );
        }

        return new ExternalEffectDetectionResult($effects, $diagnostics);
    }
}
