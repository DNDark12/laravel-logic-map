<?php

namespace dndark\LogicMap\Contracts;

use dndark\LogicMap\Domain\Graph;

interface GraphProjector
{
    /**
     * Project an overview of the graph.
     *
     * @param Graph $graph
     * @param array $filters
     * @return array
     */
    public function overview(Graph $graph, array $filters = []): array;

    /**
     * Project a subgraph starting from a specific node.
     *
     * @param Graph $graph
     * @param string $id
     * @param array $filters
     * @return array
     */
    public function subgraph(Graph $graph, string $id, array $filters = []): array;
}
