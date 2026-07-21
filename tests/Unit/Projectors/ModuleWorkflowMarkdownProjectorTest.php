<?php

namespace DNDark\LogicMap\Tests\Unit\Projectors;

use DateTimeImmutable;
use DNDark\LogicMap\Domain\Graph\NodeId;
use DNDark\LogicMap\Domain\Workflow\ExecutionBoundary;
use DNDark\LogicMap\Domain\Workflow\ModuleWorkflow;
use DNDark\LogicMap\Domain\Workflow\WorkflowDefinition;
use DNDark\LogicMap\Domain\Workflow\WorkflowId;
use DNDark\LogicMap\Domain\Workflow\WorkflowStep;
use DNDark\LogicMap\Domain\Workflow\WorkflowStepKind;
use DNDark\LogicMap\Domain\Workflow\WorkflowTransition;
use DNDark\LogicMap\Projectors\ModuleWorkflowMarkdownProjector;
use DNDark\LogicMap\Projectors\WorkflowDossierMarkdownProjector;
use PHPUnit\Framework\TestCase;

final class ModuleWorkflowMarkdownProjectorTest extends TestCase
{
    public function test_module_dossier_contains_all_entrypoints_relations_resources_and_mermaid(): void
    {
        $first = $this->workflow('orders/create', 'Create order');
        $second = $this->workflow('orders/cancel', 'Cancel order');
        $module = new ModuleWorkflow(
            NodeId::fromString('module:Orders'),
            'Orders',
            [$first, $second],
            ['calls' => [['source_id' => 'module:Checkout', 'target_id' => 'module:Orders']]],
            ['dispatches' => [['source_id' => 'module:Orders', 'target_id' => 'module:Inventory']]],
            [['resource_node_id' => 'table:orders', 'kind' => 'table']],
        );

        $markdown = (new ModuleWorkflowMarkdownProjector())->project(
            $module,
            'snapshot-1',
            new DateTimeImmutable('2026-07-20T10:00:00+00:00'),
        );

        self::assertStringStartsWith("---\nschema_version: 2\n", $markdown);
        self::assertStringContainsString('target_id: "module:Orders"', $markdown);
        self::assertStringContainsString('# Module workflow Orders', $markdown);
        self::assertStringContainsString('route:POST:orders/create', $markdown);
        self::assertStringContainsString('route:POST:orders/cancel', $markdown);
        self::assertStringContainsString('module:Checkout', $markdown);
        self::assertStringContainsString('module:Inventory', $markdown);
        self::assertStringContainsString('table:orders', $markdown);
        self::assertSame(2, substr_count($markdown, '```mermaid'));
        self::assertSame(2, substr_count($markdown, 'flowchart TD'));
    }

    public function test_workflow_dossier_embeds_the_existing_markdown_and_mermaid_projection(): void
    {
        $workflow = $this->workflow('orders/cancel', 'Cancel order');
        $markdown = (new WorkflowDossierMarkdownProjector())->project(
            $workflow,
            'snapshot-1',
            new DateTimeImmutable('2026-07-20T10:00:00+00:00'),
        );

        self::assertStringContainsString('# Workflow route:POST:orders/cancel', $markdown);
        self::assertStringContainsString('## Diagram', $markdown);
        self::assertStringContainsString("```mermaid\nflowchart TD", $markdown);
        self::assertStringContainsString('Cancel order', $markdown);
    }

    private function workflow(string $uri, string $label): WorkflowDefinition
    {
        $entrypoint = NodeId::route('POST', $uri);
        $entry = 'step:'.$uri.':entry';
        $effect = 'step:'.$uri.':effect';

        return new WorkflowDefinition(
            new WorkflowId('workflow:'.$uri),
            $entrypoint,
            $entry,
            [
                new WorkflowStep($entry, WorkflowStepKind::Entry, $label, $entrypoint, 'Orders', []),
                new WorkflowStep(
                    $effect,
                    WorkflowStepKind::Effect,
                    'Write orders',
                    NodeId::fromString('table:orders'),
                    'Orders',
                    [],
                ),
            ],
            [new WorkflowTransition($entry, $effect, ExecutionBoundary::Sync, null, null, false, [])],
            [],
        );
    }
}
