<?php

namespace DNDark\LogicMap\Analysis\Runtime;

use DOMDocument;
use DOMElement;
use DOMXPath;
use DNDark\LogicMap\Domain\Graph\KnowledgeGraph;
use DNDark\LogicMap\Domain\Graph\NodeKind;
use InvalidArgumentException;

final class CloverCoverageImporter
{
    public function import(string $reportPath, KnowledgeGraph $graph, bool $missingIsZero = false): array
    {
        if (! is_file($reportPath) || ! is_readable($reportPath)) {
            throw new InvalidArgumentException('Clover coverage report must be a readable file.');
        }

        $document = new DOMDocument();
        $previous = libxml_use_internal_errors(true);
        $loaded = $document->load($reportPath, LIBXML_NONET | LIBXML_NOBLANKS);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        if (! $loaded) {
            throw new InvalidArgumentException('Clover coverage report is not valid XML.');
        }

        $xpath = new DOMXPath($document);
        $coverage = [];
        $known = [];

        foreach ($graph->nodes() as $node) {
            $known[$node->id->value] = $node;
        }

        foreach ($xpath->query('//file') ?: [] as $file) {
            if (! $file instanceof DOMElement) {
                continue;
            }

            $classElements = $xpath->query('./class', $file) ?: [];
            $className = null;

            foreach ($classElements as $class) {
                if (! $class instanceof DOMElement || trim($class->getAttribute('name')) === '') {
                    continue;
                }

                $className ??= ltrim($class->getAttribute('name'), '\\');
                $classId = $this->classNodeId($class->getAttribute('name'), $known);

                if ($classId === null) {
                    continue;
                }

                $metrics = $xpath->query('./metrics', $class)?->item(0);
                $coverage[$classId] = [
                    'status' => 'reported',
                    'methods' => $metrics instanceof DOMElement ? (int) $metrics->getAttribute('methods') : null,
                    'covered_methods' => $metrics instanceof DOMElement ? (int) $metrics->getAttribute('coveredmethods') : null,
                ];
            }

            if ($className === null) {
                continue;
            }

            foreach ($xpath->query('./line[@type="method"]', $file) ?: [] as $line) {
                if (! $line instanceof DOMElement || trim($line->getAttribute('name')) === '') {
                    continue;
                }

                $methodId = 'method:'.$className.'::'.$line->getAttribute('name');

                if (! isset($known[$methodId])) {
                    continue;
                }

                $hits = (int) $line->getAttribute('count');
                $coverage[$methodId] = [
                    'status' => $hits > 0 ? 'covered' : 'observed_zero',
                    'hit_count' => $hits,
                    'line' => (int) $line->getAttribute('num'),
                ];
            }
        }

        if ($missingIsZero) {
            foreach ($known as $id => $node) {
                if (isset($coverage[$id]) || ! in_array($node->kind, [
                    NodeKind::ClassSymbol,
                    NodeKind::InterfaceSymbol,
                    NodeKind::TraitSymbol,
                    NodeKind::EnumSymbol,
                    NodeKind::Method,
                ], true)) {
                    continue;
                }

                $coverage[$id] = $node->kind === NodeKind::Method
                    ? ['status' => 'explicit_zero', 'hit_count' => 0, 'line' => null]
                    : ['status' => 'explicit_zero', 'methods' => null, 'covered_methods' => 0];
            }
        }

        ksort($coverage, SORT_STRING);

        return [
            'coverage' => $coverage,
            'metadata' => [
                'report_path' => str_replace('\\', '/', $reportPath),
                'report_hash' => hash_file('sha256', $reportPath),
                'missing_coverage' => $missingIsZero ? 'zero' : 'unknown',
            ],
        ];
    }

    private function classNodeId(string $className, array $known): ?string
    {
        $className = ltrim($className, '\\');

        foreach (['class', 'interface', 'trait', 'enum'] as $prefix) {
            $id = $prefix.':'.$className;

            if (isset($known[$id])) {
                return $id;
            }
        }

        return null;
    }
}
