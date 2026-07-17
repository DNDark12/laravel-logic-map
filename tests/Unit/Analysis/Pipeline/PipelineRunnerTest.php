<?php

namespace DNDark\LogicMap\Tests\Unit\Analysis\Pipeline;

use DNDark\LogicMap\Analysis\Pipeline\AnalysisPhase;
use DNDark\LogicMap\Analysis\Pipeline\AnalysisPhaseFailed;
use DNDark\LogicMap\Analysis\Pipeline\PhaseResult;
use DNDark\LogicMap\Analysis\Pipeline\PipelineContext;
use DNDark\LogicMap\Analysis\Pipeline\PipelineDefinitionException;
use DNDark\LogicMap\Analysis\Pipeline\PipelineRunner;
use DNDark\LogicMap\Domain\Graph\KnowledgeGraph;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class PipelineRunnerTest extends TestCase
{
    public function test_runs_in_topological_order_with_only_declared_dependencies_and_shared_graph(): void
    {
        $order = [];
        $dependenciesSeen = [];
        $graphsSeen = [];
        $phase = static function (string $name, array $dependencies) use (&$order, &$dependenciesSeen, &$graphsSeen): FakePhase {
            return new FakePhase($name, $dependencies, static function (
                PipelineContext $context,
                array $results,
            ) use ($name, &$order, &$dependenciesSeen, &$graphsSeen): PhaseResult {
                $order[] = $name;
                $dependenciesSeen[$name] = array_keys($results);
                $graphsSeen[] = spl_object_id($context->graph);

                return new PhaseResult($name, ['phase' => $name]);
            });
        };
        $graph = new KnowledgeGraph();
        $runner = new PipelineRunner([
            $phase('resolve', ['parse']),
            $phase('scan', []),
            $phase('parse', ['scan']),
        ]);

        $results = $runner->run(new PipelineContext($graph, ['root' => '/repo']));

        self::assertSame(['scan', 'parse', 'resolve'], $order);
        self::assertSame([], $dependenciesSeen['scan']);
        self::assertSame(['scan'], $dependenciesSeen['parse']);
        self::assertSame(['parse'], $dependenciesSeen['resolve']);
        self::assertSame(['scan', 'parse', 'resolve'], array_keys($results));
        self::assertSame([spl_object_id($graph)], array_values(array_unique($graphsSeen)));

        foreach ($results as $result) {
            self::assertArrayHasKey('duration_ns', $result->metrics);
            self::assertGreaterThanOrEqual(0, $result->metrics['duration_ns']);
        }
    }

    public function test_rejects_duplicate_names_and_missing_dependencies_before_execution(): void
    {
        $executions = 0;
        $execute = static function () use (&$executions): PhaseResult {
            $executions++;

            return new PhaseResult('a');
        };

        try {
            (new PipelineRunner([
                new FakePhase('a', [], $execute),
                new FakePhase('a', [], $execute),
            ]))->run(new PipelineContext(new KnowledgeGraph()));
            self::fail('Duplicate phase names should be rejected.');
        } catch (PipelineDefinitionException $exception) {
            self::assertStringContainsString('Duplicate phase name: a', $exception->getMessage());
        }

        self::assertSame(0, $executions);

        try {
            (new PipelineRunner([
                new FakePhase('a', ['missing'], $execute),
            ]))->run(new PipelineContext(new KnowledgeGraph()));
            self::fail('Missing dependencies should be rejected.');
        } catch (PipelineDefinitionException $exception) {
            self::assertStringContainsString('a -> missing', $exception->getMessage());
        }

        self::assertSame(0, $executions);
    }

    public function test_reports_a_concrete_cycle_path_before_execution(): void
    {
        $executions = 0;
        $execute = static function () use (&$executions): PhaseResult {
            $executions++;

            return new PhaseResult('never');
        };
        $runner = new PipelineRunner([
            new FakePhase('a', ['b'], $execute),
            new FakePhase('b', ['c'], $execute),
            new FakePhase('c', ['a'], $execute),
        ]);

        try {
            $runner->run(new PipelineContext(new KnowledgeGraph()));
            self::fail('Cycles should be rejected.');
        } catch (PipelineDefinitionException $exception) {
            self::assertStringContainsString('a -> b -> c -> a', $exception->getMessage());
        }

        self::assertSame(0, $executions);
    }

    public function test_wraps_the_first_phase_failure_and_retains_the_original_throwable(): void
    {
        $original = new RuntimeException('parser exploded');
        $afterRan = false;
        $runner = new PipelineRunner([
            new FakePhase('parse', [], static fn () => throw $original),
            new FakePhase('resolve', ['parse'], static function () use (&$afterRan): PhaseResult {
                $afterRan = true;

                return new PhaseResult('resolve');
            }),
        ]);

        try {
            $runner->run(new PipelineContext(new KnowledgeGraph()));
            self::fail('Phase failures should be wrapped.');
        } catch (AnalysisPhaseFailed $failure) {
            self::assertSame('parse', $failure->phaseName);
            self::assertSame($original, $failure->getPrevious());
        }

        self::assertFalse($afterRan);
    }
}

final readonly class FakePhase implements AnalysisPhase
{
    public function __construct(
        private string $phaseName,
        private array $phaseDependencies,
        private mixed $callback,
    ) {
    }

    public function name(): string
    {
        return $this->phaseName;
    }

    public function dependencies(): array
    {
        return $this->phaseDependencies;
    }

    public function execute(PipelineContext $context, array $dependencies): PhaseResult
    {
        return ($this->callback)($context, $dependencies);
    }
}
