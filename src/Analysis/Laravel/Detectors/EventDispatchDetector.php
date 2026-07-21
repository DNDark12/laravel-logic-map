<?php

namespace DNDark\LogicMap\Analysis\Laravel\Detectors;

use DNDark\LogicMap\Analysis\Facts\CallSiteFact;
use DNDark\LogicMap\Analysis\Laravel\CallTargetLocator;
use DNDark\LogicMap\Analysis\Laravel\SemanticEdgeFactory;
use DNDark\LogicMap\Analysis\Php\ParsedFile;
use DNDark\LogicMap\Analysis\Php\SymbolDefinition;
use DNDark\LogicMap\Analysis\Php\SymbolTable;
use DNDark\LogicMap\Domain\Graph\Certainty;
use DNDark\LogicMap\Domain\Graph\EdgeType;
use DNDark\LogicMap\Domain\Graph\EvidenceOrigin;
use DNDark\LogicMap\Domain\Graph\KnowledgeGraph;
use DNDark\LogicMap\Domain\Graph\SourceLocation;

final class EventDispatchDetector
{
    private CallTargetLocator $targets;

    public function __construct()
    {
        $this->targets = new CallTargetLocator();
    }

    public function detect(array $files, SymbolTable $symbols, KnowledgeGraph $graph): array
    {
        foreach ($files as $file) {
            if (! $file instanceof ParsedFile) {
                continue;
            }

            foreach ($file->callSites as $call) {
                $event = $this->eventTarget($file, $call, $symbols);

                if ($event === null || $this->targets->isQueueable($event)) {
                    continue;
                }

                $sources = $symbols->byId($call->enclosingSymbolId);

                if (count($sources) !== 1) {
                    continue;
                }

                SemanticEdgeFactory::add(
                    $graph,
                    $sources[0]->id,
                    EdgeType::Dispatches,
                    $event->id,
                    EvidenceOrigin::StaticAst,
                    'event_dispatch_detector',
                    Certainty::Certain,
                    new SourceLocation($call->file, $call->startLine, $call->endLine),
                    $call->normalizedExpression,
                    null,
                    null,
                    ['execution' => 'sync', 'syntax' => $call->targetName],
                );
            }
        }

        return [];
    }

    private function eventTarget(
        ParsedFile $file,
        CallSiteFact $call,
        SymbolTable $symbols,
    ): ?SymbolDefinition {
        if ($call->targetName === 'dispatch'
            && $call->receiverType !== 'Illuminate\Support\Facades\Event') {
            $targets = is_string($call->receiverType) ? $symbols->exact($call->receiverType) : [];

            return count($targets) === 1 ? $targets[0] : null;
        }

        $helper = substr($call->targetName, strrpos($call->targetName, '\\') + 1);

        if ($helper === 'event'
            || ($call->receiverType === 'Illuminate\Support\Facades\Event'
                && $call->targetName === 'dispatch')) {
            return $this->targets->instantiatedArgument($file, $call, $symbols);
        }

        return null;
    }
}
