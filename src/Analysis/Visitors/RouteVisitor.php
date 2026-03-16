<?php

namespace dndark\LogicMap\Analysis\Visitors;

use dndark\LogicMap\Domain\Edge;
use dndark\LogicMap\Domain\Enums\NodeKind;
use dndark\LogicMap\Domain\Graph;
use dndark\LogicMap\Domain\Node as DomainNode;
use PhpParser\Node;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Expr\StaticCall;
use PhpParser\NodeVisitorAbstract;
use dndark\LogicMap\Analysis\Support\IntentExtractor;

class RouteVisitor extends NodeVisitorAbstract
{
    public function __construct(protected Graph $graph) {}

    public function enterNode(Node $node)
    {
        // Handle Route::get(...) etc.
        if (($node instanceof StaticCall && $this->isRouteClass($node->class)) ||
            ($node instanceof MethodCall && $this->isRouteChain($node))) {

            $method = $node->name->toString();
            if (in_array($method, ['get', 'post', 'put', 'patch', 'delete', 'any', 'match'])) {
                $this->processRoute($node);
            }

            if ($method === 'group') {
                $this->processGroup($node);
            }
        }
    }

    protected function isRouteClass($class): bool
    {
        if (! $class instanceof Node\Name) {
            return false;
        }

        $name = $class->toString();
        return $name === 'Route' || $name === 'Illuminate\Support\Facades\Route';
    }

    protected function isRouteChain(Node $node): bool
    {
        if ($node instanceof StaticCall) {
            return $this->isRouteClass($node->class);
        }

        if ($node instanceof MethodCall) {
            return $this->isRouteChain($node->var);
        }

        return false;
    }

    protected function processGroup(Node $node)
    {
        $args = $node->args;
        $callback = null;

        // Route::group($attributes, $callback) or ->group($callback)
        if (count($args) === 2) {
            $callback = $args[1]->value;
        } elseif (count($args) === 1) {
            $callback = $args[0]->value;
        }

        if ($callback instanceof Node\Expr\Closure || $callback instanceof Node\Expr\ArrowFunction) {
            // We don't need to do anything special here as the traverser will automatically
            // enter the closure's statements if we are using a recursive traverser or if we
            // manually trigger it. Since AstParser uses NodeTraverser, it visits all nodes.
            // However, we might want to track group attributes (prefix, middleware) for scope.
        }
    }

    protected function processRoute(Node $node)
    {
        // Extract URI
        $uri = null;
        if (isset($node->args[0])) {
            $uri = $this->extractString($node->args[0]->value);
        }

        if ($uri) {
            $action = $node->args[1]->value ?? null;
            $verb = strtolower($node->name->toString());

            $controllerName = '';
            if ($action instanceof Node\Expr\Array_ && isset($action->items[0]) && $action->items[0]->value instanceof Node\Expr\ClassConstFetch) {
                $controllerName = $action->items[0]->value->class->toString();
            } elseif ($action instanceof Node\Scalar\String_ || $action instanceof Node\Expr\StaticCall) {
                $val = $this->extractString($action);
                if ($val && str_contains($val, '@')) {
                    [$controllerName,] = explode('@', $val);
                }
            }

            $intent = IntentExtractor::extractFromRoute($uri, $verb, $controllerName);

            $routeNode = new DomainNode(
                id: 'route:' . $uri,
                kind: NodeKind::ROUTE,
                name: $uri,
                scope: 'web', // Default, logic can be added to detect group/file context
                metadata: [
                    'action' => $intent['action'],
                    'domain' => $intent['domain'],
                    'result' => $intent['result'],
                    'shortLabel' => $intent['short'],
                    'trigger' => $intent['trigger'],
                ]
            );

            $this->graph->addNode($routeNode);

            $this->linkToController($routeNode, $action);
        }
    }

    protected function linkToController(DomainNode $routeNode, $action)
    {
        $controller = null;
        $method = null;

        if ($action instanceof Node\Expr\Array_) {
            // [Controller::class, 'index']
            if (isset($action->items[0]) && $action->items[0]->value instanceof Node\Expr\ClassConstFetch) {
                $controller = $action->items[0]->value->class->toString();
            }
            if (isset($action->items[1])) {
                $method = $this->extractString($action->items[1]->value);
            }
        } elseif ($action instanceof Node\Scalar\String_ || $action instanceof Node\Expr\StaticCall) {
            // Handle string 'Controller@index' or other patterns
            $val = $this->extractString($action);
            if ($val && str_contains($val, '@')) {
                [$controller, $method] = explode('@', $val);
            }
        }

        if ($controller) {
            $targetId = 'class:' . $controller;
            if ($method) {
                $targetId = 'method:' . $controller . '@' . $method;
            }

            $this->graph->addEdge(new Edge(
                source: $routeNode->id,
                target: $targetId,
                type: \dndark\LogicMap\Domain\Enums\EdgeType::ROUTE_TO_CONTROLLER,
                confidence: \dndark\LogicMap\Domain\Enums\Confidence::HIGH
            ));
        }
    }

    protected function extractString($node): ?string
    {
        if ($node instanceof Node\Scalar\String_) {
            return $node->value;
        }
        return null;
    }
}
