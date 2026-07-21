<?php

namespace DNDark\LogicMap\Analysis\Pipeline;

use LogicException;
use Throwable;

final readonly class PipelineRunner
{
    /** @param list<AnalysisPhase> $phases */
    public function __construct(private array $phases)
    {
    }

    /** @return array<string, PhaseResult> */
    public function run(PipelineContext $context): array
    {
        $orderedPhases = $this->orderedPhases();
        $results = [];

        foreach ($orderedPhases as $phase) {
            $dependencies = [];

            foreach ($phase->dependencies() as $dependency) {
                $dependencies[$dependency] = $results[$dependency];
            }

            $startedAt = hrtime(true);

            try {
                $result = $phase->execute($context, $dependencies);

                if ($result->phase !== $phase->name()) {
                    throw new LogicException(
                        "Phase {$phase->name()} returned a result for {$result->phase}.",
                    );
                }
            } catch (Throwable $throwable) {
                throw new AnalysisPhaseFailed($phase->name(), $throwable);
            }

            $duration = hrtime(true) - $startedAt;
            $results[$phase->name()] = $result->withMetrics([
                ...$result->metrics,
                'duration_ns' => $duration,
            ]);
        }

        return $results;
    }

    /** @return list<AnalysisPhase> */
    private function orderedPhases(): array
    {
        $phases = [];
        $positions = [];

        foreach ($this->phases as $position => $phase) {
            if (! $phase instanceof AnalysisPhase) {
                throw new PipelineDefinitionException('Pipeline entries must implement AnalysisPhase.');
            }

            $name = trim($phase->name());

            if ($name === '') {
                throw new PipelineDefinitionException('Phase names must be non-empty.');
            }

            if (isset($phases[$name])) {
                throw new PipelineDefinitionException("Duplicate phase name: {$name}.");
            }

            $phases[$name] = $phase;
            $positions[$name] = $position;
        }

        $indegree = array_fill_keys(array_keys($phases), 0);
        $dependents = array_fill_keys(array_keys($phases), []);

        foreach ($phases as $name => $phase) {
            $seen = [];

            foreach ($phase->dependencies() as $dependency) {
                if (! is_string($dependency) || trim($dependency) === '') {
                    throw new PipelineDefinitionException("Phase {$name} has an invalid dependency name.");
                }

                if (isset($seen[$dependency])) {
                    throw new PipelineDefinitionException("Phase {$name} repeats dependency {$dependency}.");
                }

                if (! isset($phases[$dependency])) {
                    throw new PipelineDefinitionException("Missing phase dependency: {$name} -> {$dependency}.");
                }

                $seen[$dependency] = true;
                $indegree[$name]++;
                $dependents[$dependency][] = $name;
            }
        }

        $queue = array_keys(array_filter($indegree, static fn (int $degree): bool => $degree === 0));
        $this->sortByPosition($queue, $positions);
        $ordered = [];

        while ($queue !== []) {
            $name = array_shift($queue);
            $ordered[] = $phases[$name];

            foreach ($dependents[$name] as $dependent) {
                $indegree[$dependent]--;

                if ($indegree[$dependent] === 0) {
                    $queue[] = $dependent;
                    $this->sortByPosition($queue, $positions);
                }
            }
        }

        if (count($ordered) !== count($phases)) {
            $cycle = $this->findCycle($phases);
            throw new PipelineDefinitionException('Pipeline cycle: '.implode(' -> ', $cycle).'.');
        }

        return $ordered;
    }

    /**
     * @param list<string> $names
     * @param array<string, int> $positions
     */
    private function sortByPosition(array &$names, array $positions): void
    {
        usort($names, static fn (string $left, string $right): int => $positions[$left] <=> $positions[$right]);
    }

    /**
     * @param array<string, AnalysisPhase> $phases
     * @return list<string>
     */
    private function findCycle(array $phases): array
    {
        $state = [];
        $stack = [];

        $visit = function (string $name) use (&$visit, &$state, &$stack, $phases): ?array {
            $state[$name] = 1;
            $stack[] = $name;

            foreach ($phases[$name]->dependencies() as $dependency) {
                if (($state[$dependency] ?? 0) === 0) {
                    $cycle = $visit($dependency);

                    if ($cycle !== null) {
                        return $cycle;
                    }
                } elseif ($state[$dependency] === 1) {
                    $start = array_search($dependency, $stack, true);

                    return [...array_slice($stack, $start), $dependency];
                }
            }

            array_pop($stack);
            $state[$name] = 2;

            return null;
        };

        foreach (array_keys($phases) as $name) {
            if (($state[$name] ?? 0) === 0 && ($cycle = $visit($name)) !== null) {
                return $cycle;
            }
        }

        return ['unknown', 'unknown'];
    }
}
