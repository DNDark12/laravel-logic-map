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

final class MailDetector
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
                if (! $this->isMailCall($call)) {
                    continue;
                }

                $mail = $this->targets->instantiatedArgument($file, $call, $symbols);
                $sources = $symbols->byId($call->enclosingSymbolId);

                if ($mail === null || count($sources) !== 1) {
                    continue;
                }

                $execution = in_array($call->targetName, ['queue', 'later'], true) ? 'async' : 'sync';
                SemanticEdgeFactory::add(
                    $graph,
                    $sources[0]->id,
                    EdgeType::SendsMail,
                    $mail->id,
                    EvidenceOrigin::StaticAst,
                    'mail_detector',
                    Certainty::Certain,
                    new SourceLocation($call->file, $call->startLine, $call->endLine),
                    $call->normalizedExpression,
                    null,
                    null,
                    [
                        'execution' => $execution,
                        'recipient_type' => $this->recipientType($file, $call, $sources[0]),
                    ],
                );
            }
        }

        return [];
    }

    private function isMailCall(CallSiteFact $call): bool
    {
        if (! in_array($call->targetName, ['send', 'sendNow', 'queue', 'later'], true)) {
            return false;
        }

        return $call->receiverType === 'Illuminate\Support\Facades\Mail'
            || (is_string($call->receiverExpression) && str_contains($call->receiverExpression, 'Mail::'));
    }

    private function recipientType(
        ParsedFile $file,
        CallSiteFact $outer,
        SymbolDefinition $source,
    ): ?string {
        foreach ($file->callSites as $call) {
            if ($call->receiverType !== 'Illuminate\Support\Facades\Mail'
                || $call->targetName !== 'to'
                || ! $call->enclosingSymbolId->equals($outer->enclosingSymbolId)
                || $call->startLine < $outer->startLine
                || $call->endLine > $outer->endLine) {
                continue;
            }

            $expression = $call->arguments[0]['expression'] ?? null;

            if (is_string($expression)
                && preg_match('/^\$([A-Za-z_][A-Za-z0-9_]*)$/', $expression, $matches) === 1) {
                return $source->declaredParameterTypes[$matches[1]] ?? null;
            }
        }

        return null;
    }
}
