<?php

namespace dndark\LogicMap\Analysis\Runtime;

use dndark\LogicMap\Domain\Graph;
use Illuminate\Support\Facades\Log;
use SimpleXMLElement;
use Throwable;

/**
 * Enrich graph class/method nodes with test coverage metadata from Clover XML.
 */
class CoverageMetadataCollector
{
    /**
     * @param Graph $graph
     */
    public function collect(Graph $graph): void
    {
        if (!(bool)config('logic-map.coverage.enabled', true)) {
            return;
        }

        $cloverPath = (string)config('logic-map.coverage.clover_path', base_path('coverage/clover.xml'));
        if ($cloverPath === '' || !is_file($cloverPath) || !is_readable($cloverPath)) {
            return;
        }

        $index = $this->loadCoverageIndex($cloverPath);
        if ($index === null) {
            return;
        }

        $assumeUncovered = (bool)config('logic-map.coverage.assume_uncovered_when_missing', false);

        foreach ($graph->getNodes() as $node) {
            $symbol = $this->extractSymbolFromNodeId($node->id);
            if ($symbol === null) {
                continue;
            }

            [$class, $method] = $symbol;

            $coverage = null;
            $scope = 'class';
            if ($method !== null) {
                $coverage = $index['methods'][$class . '@' . $method] ?? null;
                $scope = 'method';
            }

            if ($coverage === null) {
                $coverage = $index['classes'][$class] ?? null;
                if ($method !== null && $coverage !== null) {
                    $scope = 'class_fallback';
                }
            }

            if ($coverage === null) {
                $coverage = $assumeUncovered
                    ? $this->assumedUncoveredPayload()
                    : $this->unknownPayload();
            }

            $coverage['scope'] = $scope;
            $node->metadata['coverage'] = $coverage;
            $node->metadata['coverage_percent'] = $coverage['coverage_percent'];
            $node->metadata['coverage_level'] = $coverage['coverage_level'];
        }
    }

    /**
     * @return array{classes: array<string, array>, methods: array<string, array>}|null
     */
    protected function loadCoverageIndex(string $path): ?array
    {
        $previous = libxml_use_internal_errors(true);

        try {
            $xml = simplexml_load_file($path, SimpleXMLElement::class, LIBXML_NONET);
        } catch (Throwable $e) {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
            Log::warning('Logic Map: Failed to load Clover coverage report', [
                'path' => $path,
                'error' => $e->getMessage(),
            ]);

            return null;
        }

        $errors = libxml_get_errors();
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        if (!$xml instanceof SimpleXMLElement) {
            if (!empty($errors)) {
                Log::warning('Logic Map: Invalid Clover coverage report', [
                    'path' => $path,
                    'error' => trim($errors[0]->message ?? 'Unknown XML parse error'),
                ]);
            }

            return null;
        }

        $classes = [];
        $methods = [];
        $fileNodes = $xml->xpath('//file') ?: [];

        foreach ($fileNodes as $fileNode) {
            /** @var SimpleXMLElement $fileNode */
            $fileName = (string)($fileNode['name'] ?? '');
            foreach ($fileNode->class as $classNode) {
                /** @var SimpleXMLElement $classNode */
                $className = $this->normalizeClassName((string)($classNode['name'] ?? ''));
                if ($className === '') {
                    continue;
                }

                $classPayload = $this->buildCoveragePayload(
                    isset($classNode->metrics[0]) ? $classNode->metrics[0] : null,
                    $fileName
                );
                $classes[$className] = $this->pickBetterCoverage($classes[$className] ?? null, $classPayload);

                foreach ($classNode->method as $methodNode) {
                    /** @var SimpleXMLElement $methodNode */
                    $methodName = (string)($methodNode['name'] ?? '');
                    if ($methodName === '') {
                        continue;
                    }

                    $methodPayload = $this->buildCoveragePayload(
                        isset($methodNode->metrics[0]) ? $methodNode->metrics[0] : null,
                        $fileName
                    );
                    $key = $className . '@' . $methodName;
                    $methods[$key] = $this->pickBetterCoverage($methods[$key] ?? null, $methodPayload);
                }
            }
        }

        return [
            'classes' => $classes,
            'methods' => $methods,
        ];
    }

    /**
     * @return array{0: string, 1: string|null}|null
     */
    protected function extractSymbolFromNodeId(string $nodeId): ?array
    {
        if (str_starts_with($nodeId, 'class:')) {
            return [$this->normalizeClassName(substr($nodeId, 6)), null];
        }

        if (str_starts_with($nodeId, 'method:')) {
            $symbol = substr($nodeId, 7);
            if (!str_contains($symbol, '@')) {
                return null;
            }

            [$class, $method] = explode('@', $symbol, 2);
            $class = $this->normalizeClassName($class);
            if ($class === '' || $method === '') {
                return null;
            }

            return [$class, $method];
        }

        return null;
    }

