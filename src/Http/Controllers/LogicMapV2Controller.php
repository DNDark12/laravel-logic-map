<?php

namespace DNDark\LogicMap\Http\Controllers;

use DateTimeImmutable;
use DateTimeZone;
use DNDark\LogicMap\Contracts\SemanticGraphRepository;
use DNDark\LogicMap\Domain\Graph\NodeId;
use DNDark\LogicMap\Domain\Graph\NodeKind;
use DNDark\LogicMap\Http\Requests\ImpactRequest;
use DNDark\LogicMap\Projectors\WorkflowJsonProjector;
use DNDark\LogicMap\Projectors\ModuleWorkflowJsonProjector;
use DNDark\LogicMap\Projectors\SymbolWorkflowCollectionJsonProjector;
use DNDark\LogicMap\Projectors\WorkflowMarkdownProjector;
use DNDark\LogicMap\Projectors\WorkflowMermaidProjector;
use DNDark\LogicMap\Projectors\ImpactJsonProjector;
use DNDark\LogicMap\Projectors\ImpactMarkdownProjector;
use DNDark\LogicMap\Services\Impact\ImpactQueryService;
use DNDark\LogicMap\Services\Query\ApiResult;
use DNDark\LogicMap\Services\Query\LogicMapStatusService;
use DNDark\LogicMap\Services\Query\ModuleQueryService;
use DNDark\LogicMap\Services\Query\ResponseLimiter;
use DNDark\LogicMap\Services\Query\RuntimeEvidenceMerger;
use DNDark\LogicMap\Services\Query\SymbolContextService;
use DNDark\LogicMap\Services\Query\SymbolSearchService;
use DNDark\LogicMap\Services\Workflow\WorkflowQueryService;
use DNDark\LogicMap\Support\NodeIdCodec;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use InvalidArgumentException;
use Throwable;

