<?php

declare(strict_types=1);

namespace Qualimetrix\Metrics\CodeSmell;

use PhpParser\Node;
use PhpParser\Node\Expr;
use PhpParser\Node\Expr\ArrowFunction;
use PhpParser\Node\Expr\Closure;
use PhpParser\Node\PropertyHook;
use PhpParser\Node\Stmt;
use PhpParser\Node\Stmt\ClassMethod;
use PhpParser\Node\Stmt\Function_;
use PhpParser\NodeVisitorAbstract;
use Qualimetrix\Core\Metric\CallableWithMetrics;
use Qualimetrix\Core\Metric\MetricBag;
use Qualimetrix\Core\Path\RelativePath;
use Qualimetrix\Core\Symbol\CallableKind;
use Qualimetrix\Metrics\ResettableVisitorInterface;
use Qualimetrix\Metrics\VisitorMethodTrackingTrait;

/**
 * Visitor for detecting unreachable code after terminal statements.
 *
 * Scans the top-level statement list of methods and functions.
 * After a terminal statement (return, throw, exit/die, continue, break),
 * any subsequent statements in the SAME list are unreachable.
 *
 * Does NOT recursively check inside if/else/try blocks.
 * Closures are intentionally skipped.
 */
final class UnreachableCodeVisitor extends NodeVisitorAbstract implements ResettableVisitorInterface
{
    use VisitorMethodTrackingTrait;

    /** @var array<string, int> Method/function FQN => unreachable statement count */
    private array $unreachableCounts = [];

    /** @var array<string, int> Method/function FQN => first unreachable line number */
    private array $firstUnreachableLines = [];

    /** @var array<string, array{logicalFqn: string, namespace: ?string, class: ?string, method: string, startFilePos: int, kind: CallableKind, anonymousSyntax: ?string, classStartFilePos: ?int}> traversal key => callable info */
    private array $methodInfos = [];

    private ?string $currentNamespace = null;
    private ?string $currentClass = null;
    private ?int $currentClassStartFilePos = null;
    private int $closureCounter = 0;
    private ?string $currentProperty = null;

    /** @var list<array{?string, ?int}> Class context before each nested class-like scope */
    private array $classStack = [];

    public function reset(): void
    {
        $this->unreachableCounts = [];
        $this->firstUnreachableLines = [];
        $this->methodInfos = [];
        $this->currentNamespace = null;
        $this->currentClass = null;
        $this->currentClassStartFilePos = null;
        $this->closureCounter = 0;
        $this->currentProperty = null;
        $this->resetCallableTraversalKeys();
        $this->classStack = [];
    }

    /**
     * @return array<string, int>
     */
    public function getUnreachableCounts(): array
    {
        /** @var array<string, int> $projected */
        $projected = $this->projectLogicalMetricMap($this->unreachableCounts, $this->methodInfos);

        return $projected;
    }

    /**
     * @return array<string, int>
     */
    public function getFirstUnreachableLines(): array
    {
        /** @var array<string, int> $projected */
        $projected = $this->projectLogicalMetricMap($this->firstUnreachableLines, $this->methodInfos);

        return $projected;
    }

    /**
     * Returns structured method metrics for each analyzed method.
     *
     * @return list<CallableWithMetrics>
     */
    public function getCallablesWithMetrics(RelativePath $file): array
    {
        $result = [];

        $ordinals = $this->callableCollisionOrdinals($this->methodInfos);
        foreach ($this->methodInfos as $fqn => $info) {
            $bag = (new MetricBag())->with('unreachableCode', $this->unreachableCounts[$fqn] ?? 0);

            if (isset($this->firstUnreachableLines[$fqn])) {
                $bag = $bag->with('unreachableCode.firstLine', $this->firstUnreachableLines[$fqn]);
            }

            $result[] = $this->createCallableWithMetrics($info, $file, $bag, $ordinals[$fqn]);
        }

        return $result;
    }

