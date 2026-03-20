<?php

namespace dndark\LogicMap\Http\Controllers;

use dndark\LogicMap\Http\Presenters\ImpactReportPresenter;
use dndark\LogicMap\Http\Presenters\TraceReportPresenter;
use dndark\LogicMap\Services\QueryLogicMapService;
use dndark\LogicMap\Support\ArtifactSlugger;
use dndark\LogicMap\Support\ArtifactWriter;
use dndark\LogicMap\Support\Markdown\ImpactMarkdownBuilder;
use dndark\LogicMap\Support\Markdown\TraceMarkdownBuilder;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Response;

class ReportController extends Controller
{
    public function __construct(
        protected QueryLogicMapService $queryService
    ) {
    }

    public function impactView(Request $request, string $id)
    {
        $snapshot  = $this->readSnapshot($request);
        $direction = (string) ($request->query('direction') ?? 'both');
        $maxDepth  = (int) ($request->query('max_depth') ?? 4);

        $result = $this->queryService->impact($id, $direction, $maxDepth, $snapshot);
        if (!$result->ok) {
            abort(404, 'Impact analysis not found or snapshot missing.');
        }

        $fingerprint = $result->data['_resolution']['fingerprint'] ?? 'unknown';
        $presenter = new ImpactReportPresenter($result->data, $fingerprint);

        return view('logic-map::impact', [
            'data' => $presenter->toArray(),
            'logicMapCss' => $this->inlineCss(),
        ]);
    }

    public function traceView(Request $request, string $id)
    {
        $snapshot  = $this->readSnapshot($request);
        $direction = (string) ($request->query('direction') ?? 'forward');
        $maxDepth  = (int) ($request->query('max_depth') ?? 4);

        $result = $this->queryService->trace($id, $direction, $maxDepth, $snapshot);
        if (!$result->ok) {
            abort(404, 'Trace analysis not found or snapshot missing.');
        }

        $fingerprint = $result->data['_resolution']['fingerprint'] ?? 'unknown';
        $presenter = new TraceReportPresenter($result->data, $fingerprint);

        return view('logic-map::trace', [
            'data' => $presenter->toArray(),
            'logicMapCss' => $this->inlineCss(),
        ]);
    }

    public function impactDownload(Request $request, string $id)
    {
        $snapshot  = $this->readSnapshot($request);
        $direction = (string) ($request->query('direction') ?? 'both');
        $maxDepth  = (int) ($request->query('max_depth') ?? 4);
        $includeJson = filter_var($request->query('include_json', true), FILTER_VALIDATE_BOOLEAN);

        $result = $this->queryService->impact($id, $direction, $maxDepth, $snapshot);
        if (!$result->ok) {
            abort(404, 'Impact analysis not found.');
        }

        $fingerprint = $result->data['_resolution']['fingerprint'] ?? 'unknown';
        $content = ImpactMarkdownBuilder::build($result->data, $fingerprint, $includeJson);
        $slug = ArtifactSlugger::slugify($id);
        
        $filename = "impact--{$slug}--{$fingerprint}.md";

        return Response::make($content, 200, [
            'Content-Type' => 'text/markdown',
            'Content-Disposition' => 'attachment; filename="' . $filename . '"',
        ]);
    }

    public function traceDownload(Request $request, string $id)
    {
        $snapshot  = $this->readSnapshot($request);
        $direction = (string) ($request->query('direction') ?? 'forward');
        $maxDepth  = (int) ($request->query('max_depth') ?? 4);
        $includeJson = filter_var($request->query('include_json', true), FILTER_VALIDATE_BOOLEAN);

        $result = $this->queryService->trace($id, $direction, $maxDepth, $snapshot);
        if (!$result->ok) {
            abort(404, 'Trace analysis not found.');
        }

        $fingerprint = $result->data['_resolution']['fingerprint'] ?? 'unknown';
        $content = TraceMarkdownBuilder::build($result->data, $fingerprint, $includeJson);
        $slug = ArtifactSlugger::slugify($id);
        
        $filename = "trace--{$slug}--{$fingerprint}.md";

        return Response::make($content, 200, [
            'Content-Type' => 'text/markdown',
            'Content-Disposition' => 'attachment; filename="' . $filename . '"',
        ]);
    }

    public function impactDownloadJson(Request $request, string $id)
    {
        $snapshot  = $this->readSnapshot($request);
        $direction = (string) ($request->query('direction') ?? 'both');
        $maxDepth  = (int) ($request->query('max_depth') ?? 4);

        $result = $this->queryService->impact($id, $direction, $maxDepth, $snapshot);
        if (!$result->ok) {
            abort(404, 'Impact analysis not found.');
        }

        $fingerprint = $result->data['_resolution']['fingerprint'] ?? 'unknown';
        $slug = ArtifactSlugger::slugify($id);
        $filename = "impact--{$slug}--{$fingerprint}.json";

        return Response::make(json_encode($result->data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), 200, [
            'Content-Type' => 'application/json',
            'Content-Disposition' => 'attachment; filename="' . $filename . '"',
        ]);
    }

