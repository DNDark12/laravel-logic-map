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

final class JobDispatchDetector
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
                $job = $this->jobTarget($file, $call, $symbols);

                if ($job === null || ! $this->targets->isQueueable($job)) {
                    continue;
                }

                $sources = $symbols->byId($call->enclosingSymbolId);

                if (count($sources) !== 1) {
                    continue;
                }

                $execution = $this->execution($call->targetName);
                $location = new SourceLocation($call->file, $call->startLine, $call->endLine);
                SemanticEdgeFactory::add(
                    $graph,
                    $sources[0]->id,
                    EdgeType::Dispatches,
                    $job->id,
                    EvidenceOrigin::StaticAst,
                    'job_dispatch_detector',
                    Certainty::Certain,
                    $location,
                    $call->normalizedExpression,
                    null,
                    null,
                    ['execution' => $execution, 'syntax' => $call->targetName],
                );

                if ($execution !== 'sync') {
                    SemanticEdgeFactory::add(
                        $graph,
                        $sources[0]->id,
                        EdgeType::Queues,
                        $job->id,
                        EvidenceOrigin::StaticAst,
                        'job_dispatch_detector',
                        Certainty::Certain,
                        $location,
                        $call->normalizedExpression,
                        null,
                        null,
                        ['execution' => $execution, 'syntax' => $call->targetName],
                    );
                }
            }
        }

        return [];
    }

    private function jobTarget(
        ParsedFile $file,
        CallSiteFact $call,
        SymbolTable $symbols,
    ): ?SymbolDefinition {
        $dispatchMethods = ['dispatch', 'dispatchSync', 'dispatchAfterResponse', 'dispatchAfterCommit'];

        if (! in_array($call->targetName, $dispatchMethods, true)) {
            $helper = substr($call->targetName, strrpos($call->targetName, '\\') + 1);

            if ($helper !== 'dispatch') {
                return null;
            }
        }

        if ($call->targetName === 'dispatch'
            && is_string($call->receiverType)
            && $call->receiverType !== 'Illuminate\Support\Facades\Bus') {
            $targets = $symbols->exact($call->receiverType);

            if (count($targets) === 1) {
                return $targets[0];
            }
        }

        if ($call->receiverType === 'Illuminate\Support\Facades\Bus'
            || $call->callKind === 'function') {
            return $this->targets->instantiatedArgument($file, $call, $symbols);
        }

        return null;
    }

    private function execution(string $method): string
    {
        return match ($method) {
            'dispatchSync' => 'sync',
            'dispatchAfterResponse' => 'after_response',
            'dispatchAfterCommit' => 'after_commit',
            default => 'async',
        };
    }
}
