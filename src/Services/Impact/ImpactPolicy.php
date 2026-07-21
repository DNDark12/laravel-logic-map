<?php

namespace DNDark\LogicMap\Services\Impact;

use DNDark\LogicMap\Domain\Graph\EdgeType;
use DNDark\LogicMap\Domain\Impact\ImpactCategory;
use DNDark\LogicMap\Services\Workflow\EdgeDirectionPolicy;

final readonly class ImpactPolicy
{
    public function __construct(private EdgeDirectionPolicy $directions)
    {
    }

    public function rules(): array
    {
        $rules = [];

        foreach (ImpactCategory::cases() as $category) {
            $rules[$category->value] = ['edges' => $this->edgeTypes($category)];
        }

        return $rules;
    }

    /** @return list<EdgeType> */
    public function edgeTypes(ImpactCategory $category): array
    {
        return match ($category) {
            ImpactCategory::HardDependency => [
                EdgeType::Extends, EdgeType::Implements, EdgeType::UsesTrait,
                EdgeType::Calls, EdgeType::Instantiates, EdgeType::Injects,
                EdgeType::BindsTo, EdgeType::ResolvesTo, EdgeType::HandlesRoute,
                EdgeType::AppliesMiddleware, EdgeType::ValidatesWith, EdgeType::AuthorizesWith,
            ],
            ImpactCategory::Workflow => [EdgeType::StepInProcess],
            ImpactCategory::Async => [
                EdgeType::Dispatches, EdgeType::ListensTo, EdgeType::Queues, EdgeType::Schedules,
            ],
            ImpactCategory::SharedState => [
                EdgeType::ReadsModel, EdgeType::WritesModel,
                EdgeType::ReadsTable, EdgeType::WritesTable,
                EdgeType::ReadsColumn, EdgeType::WritesColumn,
                EdgeType::ReadsCache, EdgeType::WritesCache, EdgeType::InvalidatesCache,
                EdgeType::ReadsStorage, EdgeType::WritesStorage,
            ],
            ImpactCategory::ExternalContract => [
                EdgeType::ReadsConfig, EdgeType::ReadsCache, EdgeType::WritesCache,
                EdgeType::InvalidatesCache, EdgeType::ReadsStorage, EdgeType::WritesStorage,
                EdgeType::RendersView, EdgeType::CallsExternal,
                EdgeType::SendsNotification, EdgeType::SendsMail,
            ],
            ImpactCategory::Module => [EdgeType::MemberOfModule],
            ImpactCategory::TestScope => [EdgeType::CoveredByTest],
            ImpactCategory::Uncertainty => [],
        };
    }

    public function direction(EdgeType $type): string
    {
        return $this->directions->impactDirection($type);
    }

    public function traversalDirection(ImpactCategory $category, EdgeType $type): string
    {
        if ($category === ImpactCategory::Async
            && in_array($type, [EdgeType::Dispatches, EdgeType::Schedules], true)) {
            return 'both';
        }

        if ($category === ImpactCategory::ExternalContract) {
            return 'both';
        }

        return $this->direction($type);
    }
}
