<?php

namespace DNDark\LogicMap\Analysis\Laravel\Detectors;

use DNDark\LogicMap\Analysis\Facts\SemanticFact;
use DNDark\LogicMap\Analysis\Laravel\ExternalEffectFactory;
use DNDark\LogicMap\Domain\Graph\EdgeType;
use DNDark\LogicMap\Domain\Snapshot\Diagnostic;
use DNDark\LogicMap\Domain\Snapshot\DiagnosticCode;
use DNDark\LogicMap\Support\TemplateNormalizer;

final class StorageEffectDetector
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
            if (! $fact instanceof SemanticFact || ($fact->attributes['family'] ?? null) !== 'storage') {
                continue;
            }

            $chain = $fact->attributes['chain'];
            $disk = ($chain[0]['method'] ?? null) === 'disk'
                ? $this->normalizer->normalize($chain[0]['arguments'][0] ?? null)
                : 'default';
            $path = $this->normalizer->normalize($fact->attributes['arguments'][0] ?? null);

            $resource = $disk === null || $path === null ? null : $disk.':'.$path;

            if ($resource === null || preg_replace('/\{[^}]+\}/', '', $resource) === ':') {
                $diagnostics[] = new Diagnostic(
                    DiagnosticCode::DynamicClassString,
                    'laravel_semantics',
                    $fact->file,
                    $fact->startLine,
                    $fact->endLine,
                    'Storage disk or path has no stable representation.',
                    ['method' => $fact->attributes['method']],
                );

                continue;
            }

            $method = $fact->attributes['method'];
            $effect = in_array($method, ['get', 'read', 'exists'], true)
                ? EdgeType::ReadsStorage
                : EdgeType::WritesStorage;
            $effects[] = ExternalEffectFactory::make(
                $fact,
                $effect,
                'storage_path',
                $resource,
                attributes: ['operation' => $method, 'disk' => $disk],
            );
        }

        return new ExternalEffectDetectionResult($effects, $diagnostics);
    }
}
