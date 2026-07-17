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

final class NotificationDetector
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
                if (! $this->isNotificationCall($call)) {
                    continue;
                }

                $notification = $this->targets->instantiatedArgument($file, $call, $symbols);
                $sources = $symbols->byId($call->enclosingSymbolId);

                if ($notification === null || count($sources) !== 1) {
                    continue;
                }

                $execution = $call->targetName === 'sendNow'
                    ? 'sync'
                    : ($this->targets->isQueueable($notification) ? 'async' : 'sync');
                SemanticEdgeFactory::add(
                    $graph,
                    $sources[0]->id,
                    EdgeType::SendsNotification,
                    $notification->id,
                    EvidenceOrigin::StaticAst,
                    'notification_detector',
                    Certainty::Certain,
                    new SourceLocation($call->file, $call->startLine, $call->endLine),
                    $call->normalizedExpression,
                    null,
                    null,
                    [
                        'execution' => $execution,
                        'recipient_type' => $this->recipientType($call, $sources[0]),
                    ],
                );
            }
        }

        return [];
    }

    private function isNotificationCall(CallSiteFact $call): bool
    {
        if ($call->targetName === 'notify') {
            return true;
        }

        return $call->receiverType === 'Illuminate\Support\Facades\Notification'
            && in_array($call->targetName, ['send', 'sendNow'], true);
    }

    private function recipientType(CallSiteFact $call, SymbolDefinition $source): ?string
    {
        $expression = $call->targetName === 'notify'
            ? $call->receiverExpression
            : (($call->arguments[0]['expression'] ?? null));

        if (! is_string($expression)
            || preg_match('/^\$([A-Za-z_][A-Za-z0-9_]*)$/', $expression, $matches) !== 1) {
            return null;
        }

        return $source->declaredParameterTypes[$matches[1]] ?? null;
    }
}
