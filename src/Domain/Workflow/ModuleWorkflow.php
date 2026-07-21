<?php

namespace DNDark\LogicMap\Domain\Workflow;

use DNDark\LogicMap\Domain\Graph\NodeId;
use DNDark\LogicMap\Support\CanonicalJson;
use InvalidArgumentException;

final readonly class ModuleWorkflow
{
    /** @var list<WorkflowDefinition> */
    public array $entryWorkflows;

    public array $inboundRelations;

    public array $outboundRelations;

    public array $sharedResources;

    public function __construct(
        public NodeId $moduleId,
        public string $name,
        array $entryWorkflows,
        array $inboundRelations,
        array $outboundRelations,
        array $sharedResources,
        public array $gaps = [],
        public array $diagnostics = [],
    ) {
        if (! str_starts_with($moduleId->value, 'module:') || trim($name) === '') {
            throw new InvalidArgumentException('Module workflows require a module ID and name.');
        }

        foreach ($entryWorkflows as $workflow) {
            if (! $workflow instanceof WorkflowDefinition) {
                throw new InvalidArgumentException('Module entries must contain workflow definitions.');
            }
        }

        usort($entryWorkflows, static fn (WorkflowDefinition $left, WorkflowDefinition $right): int =>
            $left->entrypoint->value <=> $right->entrypoint->value);
        $this->entryWorkflows = array_values($entryWorkflows);
        $this->inboundRelations = $this->sortRelations($inboundRelations);
        $this->outboundRelations = $this->sortRelations($outboundRelations);
        usort($sharedResources, static fn (array $left, array $right): int =>
            CanonicalJson::encode($left) <=> CanonicalJson::encode($right));
        $this->sharedResources = array_values($sharedResources);
    }

    private function sortRelations(array $relations): array
    {
        ksort($relations, SORT_STRING);

        foreach ($relations as &$items) {
            usort($items, static fn (array $left, array $right): int =>
                CanonicalJson::encode($left) <=> CanonicalJson::encode($right));
            $items = array_values($items);
        }
        unset($items);

        return $relations;
    }
}
