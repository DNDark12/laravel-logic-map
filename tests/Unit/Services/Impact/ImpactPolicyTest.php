<?php

namespace DNDark\LogicMap\Tests\Unit\Services\Impact;

use DNDark\LogicMap\Domain\Graph\EdgeType;
use DNDark\LogicMap\Domain\Impact\ImpactCategory;
use DNDark\LogicMap\Services\Impact\ImpactPolicy;
use DNDark\LogicMap\Services\Workflow\EdgeDirectionPolicy;
use PHPUnit\Framework\TestCase;

final class ImpactPolicyTest extends TestCase
{
    public function test_every_category_has_an_explicit_edge_whitelist_and_normative_directions(): void
    {
        $directions = new EdgeDirectionPolicy();
        $policy = new ImpactPolicy($directions);

        self::assertSame(
            array_map(static fn (ImpactCategory $category): string => $category->value, ImpactCategory::cases()),
            array_keys($policy->rules()),
        );

        foreach ($policy->rules() as $category => $rule) {
            if ($category !== ImpactCategory::Uncertainty->value) {
                self::assertNotEmpty($rule['edges']);
            }

            foreach ($rule['edges'] as $edgeType) {
                self::assertSame(
                    $directions->impactDirection($edgeType),
                    $policy->direction($edgeType),
                    $edgeType->value,
                );
            }
        }

        self::assertContains(EdgeType::Calls, $policy->edgeTypes(ImpactCategory::HardDependency));
        self::assertContains(EdgeType::StepInProcess, $policy->edgeTypes(ImpactCategory::Workflow));
        self::assertContains(EdgeType::Dispatches, $policy->edgeTypes(ImpactCategory::Async));
        self::assertContains(EdgeType::WritesColumn, $policy->edgeTypes(ImpactCategory::SharedState));
        self::assertContains(EdgeType::CallsExternal, $policy->edgeTypes(ImpactCategory::ExternalContract));
        self::assertContains(EdgeType::MemberOfModule, $policy->edgeTypes(ImpactCategory::Module));
        self::assertContains(EdgeType::CoveredByTest, $policy->edgeTypes(ImpactCategory::TestScope));
    }
}
