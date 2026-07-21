<?php

namespace DNDark\LogicMap\Projectors;

use DateTimeInterface;
use DNDark\LogicMap\Domain\Impact\ImpactReport;

final class ImpactMarkdownProjector
{
    public function project(
        ImpactReport $report,
        string $snapshotId,
        string $targetId,
        DateTimeInterface $generatedAt,
    ): string {
        $json = (new ImpactJsonProjector())->project($report);
        $lines = [
            '---',
            'schema_version: 2',
            'snapshot_id: '.$this->yaml($snapshotId),
            'target_id: '.$this->yaml($targetId),
            'generated_at: '.$this->yaml($generatedAt->format(DATE_ATOM)),
            '---',
            '',
            '# Change impact '.$this->inline($targetId),
            '',
            '## Summary',
            '',
            '| Metric | Value |',
            '| --- | ---: |',
        ];

        foreach ($json['summary'] as $name => $value) {
            $lines[] = '| '.$this->inline(str_replace('_', ' ', $name)).' | '.$value.' |';
        }

        $lines = [...$lines, '', '## Changed symbols', ''];

        foreach ($json['changed_symbols'] as $change) {
            $id = $change['new_node_id'] ?? $change['old_node_id'];
            $lines[] = '- **'.$change['change_type'].'** `'.$this->inline((string) $id).'`';
        }

        $this->reasonSection($lines, 'Affected symbols', $json['affected_symbols'], false);
        $this->reasonSection($lines, 'Workflows', $json['workflows']);
        $this->reasonSection($lines, 'Modules', $json['modules']);
        $this->reasonSection($lines, 'Shared resources', $json['shared_resources']);
        $this->reasonSection($lines, 'External contracts', $json['external_contracts']);

        $lines = [...$lines, '', '## Selected tests', ''];

        foreach ($json['tests'] as $test) {
            $lines[] = '- `'.$this->inline($test['test_node_id']).'` (rank '.$test['rank'].'): '.$this->inline($test['reason']);
        }

        if ($json['tests'] === []) {
            $lines[] = '- None';
        }

        $this->reasonSection($lines, 'Uncertainty', $json['uncertainty']);
        $lines = [...$lines, '', '## Evidence', '', '| ID | Origin | Detector | Source |', '| --- | --- | --- | --- |'];

        foreach ($report->evidence as $record) {
            $location = $record->location;
            $source = $location === null
                ? '—'
                : '['.$this->inline($location->file.':'.$location->startLine).']('.$location->file.'#L'.$location->startLine.')';
            $lines[] = '| `'.$record->id().'` | '.$record->origin->value.' | '.$this->inline($record->detector).' | '.$source.' |';
        }

        return implode("\n", $lines)."\n";
    }

    private function reasonSection(array &$lines, string $title, array $rows, bool $categoryRows = true): void
    {
        $lines = [...$lines, '', '## '.$title, ''];

        foreach ($rows as $row) {
            if ($categoryRows) {
                $lines[] = '- `'.$this->inline($row['node_id']).'`: '.$this->inline($row['reason']['sentence']);

                continue;
            }

            $reasons = array_map(static fn (array $reason): string => $reason['sentence'], $row['reasons']);
            $lines[] = '- `'.$this->inline($row['node_id']).'`: '.$this->inline(implode('; ', $reasons));
        }

        if ($rows === []) {
            $lines[] = '- None';
        }
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
