<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Evidence\CodeSmell\ControlFlow;

use PhpParser\Node;

use PhpParser\Node\Expr\ArrowFunction;
use PhpParser\Node\Expr\Closure;
use PhpParser\Node\Expr\FuncCall;
use PhpParser\Node\Name;
use PhpParser\Node\Stmt\Catch_;
use PhpParser\Node\Stmt\Do_;
use PhpParser\Node\Stmt\For_;
use PhpParser\Node\Stmt\TryCatch;
use PhpParser\Node\Stmt\While_;
use Qualimetrix\Analysis\Evidence\CodeSmell\CodeSmellLocation;

/** Evaluates control-flow code smells without owning AST traversal state. */
final class ControlFlowSmells
{
    /** @return list<CodeSmellLocation> */
    public function locations(Node $node, string $subjectId, int $foreachDepth): array
    {
        return match (true) {
            $node instanceof Node\Stmt\Goto_ => [$this->location('goto', $node, $subjectId)],
            $node instanceof Node\Expr\Exit_ => [$this->location('exit', $node, $subjectId)],
            $node instanceof TryCatch => $this->emptyCatchLocations($node, $subjectId, $foreachDepth),
            $node instanceof For_, $node instanceof While_, $node instanceof Do_ => $this->loopLocations($node, $subjectId),
            default => [],
        };
    }

    /** @return list<CodeSmellLocation> */
    private function emptyCatchLocations(TryCatch $tryCatch, string $subjectId, int $foreachDepth): array
    {
        $locations = [];
        foreach ($tryCatch->catches as $catch) {
            if ($this->isEmptyCatch($catch) && !($foreachDepth > 0 && $this->hasChainSignal($tryCatch->stmts))) {
                $locations[] = $this->location('empty_catch', $catch, $subjectId);
            }
        }

        return $locations;
    }

    /** @return list<CodeSmellLocation> */
    private function loopLocations(For_|While_|Do_ $node, string $subjectId): array
    {
        $conditions = match (true) {
            $node instanceof For_ => $node->cond,
            default => [$node->cond],
        };

        foreach ($conditions as $condition) {
            if ($this->containsCountCall($condition)) {
                return [$this->location('count_in_loop', $node, $subjectId)];
            }
        }

        return [];
    }

    private function isEmptyCatch(Catch_ $catch): bool
    {
        return array_filter($catch->stmts, static fn(Node $statement): bool => !$statement instanceof Node\Stmt\Nop) === [];
    }

    /** @param array<Node\Stmt> $statements */
    private function hasChainSignal(array $statements): bool
    {
        $pending = $statements;
        while ($pending !== []) {
            $statement = array_pop($pending);
            if ($statement instanceof Node\Stmt\Return_ || $statement instanceof Node\Stmt\Continue_) {
                return true;
            }

            if ($statement instanceof Node\Stmt\If_) {
                array_push($pending, ...$statement->stmts);
                foreach ($statement->elseifs as $elseif) {
                    array_push($pending, ...$elseif->stmts);
                }
                if ($statement->else !== null) {
                    array_push($pending, ...$statement->else->stmts);
                }
            }
        }

        return false;
    }

    private function containsCountCall(?Node $node): bool
    {
        $pending = $node === null ? [] : [$node];
        while ($pending !== []) {
            $current = array_pop($pending);
            if ($current instanceof Closure || $current instanceof ArrowFunction) {
                continue;
            }
            if ($current instanceof FuncCall
                && !$current->isFirstClassCallable()
                && $current->name instanceof Name
                && \in_array($current->name->toLowerString(), ['count', 'sizeof'], true)) {
                return true;
            }
            array_push($pending, ...$this->children($current));
        }

        return false;
    }

    /** @return list<Node> */
    private function children(Node $node): array
    {
        $children = [];
        foreach ($node->getSubNodeNames() as $name) {
            $value = $node->{$name}; // @phpstan-ignore property.dynamicName
            foreach (\is_array($value) ? $value : [$value] as $item) {
                if ($item instanceof Node) {
                    $children[] = $item;
                }
            }
        }

        return $children;
    }

    private function location(string $type, Node $node, string $subjectId): CodeSmellLocation
    {
        return new CodeSmellLocation($type, $node->getStartLine(), $node->getStartTokenPos(), $subjectId);
    }
}
