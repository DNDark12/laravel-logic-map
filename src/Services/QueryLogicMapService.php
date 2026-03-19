<?php

namespace dndark\LogicMap\Services;

use dndark\LogicMap\Domain\QueryResult;

class QueryLogicMapService
{
    public function __construct(
        protected GraphReadService $graphReads,
        protected AnalysisReadService $analysisReads,
        protected ExportReadService $exportReads,
    ) {
    }

    public function overview(array $filters = [], ?string $snapshot = null): QueryResult
    {
        return $this->graphReads->overview($filters, $snapshot);
    }

    public function subgraph(string $id, array $filters = [], ?string $snapshot = null): QueryResult
    {
        return $this->graphReads->subgraph($id, $filters, $snapshot);
    }

    public function search(string $query, array $filters = [], ?string $snapshot = null): QueryResult
    {
        return $this->graphReads->search($query, $filters, $snapshot);
    }

    public function meta(?string $snapshot = null): QueryResult
    {
        return $this->graphReads->meta($snapshot);
    }

    public function snapshots(): QueryResult
    {
        return $this->graphReads->snapshots();
    }

    public function diff(?string $from = null, ?string $to = null): QueryResult
    {
        return $this->graphReads->diff($from, $to);
    }

    public function violations(array $filters = [], ?string $snapshot = null): QueryResult
    {
        return $this->analysisReads->violations($filters, $snapshot);
    }

    public function health(?string $snapshot = null): QueryResult
    {
        return $this->analysisReads->health($snapshot);
    }

    public function hotspots(array $filters = [], ?string $snapshot = null): QueryResult
    {
        return $this->analysisReads->hotspots($filters, $snapshot);
    }

    public function exportGraph(?string $snapshot = null): QueryResult
    {
        return $this->exportReads->graph($snapshot);
    }

    public function exportAnalysis(?string $snapshot = null): QueryResult
    {
        return $this->exportReads->analysis($snapshot);
    }

    public function exportBundle(?string $snapshot = null): QueryResult
    {
        return $this->exportReads->bundle($snapshot);
    }

    public function exportCsv(?string $snapshot = null): QueryResult
    {
        return $this->exportReads->csv($snapshot);
    }
}