final readonly class LogicMapV2Controller
{
    public function __construct(
        private SemanticGraphRepository $repository,
        private LogicMapStatusService $statusService,
        private SymbolSearchService $searchService,
        private SymbolContextService $contextService,
        private ModuleQueryService $moduleService,
        private ImpactQueryService $impactService,
        private NodeIdCodec $codec,
        private ResponseLimiter $responseLimiter,
        private WorkflowQueryService $workflowService,
        private RuntimeEvidenceMerger $runtimeEvidence,
    ) {
    }

    public function status(): JsonResponse
    {
        return $this->success($this->statusService->status());
    }

    public function search(Request $request): JsonResponse
    {
        $query = $request->query('q');

        if (! is_string($query) || trim($query) === '') {
            return ApiResult::failure(
                'A non-empty search query is required.',
                ['code' => 'validation_failed', 'fields' => ['q' => ['The q field is required.']]],
                422,
            )->toResponse();
        }

        $snapshot = $this->repository->active();

        if ($snapshot === null) {
            return $this->missingIndex();
        }

        $result = $this->searchService->search($snapshot, $query);

        return $this->success($result['data'], $result['meta']);
    }

    public function context(Request $request, string $id): JsonResponse
    {
        $decoded = $this->nodeId($id);

        if ($decoded instanceof JsonResponse) {
            return $decoded;
        }

        $snapshot = $this->repository->active();

        if ($snapshot === null) {
            return $this->missingIndex();
        }

        $result = $this->contextService->context(
            $snapshot,
            $decoded,
            $this->runtimeSessions($request->query('runtime_sessions')),
        );

        if ($result === null) {
            return ApiResult::failure(
                'The requested symbol was not found.',
                ['code' => 'symbol_not_found', 'id' => $decoded->value],
                404,
            )->toResponse();
        }

        return $this->success($result['data'], $result['meta']);
    }

    public function workflow(Request $request, string $id): JsonResponse
    {
        $entrypoint = $this->nodeId($id);

        if ($entrypoint instanceof JsonResponse) {
            return $entrypoint;
        }

        $snapshot = $this->repository->active();

        if ($snapshot === null) {
            return $this->missingIndex();
        }

        if (! $snapshot->graph->hasNode($entrypoint)) {
            return ApiResult::failure(
                'The requested workflow entrypoint was not found.',
                ['code' => 'workflow_not_found', 'id' => $entrypoint->value],
                404,
            )->toResponse();
        }

        $runtime = $this->runtimeEvidence->merge(
            $snapshot,
            $this->runtimeSessions($request->query('runtime_sessions')),
        );
        $selectedNode = $snapshot->graph->findNode($entrypoint);

        if ($selectedNode?->kind === NodeKind::Module) {
            if (strtolower((string) $request->query('format', 'json')) !== 'json') {
                return ApiResult::failure(
                    'Module workflows currently support JSON projection only.',
                    ['code' => 'validation_failed', 'fields' => ['format' => ['Use json for module workflows.']]],
                    422,
                )->toResponse();
            }

            $moduleWorkflow = $this->workflowService->buildModule($snapshot, $entrypoint);
            $entryEvidence = [];
            $workflowNodeIds = [];

            foreach ($moduleWorkflow->entryWorkflows as $entryWorkflow) {
                $entryEvidence[$entryWorkflow->id->value] = $this->workflowService->evidence($snapshot, $entryWorkflow);

                foreach ($entryWorkflow->steps as $step) {
                    if ($step->nodeId !== null) {
                        $workflowNodeIds[] = $step->nodeId->value;
                    }
                }
            }

            $data = (new ModuleWorkflowJsonProjector())->project($moduleWorkflow, $snapshot->id, $entryEvidence);
            $data['runtime'] = $this->runtimeProjection($runtime, $workflowNodeIds);
            $data['identity']['encoded_workflow_id'] = $this->codec->encode($data['identity']['workflow_id']);
            $data['module']['encoded_id'] = $this->codec->encode($entrypoint->value);

            foreach ($data['entry_workflows'] as &$entryWorkflow) {
                $entryWorkflow['identity']['encoded_workflow_id'] = $this->codec->encode($entryWorkflow['identity']['workflow_id']);
                $entryWorkflow['entrypoint']['encoded_id'] = $this->codec->encode($entryWorkflow['entrypoint']['node_id']);

                foreach ($entryWorkflow['steps'] as &$step) {
                    $step['encoded_id'] = $this->codec->encode($step['id']);

                    if (is_string($step['node_id'] ?? null)) {
                        $step['encoded_node_id'] = $this->codec->encode($step['node_id']);
                    }
                }
                unset($step);
            }
            unset($entryWorkflow);

            $truncated = false;

            foreach ($moduleWorkflow->entryWorkflows as $entryWorkflow) {
                if ((bool) ($entryWorkflow->truncation['truncated'] ?? false)) {
                    $truncated = true;
                    break;
                }
            }

            return $this->success($data, [
                'truncated' => $truncated,
            ]);
        }

        $collection = $this->workflowService->buildSymbolCollection($snapshot, $entrypoint, $runtime['overlays']);

        if ($collection !== []) {
            if (strtolower((string) $request->query('format', 'json')) !== 'json') {
                return ApiResult::failure(
                    'Class and container workflows currently support JSON projection only.',
                    ['code' => 'validation_failed', 'fields' => ['format' => ['Use json for class and container workflows.']]],
                    422,
                )->toResponse();
            }

            $entryEvidence = [];
            $workflowNodeIds = [];

            foreach ($collection as $entryWorkflow) {
                $entryEvidence[$entryWorkflow->id->value] = $this->workflowService->evidence($snapshot, $entryWorkflow);

                foreach ($entryWorkflow->steps as $step) {
                    if ($step->nodeId !== null) {
                        $workflowNodeIds[] = $step->nodeId->value;
                    }
                }
            }

            $data = (new SymbolWorkflowCollectionJsonProjector())->project(
                $selectedNode,
                $collection,
                $snapshot->id,
                $entryEvidence,
            );
            $data['runtime'] = $this->runtimeProjection($runtime, $workflowNodeIds);
            $data['identity']['encoded_workflow_id'] = $this->codec->encode($data['identity']['workflow_id']);
            $data['selection']['encoded_id'] = $this->codec->encode($entrypoint->value);

            foreach ($data['entry_workflows'] as &$entryWorkflow) {
                $entryWorkflow['identity']['encoded_workflow_id'] = $this->codec->encode($entryWorkflow['identity']['workflow_id']);
                $entryWorkflow['entrypoint']['encoded_id'] = $this->codec->encode($entryWorkflow['entrypoint']['node_id']);

                foreach ($entryWorkflow['steps'] as &$step) {
                    $step['encoded_id'] = $this->codec->encode($step['id']);

                    if (is_string($step['node_id'] ?? null)) {
                        $step['encoded_node_id'] = $this->codec->encode($step['node_id']);
                    }
                }
                unset($step);
            }
            unset($entryWorkflow);

            $truncated = false;

            foreach ($collection as $entryWorkflow) {
                if ((bool) ($entryWorkflow->truncation['truncated'] ?? false)) {
                    $truncated = true;
                    break;
                }
            }

            return $this->success($data, ['truncated' => $truncated]);
        }

        $workflow = $this->workflowService->build($snapshot, $entrypoint, $runtime['overlays']);
        $workflowNodeIds = array_values(array_filter(array_map(
            static fn ($step): ?string => $step->nodeId?->value,
            $workflow->steps,
        )));
        $runtimeProjection = $this->runtimeProjection($runtime, $workflowNodeIds);
        $runtimeEvidenceIds = [];

        foreach ($runtimeProjection['relations'] as $relation) {
            foreach ($relation['evidence_ids'] as $evidenceId) {
                $runtimeEvidenceIds[$evidenceId] = true;
            }
        }

        $evidence = [
            ...$this->workflowService->evidence($snapshot, $workflow),
            ...array_values(array_filter(
                $runtime['evidence'],
                static fn ($record): bool => isset($runtimeEvidenceIds[$record->id()]),
            )),
        ];
        $format = strtolower((string) $request->query('format', 'json'));

        if (! in_array($format, ['json', 'markdown', 'mermaid'], true)) {
            return ApiResult::failure(
                'The workflow export format is invalid.',
                ['code' => 'validation_failed', 'fields' => ['format' => ['Use json, markdown, or mermaid.']]],
                422,
            )->toResponse();
        }

        if ($format !== 'json') {
            $content = match ($format) {
                'markdown' => (new WorkflowMarkdownProjector())->project(
                    $workflow,
                    $snapshot->id,
                    new DateTimeImmutable('now', new DateTimeZone('UTC')),
                    $evidence,
                ),
                'mermaid' => (new WorkflowMermaidProjector())->project($workflow),
            };

            return $this->success(['format' => $format, 'content' => $content], [
                'truncated' => (bool) ($workflow->truncation['truncated'] ?? false),
            ]);
        }

        $data = (new WorkflowJsonProjector())->project($workflow, $snapshot->id, $evidence);
        $data['runtime'] = $runtimeProjection;
        $data['identity']['encoded_workflow_id'] = $this->codec->encode($workflow->id->value);
        $data['entrypoint']['encoded_id'] = $this->codec->encode($entrypoint->value);

        foreach ($data['steps'] as &$step) {
            $step['encoded_id'] = $this->codec->encode($step['id']);

            if (is_string($step['node_id'] ?? null)) {
                $step['encoded_node_id'] = $this->codec->encode($step['node_id']);
            }
        }
        unset($step);

        return $this->success($data, [
            'truncated' => (bool) ($workflow->truncation['truncated'] ?? false),
        ]);
    }

    public function impact(ImpactRequest $request): JsonResponse
    {
        $snapshot = $this->repository->active();

        if ($snapshot === null) {
            return $this->missingIndex();
        }

        try {
            $runtime = $this->runtimeEvidence->merge(
                $snapshot,
                $request->validated('runtime_sessions'),
            );
            $report = $this->impactService->analyze(
                $snapshot,
                $request->validated('symbol'),
                $request->validated('base'),
                $request->validated('head'),
                $runtime['overlays'],
            );
        } catch (InvalidArgumentException $exception) {
            return ApiResult::failure(
                'The impact request could not be analyzed.',
                ['code' => 'impact_invalid', 'detail' => $exception->getMessage()],
                422,
            )->toResponse();
        } catch (Throwable $throwable) {
            return ApiResult::failure(
                'The supplied Git refs could not be resolved.',
                ['code' => 'invalid_git_ref', 'detail' => $throwable->getMessage()],
                422,
            )->toResponse();
        }

        $data = (new ImpactJsonProjector())->project($report);
        $selectedSymbol = $request->validated('symbol');

        if (is_string($selectedSymbol)) {
            try {
                $selectedNode = $snapshot->graph->findNode(NodeId::fromString($selectedSymbol));

                if ($selectedNode?->kind === NodeKind::Module) {
                    $data['selection'] = [
                        'type' => 'module',
                        'node_id' => $selectedNode->id->value,
                        'encoded_id' => $this->codec->encode($selectedNode->id->value),
                        'name' => $selectedNode->name,
                    ];
                } elseif ($selectedNode !== null && $snapshot->graph->outgoing($selectedNode->id, [\DNDark\LogicMap\Domain\Graph\EdgeType::Defines]) !== []) {
                    $data['selection'] = [
                        'type' => 'container',
                        'node_id' => $selectedNode->id->value,
                        'encoded_id' => $this->codec->encode($selectedNode->id->value),
                        'name' => $selectedNode->name,
                        'kind' => $selectedNode->kind->value,
                    ];
                }
            } catch (InvalidArgumentException) {
            }
        }
        $impactNodeIds = [];

        foreach ($report->changedSymbols as $change) {
            foreach ([$change->oldNodeId, $change->newNodeId] as $nodeId) {
                if ($nodeId !== null) {
                    $impactNodeIds[] = $nodeId->value;
                }
            }
        }

        foreach ($report->affectedSymbols as $affected) {
            $impactNodeIds[] = $affected->nodeId->value;
        }

        $data['runtime'] = $this->runtimeProjection($runtime, $impactNodeIds);
        $format = strtolower((string) ($request->validated('format') ?? 'json'));

        if ($format === 'markdown') {
            $symbol = $request->validated('symbol');
            $target = is_string($symbol) && trim($symbol) !== ''
                ? trim($symbol)
                : (($request->validated('base') ?? 'HEAD~1').'..'.($request->validated('head') ?? 'HEAD'));
            $content = (new ImpactMarkdownProjector())->project(
                $report,
                $snapshot->id,
                $target,
                new DateTimeImmutable('now', new DateTimeZone('UTC')),
            );

            return $this->success(['format' => $format, 'content' => $content], $report->truncation);
        }

        $this->encodeImpactIds($data);

        return $this->success($data, $report->truncation);
    }

    public function modules(): JsonResponse
    {
        $snapshot = $this->repository->active();

        if ($snapshot === null) {
            return $this->missingIndex();
        }

        $result = $this->moduleService->all($snapshot);

        return $this->success($result['data'], $result['meta']);
    }

    public function module(string $id): JsonResponse
    {
        $moduleId = $this->nodeId($id);

        if ($moduleId instanceof JsonResponse) {
            return $moduleId;
        }

        $snapshot = $this->repository->active();

        if ($snapshot === null) {
            return $this->missingIndex();
        }

        $result = $this->moduleService->find($snapshot, $moduleId);

        if ($result === null) {
            return ApiResult::failure(
                'The requested module was not found.',
                ['code' => 'module_not_found', 'id' => $moduleId->value],
                404,
            )->toResponse();
        }

        return $this->success($result['data'], $result['meta']);
    }

    private function nodeId(string $encoded): NodeId|JsonResponse
    {
        try {
            return NodeId::fromString($this->codec->decode($encoded));
        } catch (InvalidArgumentException $exception) {
            return ApiResult::failure(
                'The encoded ID is invalid.',
                ['code' => 'invalid_encoded_id', 'detail' => $exception->getMessage()],
                422,
            )->toResponse();
        }
    }

    private function encodeImpactIds(array &$data): void
    {
        foreach ($data['changed_symbols'] ?? [] as $index => $change) {
            if (is_string($change['old_node_id'] ?? null)) {
                $change['encoded_old_node_id'] = $this->codec->encode($change['old_node_id']);
            }

            if (is_string($change['new_node_id'] ?? null)) {
                $change['encoded_new_node_id'] = $this->codec->encode($change['new_node_id']);
            }

            $data['changed_symbols'][$index] = $change;
        }

        foreach (['affected_symbols', 'workflows', 'modules', 'shared_resources', 'external_contracts', 'uncertainty'] as $section) {
            foreach ($data[$section] ?? [] as $index => $row) {
                if (is_string($row['node_id'] ?? null)) {
                    $row['encoded_id'] = $this->codec->encode($row['node_id']);
                }

                if (is_string($row['resource_node_id'] ?? null)) {
                    $row['encoded_resource_node_id'] = $this->codec->encode($row['resource_node_id']);
                }

                if (is_array($row['reason']['node_chain'] ?? null)) {
                    $row['reason']['encoded_node_chain'] = array_map(
                        fn (string $id): string => $this->codec->encode($id),
                        $row['reason']['node_chain'],
                    );
                }

                $reasons = $row['reasons'] ?? [];

                foreach ($reasons as &$reason) {
                    if (is_array($reason['node_chain'] ?? null)) {
                        $reason['encoded_node_chain'] = array_map(
                            fn (string $id): string => $this->codec->encode($id),
                            $reason['node_chain'],
                        );
                    }
                }
                unset($reason);
                $row['reasons'] = $reasons;
                $data[$section][$index] = $row;
            }
        }

        foreach ($data['tests'] ?? [] as $index => $test) {
            if (is_string($test['test_node_id'] ?? null)) {
                $test['encoded_test_node_id'] = $this->codec->encode($test['test_node_id']);
            }

            $data['tests'][$index] = $test;
        }
    }

    private function success(mixed $data, array $meta = [], ?string $message = null): JsonResponse
    {
        $bounded = $this->responseLimiter->limit($data, $meta);

        return ApiResult::success($bounded['data'], $bounded['meta'], $message)->toResponse();
    }

    private function runtimeSessions(mixed $value): ?array
    {
        if ($value === null || $value === '') {
            return null;
        }

        $sessions = is_array($value) ? $value : explode(',', (string) $value);
        $sessions = array_values(array_unique(array_filter(array_map(
            static fn ($session): string => trim((string) $session),
            $sessions,
        ), static fn (string $session): bool => $session !== '')));
        sort($sessions, SORT_STRING);

        return array_slice($sessions, 0, 100);
    }

    private function runtimeProjection(array $runtime, array $nodeIds): array
    {
        $nodes = array_fill_keys(array_values(array_unique($nodeIds)), true);

        return [
            'coverage' => $runtime['coverage'],
            'available_session_count' => $runtime['available_session_count'],
            'selected_session_ids' => $runtime['selected_session_ids'],
            'relations' => array_values(array_filter(
                $runtime['relations'],
                static fn (array $relation): bool => isset($nodes[$relation['source_node_id']])
                    || isset($nodes[$relation['target_node_id']]),
            )),
        ];
    }

    private function missingIndex(): JsonResponse
    {
        return ApiResult::failure(
            'No active Laravel Logic Map index exists.',
            ['code' => 'index_missing'],
            409,
        )->toResponse();
    }
}
