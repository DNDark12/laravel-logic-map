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
        $result = $this->queryService->getOverview($request->all());

        return $this->respond($result);
    }

    public function subgraph(Request $request, string $id): JsonResponse
    {
        $result = $this->queryService->getSubgraph($id, $request->all());

        return $this->respond($result);
    }

    public function search(Request $request): JsonResponse
    {
        $query = $request->input('q', '') ?? '';
        $result = $this->queryService->search($query, $request->all());

        return $this->respond($result);
    }

    public function meta(Request $request): JsonResponse
    {
        $result = $this->queryService->getMeta();

        return $this->respond($result);
    }

    public function violations(Request $request): JsonResponse
    {
        $result = $this->queryService->getViolations($request->all());

        return $this->respond($result);
    }

    public function health(Request $request): JsonResponse
    {
        $result = $this->queryService->getHealth();

        return $this->respond($result);
    }

    public function exportJson(Request $request): JsonResponse
    {
        $result = $this->queryService->exportJson();

        return $this->respond($result);
    }

    public function exportCsv(Request $request): Response
    {
        $result = $this->queryService->exportCsv();

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
}
