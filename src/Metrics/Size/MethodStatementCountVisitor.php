<?php

declare(strict_types=1);

namespace Qualimetrix\Metrics\Size;

use PhpParser\Node;
use PhpParser\Node\Expr;
use PhpParser\Node\PropertyHook;
use PhpParser\Node\Stmt;
use PhpParser\NodeVisitorAbstract;
use Qualimetrix\Core\Metric\CallableWithMetrics;
use Qualimetrix\Core\Metric\MetricBag;
use Qualimetrix\Core\Metric\MetricName;
use Qualimetrix\Core\Path\RelativePath;
use Qualimetrix\Core\Symbol\CallableKind;
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

    /** @var array<string, array{logicalFqn: string, namespace: ?string, class: ?string, method: string, startFilePos: int, sourceLine: int, kind: CallableKind, anonymousSyntax: ?string, classStartFilePos: ?int, count: int}> */
    private array $methodInfos = [];

    /** @var list<string> */
    private array $methodStack = [];

    private ?string $currentNamespace = null;
    private ?string $currentClass = null;
    private ?int $currentClassStartFilePos = null;
    private int $closureCounter = 0;
    private ?string $currentProperty = null;

    /** @var list<array{?string, ?int}> */
    private array $classContextStack = [];

    public function reset(): void
    {
        $this->methodInfos = [];
        $this->methodStack = [];
        $this->currentNamespace = null;
        $this->currentClass = null;
        $this->currentClassStartFilePos = null;
        $this->closureCounter = 0;
        $this->currentProperty = null;
        $this->resetCallableTraversalKeys();
        $this->classContextStack = [];
    }

    /**
     * @return list<CallableWithMetrics>
     */
    public function getCallablesWithMetrics(RelativePath $file): array
    {
        $result = [];

        $ordinals = $this->callableCollisionOrdinals($this->methodInfos);
        foreach ($this->methodInfos as $key => $info) {
            $result[] = $this->createCallableWithMetrics(
                $info,
                $file,
                (new MetricBag())->with(MetricName::SIZE_METHOD_STATEMENT_COUNT, $info['count']),
                $ordinals[$key],
            );
        }

        return $result;
    }

    /** @return array<string, int> */
    public function getStatementCounts(): array
    {
        $counts = [];
        foreach ($this->methodInfos as $info) {
            $counts[$info['logicalFqn']] = $info['count'];
        }

        return $counts;
    }

    public function enterNode(Node $node): ?int
    {
        if ($node instanceof Stmt\Namespace_) {
            $this->currentNamespace = $node->name?->toString() ?? '';
        }

        if ($this->isClassLikeNode($node)) {
            $this->classContextStack[] = [$this->currentClass, $this->currentClassStartFilePos];
        }

        if ($node instanceof Stmt\Class_) {
            if ($node->name === null) {
                $this->currentClass = $this->buildAnonymousClassName($node->getStartFilePos());
                $this->currentClassStartFilePos = $node->getStartFilePos();
            } else {
                $this->currentClass = $node->name->toString();
                $this->currentClassStartFilePos = $node->getStartFilePos();
            }
        } elseif ($this->isClassLikeNode($node)) {
            $className = $this->extractClassLikeName($node);
            if ($className !== null) {
                $this->currentClass = $className;
                $this->currentClassStartFilePos = $node->getStartFilePos();
            }
        }

        if ($node instanceof Stmt\ClassMethod) {
            $this->startMethod(
                $this->buildMethodFqn($node->name->toString()),
                $node->name->toString(),
                $node->getStartFilePos(),
                $node->getStartLine(),
                CallableKind::Method,
                null,
            );

            return null;
        }

        if ($node instanceof Stmt\Property && $this->currentClass !== null && \count($node->props) === 1) {
            $this->currentProperty = $node->props[0]->name->toString();
        }

        if ($node instanceof PropertyHook && $this->currentClass !== null && $this->currentProperty !== null) {
            $name = $this->currentProperty . '::' . $node->name->toString();
            $this->startMethod(
                $this->buildMethodFqn($name),
                $name,
                $node->getStartFilePos(),
                $node->getStartLine(),
                CallableKind::PropertyHook,
                null,
            );

            return null;
        }

        if ($node instanceof Stmt\Function_) {
            $this->startMethod(
                $this->buildFunctionFqn($node->name->toString()),
                $node->name->toString(),
                $node->getStartFilePos(),
                $node->getStartLine(),
                CallableKind::Function,
                null,
            );

            return null;
        }

        if ($node instanceof Expr\Closure || $node instanceof Expr\ArrowFunction) {
            ++$this->closureCounter;
            $name = '{closure#' . $this->closureCounter . '}';
            $this->startMethod(
                $this->buildClosureFqn(),
                $name,
                $node->getStartFilePos(),
                $node->getStartLine(),
                CallableKind::AnonymousCallable,
                $node instanceof Expr\Closure ? 'closure' : 'arrow',
            );

            if ($node instanceof Expr\ArrowFunction) {
                $this->incrementCurrentMethod();
            }

            return null;
        }

        if ($this->isCountedStatement($node)) {
            $this->incrementCurrentMethod();
        }

        return null;
    }

    public function leaveNode(Node $node): ?int
    {
        if ($node instanceof Stmt\ClassMethod) {
            array_pop($this->methodStack);

            return null;
        }

        if ($node instanceof PropertyHook) {
            if ($this->currentClass !== null && $this->currentProperty !== null) {
                array_pop($this->methodStack);
            }

            return null;
        }

        if ($node instanceof Stmt\Property) {
            $this->currentProperty = null;
        }

        if ($node instanceof Stmt\Function_) {
            array_pop($this->methodStack);

            return null;
        }

        if ($node instanceof Expr\Closure || $node instanceof Expr\ArrowFunction) {
            array_pop($this->methodStack);

            return null;
        }

        if ($node instanceof Stmt\Class_) {
            [$this->currentClass, $this->currentClassStartFilePos] = array_pop($this->classContextStack) ?? [null, null];
        } elseif ($this->isClassLikeNode($node)) {
            [$this->currentClass, $this->currentClassStartFilePos] = array_pop($this->classContextStack) ?? [null, null];
        }

        if ($node instanceof Stmt\Namespace_) {
            $this->currentNamespace = null;
        }

        return null;
    }

    private function startMethod(
        string $fqn,
        string $method,
        int $startFilePos,
        int $sourceLine,
        CallableKind $kind,
        ?string $anonymousSyntax,
    ): void {
        $logicalFqn = $fqn;
        $fqn = $this->createCallableTraversalKey($logicalFqn, $startFilePos);
        $this->methodInfos[$fqn] = [
            'logicalFqn' => $logicalFqn,
            'namespace' => $this->currentNamespace,
            'class' => $this->currentClass,
            'method' => $method,
            'startFilePos' => $startFilePos,
            'sourceLine' => $sourceLine,
            'kind' => $kind,
            'anonymousSyntax' => $anonymousSyntax,
            'classStartFilePos' => $this->currentClassStartFilePos,
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
