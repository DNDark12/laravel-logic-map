<?php

namespace DNDark\LogicMap\Analysis\Laravel\Facts;

use DNDark\LogicMap\Analysis\Facts\FileAwareFactCollector;
use DNDark\LogicMap\Analysis\Facts\SemanticFact;
use DNDark\LogicMap\Support\RelativePath;
use LogicException;
use PhpParser\Node;
use PhpParser\Node\Stmt;
use PhpParser\NodeVisitorAbstract;
use PhpParser\PrettyPrinter\Standard;

final class BranchConditionFactCollector extends NodeVisitorAbstract implements FileAwareFactCollector
{
    /** @var list<SemanticFact> */
    private array $facts = [];

    /** @var list<string> */
    private array $classStack = [];

    /** @var list<string> */
    private array $methodStack = [];

    private ?string $file = null;

    private Standard $printer;

    public function __construct()
    {
        $this->printer = new Standard();
    }

    public function name(): string
    {
        return 'branch_conditions';
    }

    public function useFile(string $relativePath): void
    {
        $this->file = RelativePath::normalize($relativePath);
        $this->classStack = [];
        $this->methodStack = [];
    }

    public function enterNode(Node $node): null
    {
        if ($node instanceof Stmt\ClassLike && $node->name !== null) {
            $this->classStack[] = ltrim((string) $node->namespacedName, '\\');
        }

        if ($node instanceof Stmt\ClassMethod && $this->classStack !== []) {
            $this->methodStack[] = 'method:'.$this->classStack[array_key_last($this->classStack)]
                .'::'.$node->name->toString();
        }

        if ($node instanceof Stmt\If_) {
            $this->collectBranch($node->cond, $node->stmts, 'truthy', $node);

            if ($node->else !== null) {
                $this->collectBranch($node->cond, $node->else->stmts, 'falsy', $node->else);
            }
        }

        if ($node instanceof Stmt\ElseIf_) {
            $this->collectBranch($node->cond, $node->stmts, 'truthy', $node);
        }

        return null;
    }

    public function leaveNode(Node $node): null
    {
        if ($node instanceof Stmt\ClassMethod && $this->methodStack !== []) {
            array_pop($this->methodStack);
        }

        if ($node instanceof Stmt\ClassLike && $node->name !== null && $this->classStack !== []) {
            array_pop($this->classStack);
        }

        return null;
    }

    public function facts(): array
    {
        $facts = $this->facts;
        $this->facts = [];

        return $facts;
    }

    private function collectBranch(Node $condition, array $statements, string $branch, Node $boundary): void
    {
        if ($this->methodStack === []) {
            return;
        }

        $startLine = $statements === [] ? $boundary->getStartLine() : $statements[0]->getStartLine();
        $endLine = $statements === []
            ? $boundary->getEndLine()
            : $statements[array_key_last($statements)]->getEndLine();
        $this->facts[] = new SemanticFact(
            'branch_condition',
            $this->file ?? throw new LogicException('Branch collector requires a file.'),
            $startLine,
            $endLine,
            [
                'enclosing_symbol' => $this->methodStack[array_key_last($this->methodStack)],
                'expression' => $this->printer->prettyPrintExpr($condition),
                'branch' => $branch,
                'condition_start_line' => $condition->getStartLine(),
                'condition_end_line' => $condition->getEndLine(),
            ],
        );
    }
}
