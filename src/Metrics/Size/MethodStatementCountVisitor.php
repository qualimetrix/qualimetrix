<?php

declare(strict_types=1);

namespace Qualimetrix\Metrics\Size;

use PhpParser\Node;
use PhpParser\Node\Expr;
use PhpParser\Node\Stmt;
use PhpParser\NodeVisitorAbstract;
use Qualimetrix\Core\Metric\MethodWithMetrics;
use Qualimetrix\Core\Metric\MetricBag;
use Qualimetrix\Core\Metric\MetricName;
use Qualimetrix\Metrics\ResettableVisitorInterface;
use Qualimetrix\Metrics\VisitorMethodTrackingTrait;

/**
 * Counts semantic statements owned by each method, function, or closure.
 *
 * Counted nodes are executable statements (expression, return, echo, unset,
 * global/static declarations, break/continue/goto/label) and control-flow
 * statements or clauses (if/elseif/else, switch/case, loops, try/catch/finally,
 * and declare). Structural declarations, empty statements, comments, and blank
 * lines are not counted. An arrow function owns one synthetic statement for its
 * expression body.
 *
 * Container statements count as one and their contained statements are counted
 * recursively. Nested named/anonymous callables own their statements; they are
 * excluded from the enclosing callable. Anonymous-class internals are ignored.
 */
final class MethodStatementCountVisitor extends NodeVisitorAbstract implements ResettableVisitorInterface
{
    use VisitorMethodTrackingTrait;

    /** @var array<string, array{namespace: ?string, class: ?string, method: string, line: int, count: int}> */
    private array $methodInfos = [];

    /** @var list<string> */
    private array $methodStack = [];

    private ?string $currentNamespace = null;
    private ?string $currentClass = null;
    private int $closureCounter = 0;
    private int $anonymousClassDepth = 0;

    public function reset(): void
    {
        $this->methodInfos = [];
        $this->methodStack = [];
        $this->currentNamespace = null;
        $this->currentClass = null;
        $this->closureCounter = 0;
        $this->anonymousClassDepth = 0;
    }

    /**
     * @return list<MethodWithMetrics>
     */
    public function getMethodsWithMetrics(): array
    {
        $result = [];

        foreach ($this->methodInfos as $info) {
            $result[] = new MethodWithMetrics(
                namespace: $info['namespace'],
                class: $info['class'],
                method: $info['method'],
                line: $info['line'],
                metrics: (new MetricBag())->with(MetricName::SIZE_METHOD_STATEMENT_COUNT, $info['count']),
            );
        }

        return $result;
    }

    public function enterNode(Node $node): ?int
    {
        if ($node instanceof Stmt\Namespace_) {
            $this->currentNamespace = $node->name?->toString() ?? '';
        }

        if ($node instanceof Stmt\Class_) {
            if ($node->name === null) {
                ++$this->anonymousClassDepth;
            } else {
                $this->currentClass = $node->name->toString();
            }
        } elseif ($this->isClassLikeNode($node)) {
            $className = $this->extractClassLikeName($node);
            if ($className !== null) {
                $this->currentClass = $className;
            }
        }

        if ($node instanceof Stmt\ClassMethod) {
            if ($this->anonymousClassDepth === 0) {
                $this->startMethod(
                    $this->buildMethodFqn($node->name->toString()),
                    $node->name->toString(),
                    $node->getStartLine(),
                );
            }

            return null;
        }

        if ($node instanceof Stmt\Function_) {
            if ($this->anonymousClassDepth > 0) {
                return null;
            }

            $this->startMethod(
                $this->buildFunctionFqn($node->name->toString()),
                $node->name->toString(),
                $node->getStartLine(),
            );

            return null;
        }

        if ($node instanceof Expr\Closure || $node instanceof Expr\ArrowFunction) {
            if ($this->anonymousClassDepth > 0) {
                return null;
            }

            ++$this->closureCounter;
            $name = '{closure#' . $this->closureCounter . '}';
            $this->startMethod($this->buildClosureFqn(), $name, $node->getStartLine());

            if ($node instanceof Expr\ArrowFunction) {
                $this->incrementCurrentMethod();
            }

            return null;
        }

        if ($this->anonymousClassDepth === 0 && $this->isCountedStatement($node)) {
            $this->incrementCurrentMethod();
        }

        return null;
    }

    public function leaveNode(Node $node): ?int
    {
        if ($node instanceof Stmt\ClassMethod) {
            if ($this->anonymousClassDepth === 0) {
                array_pop($this->methodStack);
            }

            return null;
        }

        if ($node instanceof Stmt\Function_) {
            if ($this->anonymousClassDepth === 0) {
                array_pop($this->methodStack);
            }

            return null;
        }

        if ($node instanceof Expr\Closure || $node instanceof Expr\ArrowFunction) {
            if ($this->anonymousClassDepth === 0) {
                array_pop($this->methodStack);
            }

            return null;
        }

        if ($node instanceof Stmt\Class_) {
            if ($node->name === null) {
                --$this->anonymousClassDepth;
            } else {
                $this->currentClass = null;
            }
        } elseif ($this->isClassLikeNode($node)) {
            $this->currentClass = null;
        }

        if ($node instanceof Stmt\Namespace_) {
            $this->currentNamespace = null;
        }

        return null;
    }

    private function startMethod(string $fqn, string $method, int $line): void
    {
        $this->methodInfos[$fqn] = [
            'namespace' => $this->currentNamespace,
            'class' => $this->currentClass,
            'method' => $method,
            'line' => $line,
            'count' => 0,
        ];
        $this->methodStack[] = $fqn;
    }

    private function incrementCurrentMethod(): void
    {
        if ($this->methodStack === []) {
            return;
        }

        $fqn = $this->methodStack[array_key_last($this->methodStack)];
        ++$this->methodInfos[$fqn]['count'];
    }

    private function isCountedStatement(Node $node): bool
    {
        return $node instanceof Stmt\Expression
            || $node instanceof Stmt\Return_
            || $node instanceof Stmt\Echo_
            || $node instanceof Stmt\Unset_
            || $node instanceof Stmt\Global_
            || $node instanceof Stmt\Static_
            || $node instanceof Stmt\Break_
            || $node instanceof Stmt\Continue_
            || $node instanceof Stmt\Goto_
            || $node instanceof Stmt\Label
            || $node instanceof Stmt\If_
            || $node instanceof Stmt\ElseIf_
            || $node instanceof Stmt\Else_
            || $node instanceof Stmt\Switch_
            || $node instanceof Stmt\Case_
            || $node instanceof Stmt\For_
            || $node instanceof Stmt\Foreach_
            || $node instanceof Stmt\While_
            || $node instanceof Stmt\Do_
            || $node instanceof Stmt\TryCatch
            || $node instanceof Stmt\Catch_
            || $node instanceof Stmt\Finally_
            || $node instanceof Stmt\Declare_;
    }
}