    public function enterNode(Node $node): ?int
    {
        // Track namespace
        if ($node instanceof Stmt\Namespace_) {
            $this->currentNamespace = $node->name?->toString() ?? '';
        }

        // Track class-like types, including anonymous declarations.
        $className = $this->extractClassLikeName($node);
        if ($this->isClassLikeNode($node)) {
            $this->classStack[] = [$this->currentClass, $this->currentClassStartFilePos];
        }
        if ($className !== null) {
            $this->currentClass = $className;
            $this->currentClassStartFilePos = $node->getStartFilePos();
        } elseif ($node instanceof Stmt\Class_ && $node->name === null) {
            $this->currentClass = $this->buildAnonymousClassName($node->getStartFilePos());
            $this->currentClassStartFilePos = $node->getStartFilePos();
        }

        // Class method
        if ($node instanceof ClassMethod) {
            $fqn = $this->buildMethodFqn($node->name->toString());
            $fqn = $this->createCallableTraversalKey($fqn, $node->getStartFilePos());
            $this->methodInfos[$fqn] = [
                'logicalFqn' => $this->buildMethodFqn($node->name->toString()),
                'namespace' => $this->currentNamespace,
                'class' => $this->currentClass,
                'method' => $node->name->toString(),
                'startFilePos' => $node->getStartFilePos(),
                'kind' => CallableKind::Method,
                'anonymousSyntax' => null,
                'classStartFilePos' => $this->currentClassStartFilePos,
            ];
            $this->analyzeAndStore($fqn, $node->stmts ?? []);

            return null;
        }

        if ($node instanceof Stmt\Property && $this->currentClass !== null && \count($node->props) === 1) {
            $this->currentProperty = $node->props[0]->name->toString();
        }

        if ($node instanceof PropertyHook && $this->currentClass !== null && $this->currentProperty !== null) {
            $name = $this->currentProperty . '::' . $node->name->toString();
            $fqn = $this->buildMethodFqn($name);
            $fqn = $this->createCallableTraversalKey($fqn, $node->getStartFilePos());
            $this->methodInfos[$fqn] = [
                'logicalFqn' => $this->buildMethodFqn($name),
                'namespace' => $this->currentNamespace,
                'class' => $this->currentClass,
                'method' => $name,
                'startFilePos' => $node->getStartFilePos(),
                'kind' => CallableKind::PropertyHook,
                'anonymousSyntax' => null,
                'classStartFilePos' => $this->currentClassStartFilePos,
            ];
            $this->analyzeAndStore($fqn, $node->body instanceof Expr ? [] : ($node->body ?? []));

            return null;
        }

        // Global function
        if ($node instanceof Function_) {
            $fqn = $this->buildFunctionFqn($node->name->toString());
            $fqn = $this->createCallableTraversalKey($fqn, $node->getStartFilePos());
            $this->methodInfos[$fqn] = [
                'logicalFqn' => $this->buildFunctionFqn($node->name->toString()),
                'namespace' => $this->currentNamespace,
                'class' => null,
                'method' => $node->name->toString(),
                'startFilePos' => $node->getStartFilePos(),
                'kind' => CallableKind::Function,
                'anonymousSyntax' => null,
                'classStartFilePos' => null,
            ];
            $this->analyzeAndStore($fqn, $node->stmts ?? []);

            return null;
        }

        if ($node instanceof Closure || $node instanceof ArrowFunction) {
            ++$this->closureCounter;
            $name = '{closure#' . $this->closureCounter . '}';
            $fqn = $this->buildClosureFqn();
            $fqn = $this->createCallableTraversalKey($fqn, $node->getStartFilePos());
            $this->methodInfos[$fqn] = [
                'logicalFqn' => $this->buildClosureFqn(),
                'namespace' => $this->currentNamespace,
                'class' => $this->currentClass,
                'method' => $name,
                'startFilePos' => $node->getStartFilePos(),
                'kind' => CallableKind::AnonymousCallable,
                'anonymousSyntax' => $node instanceof Closure ? 'closure' : 'arrow',
                'classStartFilePos' => $this->currentClassStartFilePos,
            ];
            $this->analyzeAndStore($fqn, $node instanceof ArrowFunction ? [] : ($node->stmts ?? []));

            return null;
        }

        return null;
    }

    public function leaveNode(Node $node): ?int
    {
        if ($node instanceof Stmt\Property) {
            $this->currentProperty = null;
        }

        // Exit class-like scope — pop stack and restore previous class context
        if ($this->isClassLikeNode($node)) {
            [$this->currentClass, $this->currentClassStartFilePos] = array_pop($this->classStack) ?? [null, null];
        }

        // Exit namespace scope
        if ($node instanceof Stmt\Namespace_) {
            $this->currentNamespace = null;
        }

        return null;
    }

    /**
     * @param Stmt[] $stmts
     */
    private function analyzeAndStore(string $fqn, array $stmts): void
    {
        [$count, $firstLine] = $this->analyzeStatementList($stmts);
        $this->unreachableCounts[$fqn] = $count;

        if ($firstLine !== null) {
            $this->firstUnreachableLines[$fqn] = $firstLine;
        }
    }

    /**
     * @param Stmt[] $stmts
     *
     * @return array{int, ?int}
     */
    private function analyzeStatementList(array $stmts): array
    {
        $foundTerminal = false;
        $unreachableCount = 0;
        $firstLine = null;

        foreach ($stmts as $stmt) {
            if ($stmt instanceof \PhpParser\Node\Stmt\Nop) {
                continue;
            }

            if ($foundTerminal) {
                // A goto label is a valid jump target — it resets reachability
                if ($stmt instanceof Stmt\Label) {
                    $foundTerminal = false;

                    continue;
                }

                $unreachableCount++;
                $firstLine ??= $stmt->getStartLine();

                continue;
            }

            if ($this->isTerminalStatement($stmt)) {
                $foundTerminal = true;
            }
        }

        return [$unreachableCount, $firstLine];
    }

    private function isTerminalStatement(Stmt $stmt): bool
    {
        // return
        if ($stmt instanceof Stmt\Return_) {
            return true;
        }

        // continue
        if ($stmt instanceof Stmt\Continue_) {
            return true;
        }

        // break
        if ($stmt instanceof Stmt\Break_) {
            return true;
        }

        // goto
        if ($stmt instanceof Stmt\Goto_) {
            return true;
        }

        // throw (Stmt\Expression wrapping Expr\Throw_)
        // exit/die (Stmt\Expression wrapping Expr\Exit_)
        if ($stmt instanceof Stmt\Expression) {
            return $stmt->expr instanceof Expr\Throw_
                || $stmt->expr instanceof Expr\Exit_;
        }

        return false;
    }
}
