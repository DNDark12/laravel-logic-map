<?php

namespace DNDark\LogicMap\Projectors;

use DateTimeInterface;
use DNDark\LogicMap\Domain\Workflow\ModuleWorkflow;

final class ModuleWorkflowMarkdownProjector
{
    public function project(
        ModuleWorkflow $moduleWorkflow,
        string $snapshotId,
        DateTimeInterface $generatedAt,
        array $entryEvidence = [],
    ): string {
        $json = (new ModuleWorkflowJsonProjector())->project($moduleWorkflow, $snapshotId, $entryEvidence);
        $lines = [
            '---',
            'schema_version: 2',
            'snapshot_id: '.$this->yaml($snapshotId),
            'target_id: '.$this->yaml($moduleWorkflow->moduleId->value),
            'generated_at: '.$this->yaml($generatedAt->format(DATE_ATOM)),
            '---',
            '',
            '# Module workflow '.$this->inline($moduleWorkflow->name),
            '',
            '## Summary',
            '',
            '| Metric | Value |',
            '| --- | ---: |',
        ];

        foreach ($json['summary'] as $name => $value) {
            $lines[] = '| '.$this->inline(str_replace('_', ' ', $name)).' | '.$value.' |';
        }

        $lines = [...$lines, '', '## Entry workflows', ''];

        foreach ($moduleWorkflow->entryWorkflows as $workflow) {
            $lines[] = '- `'.$this->inline($workflow->entrypoint->value).'` — '.$workflow->summary()->stepCount.' steps';
        }

        if ($moduleWorkflow->entryWorkflows === []) {
            $lines[] = '- None';
        }

        $this->relationSection($lines, 'Inbound relations', $moduleWorkflow->inboundRelations);
        $this->relationSection($lines, 'Outbound relations', $moduleWorkflow->outboundRelations);
        $lines = [...$lines, '', '## Shared resources', ''];

        foreach ($moduleWorkflow->sharedResources as $resource) {
            $lines[] = '- `'.$this->inline($this->resourceId($resource)).'` — '.$this->inline($this->json($resource));
        }

        if ($moduleWorkflow->sharedResources === []) {
            $lines[] = '- None';
        }

        $lines = [...$lines, '', '## Gaps', ''];

        foreach ($moduleWorkflow->gaps as $gap) {
            $lines[] = '- `'.$this->inline($gap->stepId).'`: '.$this->inline($gap->reason);
        }

        if ($moduleWorkflow->gaps === []) {
            $lines[] = '- None';
        }

        $lines = [...$lines, '', '## Workflow diagrams', ''];
        $mermaid = new WorkflowMermaidProjector();

        foreach ($moduleWorkflow->entryWorkflows as $workflow) {
            $lines[] = '### `'.$this->inline($workflow->entrypoint->value).'`';
            $lines[] = '';
            $lines[] = '```mermaid';
            $lines[] = rtrim($mermaid->project($workflow));
            $lines[] = '```';
            $lines[] = '';
        }

        return rtrim(implode("\n", $lines))."\n";
    }

    private function relationSection(array &$lines, string $title, array $relations): void
    {
        $lines = [...$lines, '', '## '.$title, ''];
        $count = 0;

        foreach ($relations as $type => $rows) {
            foreach ($rows as $row) {
                $lines[] = '- **'.$this->inline((string) $type).'** — `'.$this->inline($this->json($row)).'`';
                $count++;
            }
        }

        if ($count === 0) {
            $lines[] = '- None';
        }
    }

    private function resourceId(array $resource): string
    {
        foreach (['resource_node_id', 'node_id', 'id', 'target_id'] as $key) {
            if (is_string($resource[$key] ?? null) && $resource[$key] !== '') {
                return $resource[$key];
            }
        }

        return 'resource';
    }

    private function yaml(string $value): string
    {
        return json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    private function json(array $value): string
    {
        return json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    private function inline(string $value): string
    {
        return str_replace(["\r", "\n", '|'], ['', ' ', '\\|'], $value);
    }
}
