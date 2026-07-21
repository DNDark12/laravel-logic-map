<?php

namespace DNDark\LogicMap\Projectors;

use DateTimeInterface;
use DNDark\LogicMap\Domain\Graph\EvidenceRecord;
use DNDark\LogicMap\Domain\Workflow\WorkflowDefinition;

final class WorkflowMarkdownProjector
{
    public function project(
        WorkflowDefinition $workflow,
        string $snapshotId,
        DateTimeInterface $generatedAt,
        array $evidence = [],
    ): string {
        $json = (new WorkflowJsonProjector())->project($workflow, $snapshotId, $evidence);
        $lines = [
            '---',
            'schema_version: 2',
            'snapshot_id: '.$this->yaml($snapshotId),
            'target_id: '.$this->yaml($workflow->entrypoint->value),
            'generated_at: '.$this->yaml($generatedAt->format(DATE_ATOM)),
            '---',
            '',
            '# Workflow '.$this->inline($workflow->entrypoint->value),
            '',
            '## Summary',
            '',
            '| Metric | Value |',
            '| --- | ---: |',
        ];

        foreach ($json['summary'] as $name => $value) {
            $lines[] = '| '.$this->inline(str_replace('_', ' ', $name)).' | '.$value.' |';
        }

        $lines = [...$lines, '', '## Steps', '', '| Step | Kind | Module | Node |', '| --- | --- | --- | --- |'];

        foreach ($json['steps'] as $step) {
            $lines[] = '| '.$this->inline($step['label']).' | '.$step['kind'].' | '.($step['module'] ?? '—').' | '.$this->inline($step['node_id'] ?? '—').' |';
        }

        $lines = [...$lines, '', '## Transitions', ''];

        foreach ($json['transitions'] as $transition) {
            $label = $transition['condition'] ?? $transition['branch'] ?? $transition['boundary'];
            $lines[] = '- `'.$transition['from'].'` → `'.$transition['to'].'` — '.$this->inline((string) $label);
        }

        $lines = [...$lines, '', '## Transactions', ''];

        foreach ($json['transactions'] as $transaction) {
            $lines[] = '- `'.$transaction['id'].'`: '.implode(', ', array_map(static fn (string $id): string => '`'.$id.'`', $transaction['step_ids']));
        }

        if ($json['transactions'] === []) {
            $lines[] = '- None';
        }

        $lines = [...$lines, '', '## Gaps', ''];

        foreach ($json['gaps'] as $gap) {
            $lines[] = '- `'.$gap['step_id'].'`: '.$this->inline($gap['reason']);
        }

        if ($json['gaps'] === []) {
            $lines[] = '- None';
        }

        $lines = [...$lines, '', '## Evidence', '', '| ID | Origin | Detector | Source |', '| --- | --- | --- | --- |'];

        foreach ($evidence as $record) {
            if (! $record instanceof EvidenceRecord) {
                continue;
            }

            $location = $record->location;
            $source = $location === null
                ? '—'
                : '['.$this->inline($location->file.':'.$location->startLine).']('.$location->file.'#L'.$location->startLine.')';
            $lines[] = '| `'.$record->id().'` | '.$record->origin->value.' | '.$this->inline($record->detector).' | '.$source.' |';
        }

        return implode("\n", $lines)."\n";
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