    public function traceDownloadJson(Request $request, string $id)
    {
        $snapshot  = $this->readSnapshot($request);
        $direction = (string) ($request->query('direction') ?? 'forward');
        $maxDepth  = (int) ($request->query('max_depth') ?? 4);

        $result = $this->queryService->trace($id, $direction, $maxDepth, $snapshot);
        if (!$result->ok) {
            abort(404, 'Trace analysis not found.');
        }

        $fingerprint = $result->data['_resolution']['fingerprint'] ?? 'unknown';
        $slug = ArtifactSlugger::slugify($id);
        $filename = "trace--{$slug}--{$fingerprint}.json";

        return Response::make(json_encode($result->data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), 200, [
            'Content-Type' => 'application/json',
            'Content-Disposition' => 'attachment; filename="' . $filename . '"',
        ]);
    }

    protected function readSnapshot(Request $request): ?string
    {
        $snapshot = $request->query('snapshot');
        if (!is_string($snapshot)) {
            return null;
        }

        $snapshot = trim($snapshot);
        return $snapshot !== '' ? $snapshot : null;
    }

    protected function inlineCss(): string
    {
        $path = __DIR__ . '/../../../resources/dist/logic-map.css';
        if (file_exists($path)) {
            return "<style>\n" . file_get_contents($path) . "\n</style>";
        }
        return '';
    }

    public function saveImpactMarkdown(Request $request, string $id)
    {
        if (!config('logic-map.change_intelligence.markdown.save_to_project_docs', false)) {
            return Response::json([
                'ok' => false,
                'message' => 'Browser save is disabled. Set LOGIC_MAP_SAVE_TO_DOCS=true to enable.',
            ], 403);
        }

        $snapshot    = $this->readSnapshot($request);
        $direction   = (string) ($request->query('direction') ?? 'both');
        $maxDepth    = (int) ($request->query('max_depth') ?? 4);
        $includeJson = filter_var($request->query('include_json', true), FILTER_VALIDATE_BOOLEAN);

        $result = $this->queryService->impact($id, $direction, $maxDepth, $snapshot);
        if (!$result->ok) {
            return Response::json(['ok' => false, 'message' => 'Impact analysis not found.'], 404);
        }

        $fingerprint = $result->data['_resolution']['fingerprint'] ?? 'unknown';
        $content     = ImpactMarkdownBuilder::build($result->data, $fingerprint, $includeJson);
        $slug        = ArtifactSlugger::slugify($id);
        $filename    = "impact--{$slug}--{$fingerprint}.md";

        $basePath = config('logic-map.change_intelligence.markdown.base_path', base_path('docs/logic-map'));
        $writer   = new ArtifactWriter($basePath);
        $writer->write("notes/{$filename}", $content);

        return Response::json([
            'ok'       => true,
            'message'  => "Saved to docs/logic-map/notes/{$filename}",
            'filename' => "notes/{$filename}",
        ]);
    }

    public function saveTraceMarkdown(Request $request, string $id)
    {
        if (!config('logic-map.change_intelligence.markdown.save_to_project_docs', false)) {
            return Response::json([
                'ok' => false,
                'message' => 'Browser save is disabled. Set LOGIC_MAP_SAVE_TO_DOCS=true to enable.',
            ], 403);
        }

        $snapshot    = $this->readSnapshot($request);
        $direction   = (string) ($request->query('direction') ?? 'forward');
        $maxDepth    = (int) ($request->query('max_depth') ?? 4);
        $includeJson = filter_var($request->query('include_json', true), FILTER_VALIDATE_BOOLEAN);

        $result = $this->queryService->trace($id, $direction, $maxDepth, $snapshot);
        if (!$result->ok) {
            return Response::json(['ok' => false, 'message' => 'Trace analysis not found.'], 404);
        }

        $fingerprint = $result->data['_resolution']['fingerprint'] ?? 'unknown';
        $content     = TraceMarkdownBuilder::build($result->data, $fingerprint, $includeJson);
        $slug        = ArtifactSlugger::slugify($id);
        $filename    = "trace--{$slug}--{$fingerprint}.md";

        $basePath = config('logic-map.change_intelligence.markdown.base_path', base_path('docs/logic-map'));
        $writer   = new ArtifactWriter($basePath);
        $writer->write("notes/{$filename}", $content);

        return Response::json([
            'ok'       => true,
            'message'  => "Saved to docs/logic-map/notes/{$filename}",
            'filename' => "notes/{$filename}",
        ]);
    }
}
