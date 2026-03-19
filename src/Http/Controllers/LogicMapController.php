<?php

namespace dndark\LogicMap\Http\Controllers;

use dndark\LogicMap\Services\QueryLogicMapService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Symfony\Component\HttpFoundation\Response;

class LogicMapController extends Controller
{
    public function __construct(
        protected QueryLogicMapService $queryService
    ) {
    }

    public function index()
    {
        return view('logic-map::graph');
    }

    public function overview(Request $request): JsonResponse
    {
        $snapshot = $this->readSnapshot($request);
        $result = $this->queryService->getOverview($request->except('snapshot'), $snapshot);

        return $this->respond($result);
    }

    public function subgraph(Request $request, string $id): JsonResponse
    {
        $snapshot = $this->readSnapshot($request);
        $result = $this->queryService->getSubgraph($id, $request->except('snapshot'), $snapshot);

        return $this->respond($result);
    }

    public function search(Request $request): JsonResponse
    {
        $query = $request->input('q', '') ?? '';
        $snapshot = $this->readSnapshot($request);
        $result = $this->queryService->search($query, $request->except('snapshot'), $snapshot);

        return $this->respond($result);
    }

    public function meta(Request $request): JsonResponse
    {
        $snapshot = $this->readSnapshot($request);
        $result = $this->queryService->getMeta($snapshot);

        return $this->respond($result);
    }

    public function diff(Request $request): JsonResponse
    {
        $result = $this->queryService->getDiff(
            $request->query('from'),
            $request->query('to')
        );

        return $this->respond($result);
    }

    public function violations(Request $request): JsonResponse
    {
        $snapshot = $this->readSnapshot($request);
        $result = $this->queryService->getViolations($request->except('snapshot'), $snapshot);

        return $this->respond($result);
    }

    public function health(Request $request): JsonResponse
    {
        $snapshot = $this->readSnapshot($request);
        $result = $this->queryService->getHealth($snapshot);

        return $this->respond($result);
    }

    public function exportJson(Request $request): JsonResponse
    {
        $snapshot = $this->readSnapshot($request);
        $result = $this->queryService->exportJson($snapshot);

        return $this->respond($result);
    }

    public function exportCsv(Request $request): Response
    {
        $snapshot = $this->readSnapshot($request);
        $result = $this->queryService->exportCsv($snapshot);

        if ($result['ok'] === false) {
            return response()->json([
                'ok' => false,
                'data' => null,
                'message' => $result['message'] ?? null,
                'errors' => null,
            ], $result['code'] ?? 400);
        }

        return response($result['data'], 200, [
            'Content-Type' => 'text/csv',
            'Content-Disposition' => 'attachment; filename="' . ($result['filename'] ?? 'logic-map-export.csv') . '"',
        ]);
    }

    /**
     * Convert service response to JSON with proper HTTP status.
     */
    protected function respond(array $result): JsonResponse
    {
        $statusCode = 200;

        if ($result['ok'] === false) {
            $statusCode = $result['code'] ?? 400;
        }

        return response()->json([
            'ok' => $result['ok'],
            'data' => $result['data'],
            'message' => $result['message'] ?? null,
            'errors' => null,
        ], $statusCode);
    }

    public function snapshots(Request $request): JsonResponse
    {
        $result = $this->queryService->getSnapshots();

        return $this->respond($result);
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
}
