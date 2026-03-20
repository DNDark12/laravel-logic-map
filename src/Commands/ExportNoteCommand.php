<?php

namespace dndark\LogicMap\Commands;

use dndark\LogicMap\Services\ImpactReadService;
use dndark\LogicMap\Services\QueryLogicMapService;
use dndark\LogicMap\Services\SnapshotResolver;
use dndark\LogicMap\Services\TraceReadService;
use dndark\LogicMap\Support\ArtifactSlugger;
use dndark\LogicMap\Support\ArtifactWriter;
use dndark\LogicMap\Support\Markdown\ImpactMarkdownBuilder;
use dndark\LogicMap\Support\Markdown\TraceMarkdownBuilder;
use Illuminate\Console\Command;

class ExportNoteCommand extends Command
{
    protected $signature = 'logic-map:export-note
                            {--node= : Node ID to export (e.g. method:App\Services\OrderService@create)}
                            {--type=impact : Type of note to export: impact or trace}
                            {--snapshot= : Use a specific snapshot fingerprint instead of latest}
                            {--output= : Custom output directory (default: docs/logic-map/notes)}
                            {--no-json : Omit the raw JSON appendix from generated markdown}';

    protected $description = 'Export a per-node impact or trace note to docs/logic-map/notes/';

    public function handle(
        SnapshotResolver    $resolver,
        QueryLogicMapService $queryService
    ): int {
        $nodeId     = $this->option('node');
        $type       = strtolower($this->option('type') ?? 'impact');
        $snapshotId = $this->option('snapshot');
        $includeJson = !$this->option('no-json');

        if (empty($nodeId)) {
            $this->error('--node is required. Example: --node="method:App\Services\OrderService@create"');
            return self::FAILURE;
        }

        if (!in_array($type, ['impact', 'trace'])) {
            $this->error('--type must be "impact" or "trace".');
            return self::FAILURE;
        }

        $resolution = $resolver->resolve($snapshotId, true);
        if (!$resolution->hasGraph()) {
            $this->error('No graph found. Run logic-map:build first.');
            return self::FAILURE;
        }

        $fingerprint = $resolution->resolvedFingerprint;
        $basePath    = $this->option('output')
            ?: base_path('docs/logic-map/notes');
        $writer      = new ArtifactWriter($basePath);
        $slug        = ArtifactSlugger::slugify($nodeId);
        $filename    = "{$type}--{$slug}.md";

        $this->info("Exporting {$type} note for [{$nodeId}]...");

        if ($type === 'impact') {
            $result = $queryService->impact($nodeId, $fingerprint);
            if (!$result->ok) {
                $this->error("Impact query failed: " . ($result->message ?? 'Unknown error'));
                return self::FAILURE;
            }
            $content = ImpactMarkdownBuilder::build($result->data, $fingerprint, $includeJson);
        } else {
            $result = $queryService->trace($nodeId, 'forward', 8, $fingerprint);
            if (!$result->ok) {
                $this->error("Trace query failed: " . ($result->message ?? 'Unknown error'));
                return self::FAILURE;
            }
            $content = TraceMarkdownBuilder::build($result->data, $fingerprint, $includeJson);
        }

        $writer->write($filename, $content);

        $this->info("✓ Exported to [{$basePath}/{$filename}]");
        return self::SUCCESS;
    }
}
