<?php

namespace DNDark\LogicMap\Analysis\Laravel;

use DNDark\LogicMap\Analysis\Laravel\Boot\BootFact;
use DNDark\LogicMap\Analysis\Php\SymbolDefinition;
use DNDark\LogicMap\Analysis\Php\SymbolTable;
use DNDark\LogicMap\Domain\Graph\Certainty;
use DNDark\LogicMap\Domain\Graph\EdgeType;
use DNDark\LogicMap\Domain\Graph\KnowledgeGraph;
use DNDark\LogicMap\Domain\Graph\NodeKind;

final class SymbolClassifier
{
    public function __construct(private readonly array $namespaceConventions) {}

    /** @return list<array{symbol: string, kind: string, certainty: string, reason: string}> */
    public function classify(
        array $files,
        SymbolTable $symbols,
        array $bootFacts,
        KnowledgeGraph $graph,
    ): array {
        $candidates = [];

        foreach ($symbols->all() as $symbol) {
            if ($symbol->structuralKind === NodeKind::Method) {
                continue;
            }

            $this->candidate(
                $candidates,
                $symbol,
                NodeKind::ClassSymbol,
                Certainty::Possible,
                'structural class-like fallback',
                0,
            );
            $this->addNamespaceConvention($candidates, $symbol);
            $this->addInheritanceRules($candidates, $symbol);
        }

        $this->addBootRules($candidates, $bootFacts, $symbols);
        $this->addGraphRules($candidates, $graph, $symbols);

        $assignments = [];
        ksort($candidates, SORT_STRING);

        foreach ($candidates as $symbolId => $choices) {
            usort($choices, static fn (array $left, array $right): int => [
                $right['priority'],
                self::certaintyRank($right['certainty']),
                $left['kind']->value,
                $left['reason'],
            ] <=> [
                $left['priority'],
                self::certaintyRank($left['certainty']),
                $right['kind']->value,
                $right['reason'],
            ]);
            $selected = $choices[0];
            $graph->applyClassification(
                $selected['symbol']->id,
                $selected['kind'],
                $selected['certainty'],
                $selected['reason'],
            );
            $assignments[] = [
                'symbol' => $symbolId,
                'kind' => $selected['kind']->value,
                'certainty' => $selected['certainty']->value,
                'reason' => $selected['reason'],
            ];
        }

        foreach ($graph->nodes() as $node) {
            if (isset($candidates[$node->id->value])
                || preg_match('/^(class|interface|trait|enum):/', $node->id->value) !== 1) {
                continue;
            }

            $reason = 'external structural target fallback';
            $graph->applyClassification($node->id, NodeKind::ClassSymbol, Certainty::Possible, $reason);
            $assignments[] = [
                'symbol' => $node->id->value,
                'kind' => NodeKind::ClassSymbol->value,
                'certainty' => Certainty::Possible->value,
                'reason' => $reason,
            ];
        }

        usort($assignments, static fn (array $left, array $right): int => $left['symbol'] <=> $right['symbol']);

        return $assignments;
    }

    private function addNamespaceConvention(array &$candidates, SymbolDefinition $symbol): void
    {
        if ($symbol->qualifiedName === null) {
            return;
        }

        $segments = explode('\\', $symbol->qualifiedName);
        $container = $segments[count($segments) - 2] ?? null;
        $kind = is_string($container)
            ? NodeKind::tryFrom((string) ($this->namespaceConventions[$container] ?? ''))
            : null;

        if ($kind === null || ! in_array($kind, self::semanticKinds(), true)) {
            return;
        }

        $this->candidate(
            $candidates,
            $symbol,
            $kind,
            Certainty::Probable,
            'namespace convention: '.$container,
            10,
        );
    }

    private function addInheritanceRules(array &$candidates, SymbolDefinition $symbol): void
    {
        $extends = array_map(
            static fn (string $name): string => ltrim($name, '\\'),
            $symbol->attributes['extends'] ?? [],
        );
        $implements = array_map(
            static fn (string $name): string => ltrim($name, '\\'),
            $symbol->attributes['implements'] ?? [],
        );

        if (in_array('Illuminate\Foundation\Http\FormRequest', $extends, true)) {
            $this->candidate($candidates, $symbol, NodeKind::FormRequest, Certainty::Certain, 'extends FormRequest', 70);
        }

        if (in_array('Illuminate\Database\Eloquent\Model', $extends, true)) {
            $this->candidate($candidates, $symbol, NodeKind::Model, Certainty::Certain, 'extends Eloquent Model', 70);
        }

        if (in_array('Illuminate\Console\Command', $extends, true)) {
            $this->candidate($candidates, $symbol, NodeKind::Command, Certainty::Certain, 'extends Console Command', 70);
        }

        if (in_array('Illuminate\Contracts\Queue\ShouldQueue', $implements, true)) {
            $this->candidate($candidates, $symbol, NodeKind::Job, Certainty::Certain, 'implements ShouldQueue', 50);
        }
    }

