<?php

namespace DNDark\LogicMap\Analysis\Laravel\Detectors;

use DNDark\LogicMap\Analysis\Laravel\Boot\BootFact;
use DNDark\LogicMap\Analysis\Laravel\CallTargetLocator;
use DNDark\LogicMap\Analysis\Laravel\SemanticEdgeFactory;
use DNDark\LogicMap\Analysis\Php\ParsedFile;
use DNDark\LogicMap\Analysis\Php\SymbolTable;
use DNDark\LogicMap\Domain\Graph\Certainty;
use DNDark\LogicMap\Domain\Graph\EdgeType;
use DNDark\LogicMap\Domain\Graph\EvidenceOrigin;
use DNDark\LogicMap\Domain\Graph\KnowledgeGraph;
use DNDark\LogicMap\Domain\Graph\SourceLocation;

final class ListenerDetector
{
    private CallTargetLocator $targets;

    public function __construct()
    {
        $this->targets = new CallTargetLocator();
    }

    public function detect(
        array $files,
        SymbolTable $symbols,
        array $bootFacts,
        KnowledgeGraph $graph,
    ): array {
        foreach ($files as $file) {
            if (! $file instanceof ParsedFile) {
                continue;
            }

            foreach ($file->callSites as $call) {
                if ($call->receiverType !== 'Illuminate\Support\Facades\Event'
                    || $call->targetName !== 'listen') {
                    continue;
                }

                $event = $this->targets->classConstant($call->arguments[0] ?? null);
                $listener = $this->targets->classConstant($call->arguments[1] ?? null);

                if ($event !== null && $listener !== null) {
                    $this->emit(
                        $event,
                        $listener,
                        EvidenceOrigin::StaticAst,
                        new SourceLocation($call->file, $call->startLine, $call->endLine),
                        $call->normalizedExpression,
                        'listener:'.$call->file.':'.$call->startLine.':'.$call->endLine,
                        $symbols,
                        $graph,
                    );
                }
            }
        }

        foreach ($bootFacts as $fact) {
            if (! $fact instanceof BootFact || $fact->kind !== 'event_listener') {
                continue;
            }

            $this->emit(
                $fact->attributes['event'],
                $fact->attributes['listener'],
                EvidenceOrigin::LaravelBoot,
                null,
                null,
                'listener:'.$fact->attributes['event'].'=>'.$fact->attributes['listener']
                    .'::'.($fact->attributes['method'] ?? 'handle'),
                $symbols,
                $graph,
            );
        }

        return [];
    }

    private function emit(
        string $event,
        string $listener,
        EvidenceOrigin $origin,
        ?SourceLocation $location,
        ?string $expression,
        string $registrationKey,
        SymbolTable $symbols,
        KnowledgeGraph $graph,
    ): void {
        $events = $symbols->exact($event);
        $listeners = $symbols->exact($listener);

        if (count($events) !== 1 || count($listeners) !== 1) {
            return;
        }

        $queued = $this->targets->isQueueable($listeners[0]);
        $execution = $queued ? 'async' : 'sync';
        $identity = 'listener:'.$event.'=>'.$listener;
        SemanticEdgeFactory::add(
            $graph,
            $listeners[0]->id,
            EdgeType::ListensTo,
            $events[0]->id,
            $origin,
            'listener_detector',
            Certainty::Certain,
            $location,
            $expression,
            $registrationKey,
            $identity,
            ['execution' => $execution, 'effective' => $origin === EvidenceOrigin::LaravelBoot],
        );

        if ($queued) {
            SemanticEdgeFactory::add(
                $graph,
                $events[0]->id,
                EdgeType::Queues,
                $listeners[0]->id,
                $origin,
                'listener_detector',
                Certainty::Certain,
                $location,
                $expression,
                $registrationKey,
                $identity.':queue',
                ['execution' => 'async', 'effective' => $origin === EvidenceOrigin::LaravelBoot],
            );
        }
    }
}
