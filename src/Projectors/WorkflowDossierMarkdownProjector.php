<?php

namespace DNDark\LogicMap\Projectors;

use DateTimeInterface;
use DNDark\LogicMap\Domain\Workflow\WorkflowDefinition;

final class WorkflowDossierMarkdownProjector
{
    public function project(
        WorkflowDefinition $workflow,
        string $snapshotId,
        DateTimeInterface $generatedAt,
        array $evidence = [],
    ): string {
        $markdown = (new WorkflowMarkdownProjector())->project(
            $workflow,
            $snapshotId,
            $generatedAt,
            $evidence,
        );
        $diagram = (new WorkflowMermaidProjector())->project($workflow);

        return rtrim($markdown)."\n\n## Diagram\n\n```mermaid\n".$diagram."```\n";
    }
}
