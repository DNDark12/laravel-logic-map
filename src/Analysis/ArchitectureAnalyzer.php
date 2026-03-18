<?php

namespace dndark\LogicMap\Analysis;

use dndark\LogicMap\Analysis\Analyzers\CircularDependencyAnalyzer;
use dndark\LogicMap\Analysis\Analyzers\FatControllerAnalyzer;
use dndark\LogicMap\Analysis\Analyzers\OrphanAnalyzer;
use dndark\LogicMap\Contracts\ViolationAnalyzer;
use dndark\LogicMap\Domain\AnalysisReport;
use dndark\LogicMap\Domain\Graph;
use dndark\LogicMap\Domain\Violation;

/**
 * Orchestrates all registered ViolationAnalyzers and produces an AnalysisReport.
 */
class ArchitectureAnalyzer
{
    /** @var ViolationAnalyzer[] */
    protected array $analyzers = [];

    protected RiskCalculator $riskCalculator;

    public function __construct(?RiskCalculator $riskCalculator = null)
    {
        $this->riskCalculator = $riskCalculator ?? new RiskCalculator();
        $this->registerDefaultAnalyzers();
    }

    /**
     * Run all enabled analyzers and produce an AnalysisReport.
     */
    public function analyze(Graph $graph): AnalysisReport
    {
        if (!config('logic-map.analysis.enabled', true)) {
            return $this->emptyReport();
        }

        $violations = [];
        foreach ($this->analyzers as $analyzer) {
            if ($analyzer->isEnabled()) {
                $violations = array_merge($violations, $analyzer->analyze($graph));
            }
        }

        $nodeRiskMap = $this->riskCalculator->calculate($graph, $violations);
        $summary = $this->summarizeBySeverity($violations);
        $healthScore = $this->calculateHealthScore($violations);

        return new AnalysisReport(
            violations: $violations,
            healthScore: $healthScore,
            grade: $this->scoreToGrade($healthScore),
            summary: $summary,
            nodeRiskMap: $nodeRiskMap,
            metadata: [
                'graph_fingerprint' => null,
                'analysis_config_hash' => $this->getConfigHash(),
                'analyzer_count' => count(array_filter(
                    $this->analyzers,
                    fn(ViolationAnalyzer $a) => $a->isEnabled()
                )),
            ],
        );
    }

    /**
     * Register a custom analyzer.
     */
    public function registerAnalyzer(ViolationAnalyzer $analyzer): void
    {
        $this->analyzers[$analyzer->getName()] = $analyzer;
    }

    /**
     * Get the analysis config hash for cache key construction.
     */
    public function getConfigHash(): string
    {
        $analysisConfig = config('logic-map.analysis', []);
        return md5(json_encode($analysisConfig));
    }

    /**
     * Register the default built-in analyzers.
     */
    protected function registerDefaultAnalyzers(): void
    {
        $this->registerAnalyzer(new FatControllerAnalyzer());
        $this->registerAnalyzer(new CircularDependencyAnalyzer());
        $this->registerAnalyzer(new OrphanAnalyzer());
        $this->registerAnalyzer(new Analyzers\HighInstabilityAnalyzer());
        $this->registerAnalyzer(new Analyzers\HighCouplingAnalyzer());
    }

    /**
     * Calculate health score: 100 = perfect, 0 = critical issues.
     */
    protected function calculateHealthScore(array $violations): int
    {
        if (empty($violations)) {
            return 100;
        }

        $weights = config('logic-map.analysis.weights', [
            'critical' => 25,
            'high' => 10,
            'medium' => 5,
            'low' => 1,
        ]);

        $penalty = 0;
        foreach ($violations as $violation) {
            $penalty += $weights[$violation->severity] ?? 1;
        }

        // Score is 100 minus penalty, clamped to 0-100
        return max(0, min(100, 100 - $penalty));
    }

    protected function scoreToGrade(int $score): string
    {
        $scales = config('logic-map.analysis.grade_scales', [
            90 => 'A', 80 => 'B', 70 => 'C', 60 => 'D', 0 => 'F'
        ]);

        // Sort keys descending to match first applicable range
        krsort($scales);

        foreach ($scales as $threshold => $grade) {
            if ($score >= $threshold) {
                return $grade;
            }
        }

        return 'F';
    }

    /**
     * @param Violation[] $violations
     */
    protected function summarizeBySeverity(array $violations): array
    {
        $summary = ['critical' => 0, 'high' => 0, 'medium' => 0, 'low' => 0];

        foreach ($violations as $violation) {
            if (isset($summary[$violation->severity])) {
                $summary[$violation->severity]++;
            }
        }

        return $summary;
    }

    protected function emptyReport(): AnalysisReport
    {
        return new AnalysisReport(
            violations: [],
            healthScore: 100,
            grade: 'A',
            summary: ['critical' => 0, 'high' => 0, 'medium' => 0, 'low' => 0],
            nodeRiskMap: [],
            metadata: ['analysis_enabled' => false],
        );
    }
}
