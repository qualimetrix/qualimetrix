<?php

declare(strict_types=1);

namespace Qualimetrix\Metrics\CodeSmell;

use PhpParser\Node;
use PhpParser\Node\Stmt\ClassMethod;
use PhpParser\Node\Stmt\Function_;
use PhpParser\NodeVisitorAbstract;
use Qualimetrix\Core\Metric\MethodWithMetrics;
use Qualimetrix\Core\Metric\MetricBag;
use Qualimetrix\Metrics\ResettableVisitorInterface;
use Qualimetrix\Metrics\VisitorMethodTrackingTrait;

/**
 * Visitor for counting method/function parameters.
 *
 * Counts the number of parameters for each method and function.
 * Closures are intentionally skipped as they don't have meaningful
 * SymbolPath for method-level metrics.
 */
final class ParameterCountVisitor extends NodeVisitorAbstract implements ResettableVisitorInterface
{
    use VisitorMethodTrackingTrait;

    /** @var array<string, int> Method/function FQN => parameter count */
    private array $parameterCounts = [];

    /** @var array<string, array{namespace: ?string, class: ?string, method: string, line: int}> FQN => method info */
    private array $methodInfos = [];

    private ?string $currentNamespace = null;
    private ?string $currentClass = null;
    /** @phpstan-ignore property.onlyWritten (required by VisitorMethodTrackingTrait) */
    private int $closureCounter = 0;

    /** @var list<string|null> Stack of class names for nested class-like scopes */
    private array $classStack = [];

    /** @var int Depth of anonymous class nesting (methods inside anonymous classes are skipped) */
    private int $anonymousClassDepth = 0;

    public function reset(): void
    {
        $this->parameterCounts = [];
        $this->methodInfos = [];
        $this->currentNamespace = null;
        $this->currentClass = null;
        $this->closureCounter = 0;
        $this->classStack = [];
        $this->anonymousClassDepth = 0;
    }

    /**
     * @return array<string, int>
     */
    public function getParameterCounts(): array
    {
        return $this->parameterCounts;
    }

    /**
     * @return array<string, array{namespace: ?string, class: ?string, method: string, line: int}>
     */
    public function getMethodInfos(): array
    {
        return $this->methodInfos;
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
            $metrics = (new MetricBag())->with('parameterCount', $this->parameterCounts[$fqn] ?? 0);

            $result[] = new MethodWithMetrics(
                namespace: $info['namespace'],
                class: $info['class'],
                method: $info['method'],
                line: $info['line'],
                metrics: $metrics,
            );
        }

        return $result;
    }

    public function enterNode(Node $node): ?int
    {
        // Track namespace
        if ($node instanceof Node\Stmt\Namespace_) {
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
            if ($node instanceof Node\Stmt\Class_ && $node->name === null) {
                ++$this->anonymousClassDepth;
            }
        }

        // Class method (skip if inside anonymous class)
        if ($node instanceof ClassMethod) {
            if ($this->anonymousClassDepth === 0) {
                $fqn = $this->buildMethodFqn($node->name->toString());
                $this->parameterCounts[$fqn] = \count($node->params);
                $this->methodInfos[$fqn] = [
                    'namespace' => $this->currentNamespace,
                    'class' => $this->currentClass,
                    'method' => $node->name->toString(),
                    'line' => $node->getStartLine(),
                ];
            }

            return null;
        }

        // Global function
        if ($node instanceof Function_) {
            $fqn = $this->buildFunctionFqn($node->name->toString());
            $this->parameterCounts[$fqn] = \count($node->params);
            $this->methodInfos[$fqn] = [
                'namespace' => $this->currentNamespace,
                'class' => null,
                'method' => $node->name->toString(),
                'line' => $node->getStartLine(),
            ];

            return null;
        }

        return null;
    }

    public function leaveNode(Node $node): ?int
    {
        // Exit class-like scope — pop stack and restore previous class context
        if ($this->isClassLikeNode($node)) {
            if ($node instanceof Node\Stmt\Class_ && $node->name === null) {
                --$this->anonymousClassDepth;
            }
            array_pop($this->classStack);
            $this->currentClass = $this->classStack !== [] ? $this->classStack[array_key_last($this->classStack)] : null;
        }

        // Exit namespace scope
        if ($node instanceof Node\Stmt\Namespace_) {
            $this->currentNamespace = null;
        }

        return null;
    }
}
