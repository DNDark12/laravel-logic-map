<?php

namespace DNDark\LogicMap\Services\Workflow;

use DNDark\LogicMap\Domain\Graph\EdgeType;

final class EdgeDirectionPolicy
{
    public function rules(): array
    {
        $rules = [];

        foreach (EdgeType::cases() as $type) {
            $rules[$type->value] = $this->rule($type);
        }

        return $rules;
    }

    public function workflow(EdgeType $type): bool
    {
        return $this->workflowDirection($type) !== 'none';
    }

    public function workflowDirection(EdgeType $type): string
    {
        return $this->rule($type)['workflow'];
    }

    public function impactDirection(EdgeType $type): string
    {
        return $this->rule($type)['impact'];
    }

    public function priority(EdgeType $type): int
    {
        return $this->rule($type)['priority'];
    }

    private function rule(EdgeType $type): array
    {
        $workflow = match ($type) {
            EdgeType::ListensTo => 'reverse',
            EdgeType::HandlesRoute, EdgeType::AppliesMiddleware, EdgeType::ValidatesWith,
            EdgeType::AuthorizesWith, EdgeType::Calls, EdgeType::Instantiates,
            EdgeType::BindsTo, EdgeType::ResolvesTo, EdgeType::Dispatches, EdgeType::Queues,
            EdgeType::Schedules, EdgeType::ReadsModel, EdgeType::WritesModel,
            EdgeType::ReadsTable, EdgeType::WritesTable, EdgeType::ReadsColumn,
            EdgeType::WritesColumn, EdgeType::ReadsCache, EdgeType::WritesCache,
            EdgeType::InvalidatesCache, EdgeType::ReadsConfig, EdgeType::ReadsStorage,
            EdgeType::WritesStorage, EdgeType::RendersView, EdgeType::CallsExternal,
            EdgeType::SendsNotification, EdgeType::SendsMail, EdgeType::BranchesTo => 'forward',
            default => 'none',
        };
        $impact = match ($type) {
            EdgeType::BindsTo, EdgeType::ResolvesTo, EdgeType::ListensTo, EdgeType::Queues => 'both',
            EdgeType::MemberOfModule, EdgeType::StepInProcess => 'forward',
            EdgeType::Contains, EdgeType::Defines, EdgeType::BranchesTo => 'none',
            default => 'reverse',
        };
        $priority = match ($type) {
            EdgeType::AppliesMiddleware => 10,
            EdgeType::HandlesRoute => 20,
            EdgeType::ValidatesWith => 30,
            EdgeType::AuthorizesWith => 40,
            EdgeType::Calls, EdgeType::ResolvesTo, EdgeType::BindsTo => 50,
            EdgeType::WritesColumn, EdgeType::WritesTable, EdgeType::WritesModel => 60,
            EdgeType::Dispatches => 70,
            EdgeType::ListensTo => 80,
            EdgeType::Queues => 90,
            default => 100,
        };

        return ['workflow' => $workflow, 'impact' => $impact, 'priority' => $priority];
    }
}
