<?php

namespace dndark\LogicMap\Analysis\Runtime;

use dndark\LogicMap\Domain\Enums\NodeKind;
use dndark\LogicMap\Domain\Graph;
use dndark\LogicMap\Domain\Node as DomainNode;
use Illuminate\Support\Facades\Route;

class RouteMetadataCollector
{
    /**
     * Enrich the graph with runtime route metadata.
     *
     * @param Graph $graph
     * @return void
     */
    public function collect(Graph $graph): void
    {
        $routes = Route::getRoutes();

        foreach ($routes as $route) {
            $uri = $route->uri();
            $id = 'route:' . $uri;

            $node = $graph->getNodes()[$id] ?? null;

            if ($node) {
                $node->metadata = array_merge($node->metadata, [
                    'methods' => $route->methods(),
                    'name' => $route->getName(),
                    'middleware' => $route->middleware(),
                    'action' => $route->getActionName(),
                ]);
            } else {
                // If not found via AST (e.g. dynamic), add it now
                $graph->addNode(new DomainNode(
                    id: $id,
                    kind: NodeKind::ROUTE,
                    name: $uri,
                    metadata: [
                        'methods' => $route->methods(),
                        'name' => $route->getName(),
                        'middleware' => $route->middleware(),
                        'action' => $route->getActionName(),
                    ]
                ));
            }
        }
    }
}
