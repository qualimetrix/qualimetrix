<?php

declare(strict_types=1);

namespace AiMessDetector\Metrics\CodeSmell;

use AiMessDetector\Core\Metric\MethodWithMetrics;
use AiMessDetector\Core\Metric\MetricBag;
use AiMessDetector\Metrics\ResettableVisitorInterface;
use AiMessDetector\Metrics\VisitorMethodTrackingTrait;
use PhpParser\Node;
use PhpParser\Node\Expr;
use PhpParser\Node\Stmt;
use PhpParser\Node\Stmt\ClassMethod;
use PhpParser\Node\Stmt\Function_;
use PhpParser\NodeVisitorAbstract;

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

    /** @var array<string, array{namespace: ?string, class: ?string, method: string, line: int}> FQN => method info */
    private array $methodInfos = [];

    private ?string $currentNamespace = null;
    private ?string $currentClass = null;
    /** @phpstan-ignore property.onlyWritten (required by VisitorMethodTrackingTrait) */
    private int $closureCounter = 0;

    /** @var list<string|null> Stack of class names for nested class-like scopes */
    private array $classStack = [];

    public function reset(): void
    {
        $this->unreachableCounts = [];
        $this->firstUnreachableLines = [];
        $this->methodInfos = [];
        $this->currentNamespace = null;
        $this->currentClass = null;
        $this->closureCounter = 0;
        $this->classStack = [];
    }

    /**
     * @return array<string, int>
     */
    public function getUnreachableCounts(): array
    {
        return $this->unreachableCounts;
    }

    /**
     * @return array<string, int>
     */
    public function getFirstUnreachableLines(): array
    {
        return $this->firstUnreachableLines;
    }

    /**
     * Returns structured method metrics for each analyzed method.
     *
     * @return list<MethodWithMetrics>
     */
    public function getMethodsWithMetrics(): array
    {
        $result = [];

        foreach ($this->methodInfos as $fqn => $info) {
            $bag = (new MetricBag())->with('unreachableCode', $this->unreachableCounts[$fqn] ?? 0);

            if (isset($this->firstUnreachableLines[$fqn])) {
                $bag = $bag->with('unreachableCode.firstLine', $this->firstUnreachableLines[$fqn]);
            }

            $result[] = new MethodWithMetrics(
                namespace: $info['namespace'],
                class: $info['class'],
                method: $info['method'],
                line: $info['line'],
                metrics: $bag,
            );
        }

        return $result;
    }

    public function enterNode(Node $node): ?int
    {
        // Track namespace
        if ($node instanceof Stmt\Namespace_) {
            $this->currentNamespace = $node->name?->toString() ?? '';
        }

        // Track class-like types with stack for nested anonymous classes
        $className = $this->extractClassLikeName($node);
        if ($className !== null) {
            $this->currentClass = $className;
            $this->classStack[] = $className;
        } elseif ($this->isClassLikeNode($node)) {
            // Anonymous class — push null to track scope depth
            $this->classStack[] = null;
        }

        // Class method
        if ($node instanceof ClassMethod) {
            $fqn = $this->buildMethodFqn($node->name->toString());
            $this->methodInfos[$fqn] = [
                'namespace' => $this->currentNamespace,
                'class' => $this->currentClass,
                'method' => $node->name->toString(),
                'line' => $node->getStartLine(),
            ];
            $this->analyzeAndStore($fqn, $node->stmts ?? []);

            return null;
        }

        // Global function
        if ($node instanceof Function_) {
            $fqn = $this->buildFunctionFqn($node->name->toString());
            $this->methodInfos[$fqn] = [
                'namespace' => $this->currentNamespace,
                'class' => null,
                'method' => $node->name->toString(),
                'line' => $node->getStartLine(),
            ];
            $this->analyzeAndStore($fqn, $node->stmts ?? []);

            return null;
        }

        return null;
    }

    public function leaveNode(Node $node): ?int
    {
        // Exit class-like scope — pop stack and restore previous class context
        if ($this->isClassLikeNode($node)) {
            array_pop($this->classStack);
            $this->currentClass = $this->classStack !== [] ? $this->classStack[array_key_last($this->classStack)] : null;
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
            if ($foundTerminal) {
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

        // throw (Stmt\Expression wrapping Expr\Throw_)
        // exit/die (Stmt\Expression wrapping Expr\Exit_)
        if ($stmt instanceof Stmt\Expression) {
            return $stmt->expr instanceof Expr\Throw_
                || $stmt->expr instanceof Expr\Exit_;
        }

        return false;
    }
}