    private function addBootRules(array &$candidates, array $bootFacts, SymbolTable $symbols): void
    {
        foreach ($bootFacts as $fact) {
            if (! $fact instanceof BootFact) {
                continue;
            }

            if ($fact->kind === 'route') {
                $this->candidateForName(
                    $candidates,
                    $symbols,
                    $fact->attributes['action_class'] ?? null,
                    NodeKind::Controller,
                    'registered route action',
                    100,
                );

                foreach ($fact->attributes['middleware'] ?? [] as $middleware) {
                    $this->candidateForName(
                        $candidates,
                        $symbols,
                        is_string($middleware) ? $middleware : null,
                        NodeKind::Middleware,
                        'class middleware registered on route',
                        100,
                    );
                }
            }

            if ($fact->kind === 'policy') {
                $this->candidateForName(
                    $candidates,
                    $symbols,
                    $fact->attributes['policy'] ?? null,
                    NodeKind::Policy,
                    'registered policy mapping',
                    100,
                );
            }

            if ($fact->kind === 'event_listener') {
                $this->candidateForName(
                    $candidates,
                    $symbols,
                    $fact->attributes['listener'] ?? null,
                    NodeKind::Listener,
                    'registered event listener',
                    100,
                );
                $this->candidateForName(
                    $candidates,
                    $symbols,
                    $fact->attributes['event'] ?? null,
                    NodeKind::Event,
                    'registered listener event',
                    80,
                );
            }
        }
    }

    private function addGraphRules(array &$candidates, KnowledgeGraph $graph, SymbolTable $symbols): void
    {
        foreach ($graph->edges() as $edge) {
            if ($edge->type === EdgeType::ListensTo) {
                $this->candidateForId($candidates, $symbols, $edge->source->value, NodeKind::Listener, 'listens_to source', 90);
                $this->candidateForId($candidates, $symbols, $edge->target->value, NodeKind::Event, 'listens_to target', 80);
            } elseif ($edge->type === EdgeType::Dispatches) {
                $this->candidateForId($candidates, $symbols, $edge->target->value, NodeKind::Event, 'dispatch target', 40);
            } elseif ($edge->type === EdgeType::SendsNotification) {
                $this->candidateForId($candidates, $symbols, $edge->target->value, NodeKind::Notification, 'notification send target', 90);
            } elseif ($edge->type === EdgeType::SendsMail) {
                $this->candidateForId($candidates, $symbols, $edge->target->value, NodeKind::Mailable, 'mail send target', 90);
            }
        }
    }

    private function candidateForName(
        array &$candidates,
        SymbolTable $symbols,
        mixed $name,
        NodeKind $kind,
        string $reason,
        int $priority,
    ): void {
        if (! is_string($name)) {
            return;
        }

        $matches = $symbols->exact($name);

        if (count($matches) === 1 && $matches[0]->structuralKind !== NodeKind::Method) {
            $this->candidate($candidates, $matches[0], $kind, Certainty::Certain, $reason, $priority);
        }
    }

    private function candidateForId(
        array &$candidates,
        SymbolTable $symbols,
        string $id,
        NodeKind $kind,
        string $reason,
        int $priority,
    ): void {
        $matches = $symbols->byId(\DNDark\LogicMap\Domain\Graph\NodeId::fromString($id));

        if (count($matches) === 1 && $matches[0]->structuralKind !== NodeKind::Method) {
            $this->candidate($candidates, $matches[0], $kind, Certainty::Certain, $reason, $priority);
        }
    }

    private function candidate(
        array &$candidates,
        SymbolDefinition $symbol,
        NodeKind $kind,
        Certainty $certainty,
        string $reason,
        int $priority,
    ): void {
        $candidates[$symbol->id->value][] = compact('symbol', 'kind', 'certainty', 'reason', 'priority');
    }

    /** @return list<NodeKind> */
    private static function semanticKinds(): array
    {
        return [
            NodeKind::ClassSymbol,
            NodeKind::Middleware,
            NodeKind::FormRequest,
            NodeKind::Policy,
            NodeKind::Controller,
            NodeKind::Action,
            NodeKind::Service,
            NodeKind::Repository,
            NodeKind::Command,
            NodeKind::Job,
            NodeKind::Event,
            NodeKind::Listener,
            NodeKind::Notification,
            NodeKind::Mailable,
            NodeKind::Model,
        ];
    }

    private static function certaintyRank(Certainty $certainty): int
    {
        return match ($certainty) {
            Certainty::Certain => 3,
            Certainty::Probable => 2,
            Certainty::Possible => 1,
        };
    }
}