    /**
     * @param ?SimpleXMLElement $metrics
     * @return array{
     *   line_rate: ?float,
     *   coverage_percent: ?int,
     *   coverage_level: string,
     *   statements: ?int,
     *   covered_statements: ?int,
     *   methods: ?int,
     *   covered_methods: ?int,
     *   source: string,
     *   file: ?string,
     *   assumed: bool
     * }
     */
    protected function buildCoveragePayload(?SimpleXMLElement $metrics, string $fileName): array
    {
        $statements = $this->readIntAttr($metrics, 'statements');
        $coveredStatements = $this->readIntAttr($metrics, 'coveredstatements');
        $methods = $this->readIntAttr($metrics, 'methods');
        $coveredMethods = $this->readIntAttr($metrics, 'coveredmethods');

        $rate = null;
        if ($statements !== null && $statements > 0) {
            $rate = $coveredStatements !== null ? $coveredStatements / $statements : 0.0;
        } elseif ($methods !== null && $methods > 0) {
            $rate = $coveredMethods !== null ? $coveredMethods / $methods : 0.0;
        }

        if ($rate !== null) {
            $rate = max(0.0, min(1.0, $rate));
        }

        return [
            'line_rate' => $rate,
            'coverage_percent' => $rate !== null ? (int)round($rate * 100) : null,
            'coverage_level' => $this->toCoverageLevel($rate),
            'statements' => $statements,
            'covered_statements' => $coveredStatements,
            'methods' => $methods,
            'covered_methods' => $coveredMethods,
            'source' => 'clover',
            'file' => $fileName !== '' ? $fileName : null,
            'assumed' => false,
        ];
    }

    /**
     * @param array<string, mixed>|null $current
     * @param array<string, mixed> $candidate
     * @return array<string, mixed>
     */
    protected function pickBetterCoverage(?array $current, array $candidate): array
    {
        if ($current === null) {
            return $candidate;
        }

        $currentStatements = (int)($current['statements'] ?? 0);
        $candidateStatements = (int)($candidate['statements'] ?? 0);
        if ($candidateStatements > $currentStatements) {
            return $candidate;
        }

        $currentMethods = (int)($current['methods'] ?? 0);
        $candidateMethods = (int)($candidate['methods'] ?? 0);
        if ($candidateMethods > $currentMethods) {
            return $candidate;
        }

        return $current;
    }

    /**
     * @param ?SimpleXMLElement $metrics
     */
    protected function readIntAttr(?SimpleXMLElement $metrics, string $attr): ?int
    {
        if (!$metrics instanceof SimpleXMLElement) {
            return null;
        }

        if (!isset($metrics[$attr])) {
            return null;
        }

        $value = (string)$metrics[$attr];
        return is_numeric($value) ? (int)$value : null;
    }

    protected function toCoverageLevel(?float $rate): string
    {
        if ($rate === null) {
            return 'unknown';
        }

        $low = (float)config('logic-map.coverage.low_threshold', 0.5);
        $high = (float)config('logic-map.coverage.high_threshold', 0.8);
        if ($high < $low) {
            $high = $low;
        }

        if ($rate <= 0.0) {
            return 'none';
        }

        if ($rate < $low) {
            return 'low';
        }

        if ($rate < $high) {
            return 'medium';
        }

        if ($rate < 1.0) {
            return 'high';
        }

        return 'full';
    }

    /**
     * @return array{
     *   line_rate: null,
     *   coverage_percent: null,
     *   coverage_level: string,
     *   statements: null,
     *   covered_statements: null,
     *   methods: null,
     *   covered_methods: null,
     *   source: string,
     *   file: null,
     *   assumed: bool
     * }
     */
    protected function unknownPayload(): array
    {
        return [
            'line_rate' => null,
            'coverage_percent' => null,
            'coverage_level' => 'unknown',
            'statements' => null,
            'covered_statements' => null,
            'methods' => null,
            'covered_methods' => null,
            'source' => 'clover',
            'file' => null,
            'assumed' => false,
        ];
    }

    /**
     * @return array{
     *   line_rate: float,
     *   coverage_percent: int,
     *   coverage_level: string,
     *   statements: int,
     *   covered_statements: int,
     *   methods: int,
     *   covered_methods: int,
     *   source: string,
     *   file: null,
     *   assumed: bool
     * }
     */
    protected function assumedUncoveredPayload(): array
    {
        return [
            'line_rate' => 0.0,
            'coverage_percent' => 0,
            'coverage_level' => 'none',
            'statements' => 0,
            'covered_statements' => 0,
            'methods' => 0,
            'covered_methods' => 0,
            'source' => 'clover',
            'file' => null,
            'assumed' => true,
        ];
    }

    protected function normalizeClassName(string $class): string
    {
        return ltrim(trim($class), '\\');
    }
}
