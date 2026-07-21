<?php

namespace DNDark\LogicMap\Projectors;

use DateTimeInterface;
use DNDark\LogicMap\Domain\Graph\GraphNode;
use DNDark\LogicMap\Domain\Workflow\WorkflowDefinition;
use InvalidArgumentException;

final class SymbolWorkflowCollectionMarkdownProjector
{
    /** @param list<WorkflowDefinition> $workflows */
    public function project(
        GraphNode $selection,
        array $workflows,
        string $snapshotId,
        DateTimeInterface $generatedAt,
        array $entryEvidence = [],
    ): string {
        $json = (new SymbolWorkflowCollectionJsonProjector())->project(
            $selection,
            $workflows,
            $snapshotId,
            $entryEvidence,
        );
        $lines = [
            '---',
            'schema_version: 2',
            'snapshot_id: '.$this->yaml($snapshotId),
            'target_id: '.$this->yaml($selection->id->value),
            'generated_at: '.$this->yaml($generatedAt->format(DATE_ATOM)),
            '---',
            '',
            '# Workflow collection '.$this->inline($selection->name),
            '',
            'Selected `'.$this->inline($selection->kind->value).'` container: `'.$this->inline($selection->id->value).'`.',
            '',
            '## Summary',
            '',
            '| Metric | Value |',
            '| --- | ---: |',
        ];

        foreach ($json['summary'] as $name => $value) {
            $lines[] = '| '.$this->inline(str_replace('_', ' ', $name)).' | '.$value.' |';
        }

        $lines = [...$lines, '', '## Entry workflows', '', '| Entry | Steps | Branches | Effects | Gaps |', '| --- | ---: | ---: | ---: | ---: |'];

        foreach ($workflows as $workflow) {
            if (! $workflow instanceof WorkflowDefinition) {
                throw new InvalidArgumentException('Symbol workflow collections require workflow definitions.');
            }

            $summary = $workflow->summary();
            $entry = $this->entryLabel($workflow);
            $lines[] = '| `'.$this->inline($entry).'` | '.$summary->stepCount.' | '.$summary->branchCount.' | '.$summary->effectCount.' | '.$summary->gapCount.' |';
        }

        $lines = [...$lines, '', '## Workflow diagrams', ''];
        $mermaid = new WorkflowMermaidProjector();

        foreach ($workflows as $workflow) {
            $lines[] = '### `'.$this->inline($this->entryLabel($workflow)).'`';
            $lines[] = '';
            $lines[] = '```mermaid';
            $lines[] = rtrim($mermaid->project($workflow));
            $lines[] = '```';
            $lines[] = '';
        }

        return rtrim(implode("\n", $lines))."\n";
    }

    private function entryLabel(WorkflowDefinition $workflow): string
    {
        foreach ($workflow->steps as $step) {
            if ($step->id === $workflow->entryStepId) {
                return $step->label;
            }
        }

        return $workflow->entrypoint->value;
    }

    private function yaml(string $value): string
    {
        return json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    private function inline(string $value): string
    {
        return str_replace(["\r", "\n", '|'], ['', ' ', '\\|'], $value);
    }
}
