<?php

namespace DNDark\LogicMap\Analysis\Php;

use DNDark\LogicMap\Analysis\Facts\ControlContext;
use DNDark\LogicMap\Analysis\Facts\ControlKind;
use PhpParser\Node;
use PhpParser\Node\Expr;
use PhpParser\Node\Stmt;

final class ControlContextStack
{
    /** @var array<string, ControlContext> */
    private array $contexts = [];

    public function __construct(private readonly ExpressionNormalizer $normalizer) {}

    public function enterNode(Node $node): void
    {
        if ($node instanceof Stmt\If_) {
            $this->add(ControlKind::Branch, $node->cond, 'true', $node->stmts, $node);

            if ($node->else !== null) {
                $this->add(ControlKind::Branch, $node->cond, 'false', $node->else->stmts, $node->else);
            }
        } elseif ($node instanceof Stmt\ElseIf_) {
            $this->add(ControlKind::Branch, $node->cond, 'true', $node->stmts, $node);
        } elseif ($node instanceof Stmt\Foreach_) {
            $this->add(ControlKind::Loop, $node->expr, 'body', $node->stmts, $node);
        } elseif ($node instanceof Stmt\While_ || $node instanceof Stmt\Do_) {
            $this->add(ControlKind::Loop, $node->cond, 'body', $node->stmts, $node);
        } elseif ($node instanceof Stmt\For_) {
            $predicate = $node->cond === [] ? 'true' : implode(', ', array_map(
                fn (Expr $expression): string => $this->normalizer->normalize($expression),
                $node->cond,
            ));
            $this->add(ControlKind::Loop, $predicate, 'body', $node->stmts, $node);
        } elseif ($node instanceof Stmt\TryCatch) {
            $this->add(ControlKind::Try, null, null, $node->stmts, $node);

            foreach ($node->catches as $catch) {
                $types = implode('|', array_map(static fn ($type): string => $type->toString(), $catch->types));
                $this->add(ControlKind::Catch, $types, 'catch', $catch->stmts, $catch);
            }

            if ($node->finally !== null) {
                $this->add(ControlKind::Finally, null, null, $node->finally->stmts, $node->finally);
            }
        } elseif ($node instanceof Expr\Match_) {
            $subject = $this->normalizer->normalize($node->cond);

            foreach ($node->arms as $arm) {
                $labels = $arm->conds === null
                    ? 'default'
                    : implode('|', array_map(fn (Expr $cond): string => $this->normalizer->normalize($cond), $arm->conds));
                $this->register(new ControlContext(
                    ControlKind::MatchArm,
                    $subject,
                    $labels,
                    $arm->body->getStartLine(),
                    $arm->body->getEndLine(),
                ));
            }
        } elseif ($node instanceof Expr\StaticCall
            && $this->callName($node) === 'transaction'
            && $this->isDb($node)
            && isset($node->args[0])
            && $node->args[0]->value instanceof Expr\Closure) {
            $closure = $node->args[0]->value;
            $this->add(ControlKind::Transaction, 'DB::transaction', 'body', $closure->stmts, $closure);
        }
    }

    public function leaveNode(Node $node): void {}

    /** @return list<ControlContext> */
    public function contextsForSpan(int $startLine, int $endLine): array
    {
        $contexts = array_values(array_filter(
            $this->contexts,
            static fn (ControlContext $context): bool => $context->startLine <= $startLine
                && $context->endLine >= $endLine,
        ));
        usort($contexts, static fn (ControlContext $left, ControlContext $right): int => [
            $left->startLine,
            -$left->endLine,
            $left->kind->value,
            $left->branch ?? '',
        ] <=> [
            $right->startLine,
            -$right->endLine,
            $right->kind->value,
            $right->branch ?? '',
        ]);

        return $contexts;
    }

    public function contextArraysForSpan(int $startLine, int $endLine): array
    {
        return array_map(
            static fn (ControlContext $context): array => $context->toArray(),
            $this->contextsForSpan($startLine, $endLine),
        );
    }

    private function add(
        ControlKind $kind,
        Node|string|null $predicate,
        ?string $branch,
        array $statements,
        Node $fallback,
    ): void {
        $start = $statements === [] ? $fallback->getStartLine() : $statements[0]->getStartLine();
        $end = $statements === [] ? $fallback->getEndLine() : $statements[array_key_last($statements)]->getEndLine();
        $this->register(new ControlContext(
            $kind,
            $predicate === null ? null : $this->normalizer->normalize($predicate),
            $branch,
            $start,
            $end,
        ));
    }

    private function register(ControlContext $context): void
    {
        $this->contexts[$context->boundaryId] = $context;
    }

    private function callName(Expr\StaticCall $call): ?string
    {
        return $call->name instanceof Node\Identifier ? $call->name->toString() : null;
    }

    private function isDb(Expr\StaticCall $call): bool
    {
        if (! $call->class instanceof Node\Name) {
            return false;
        }

        $name = ltrim($call->class->toString(), '\\');

        return $name === 'DB' || $name === 'Illuminate\Support\Facades\DB';
    }
}
