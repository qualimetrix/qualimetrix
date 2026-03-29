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

    /** @var array<string, bool> Method/function FQN => is VO constructor */
    private array $voConstructors = [];

    /** @var array<string, array{namespace: ?string, class: ?string, method: string, line: int}> FQN => method info */
    private array $methodInfos = [];

    private ?string $currentNamespace = null;
    private ?string $currentClass = null;
    /** @phpstan-ignore property.onlyWritten (required by VisitorMethodTrackingTrait) */
    private int $closureCounter = 0;

    /** @var list<string|null> Stack of class names for nested class-like scopes */
    private array $classStack = [];

    /** @var list<bool> Stack of readonly flags matching classStack */
    private array $readonlyStack = [];

    /** @var int Depth of anonymous class nesting (methods inside anonymous classes are skipped) */
    private int $anonymousClassDepth = 0;

    public function reset(): void
    {
        $this->parameterCounts = [];
        $this->voConstructors = [];
        $this->methodInfos = [];
        $this->currentNamespace = null;
        $this->currentClass = null;
        $this->closureCounter = 0;
        $this->classStack = [];
        $this->readonlyStack = [];
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
     * Returns FQNs of methods detected as VO constructors.
     *
     * A VO constructor is a __construct in a readonly class where all parameters
     * are promoted properties and the body is empty (no statements).
     *
     * @return array<string, bool>
     */
    public function getVoConstructors(): array
    {
        return $this->voConstructors;
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
            $this->readonlyStack[] = $node instanceof Node\Stmt\Class_ && $node->isReadonly();
        } elseif ($this->isClassLikeNode($node)) {
            // Anonymous class — push null to track scope depth
            $this->classStack[] = null;
            $this->readonlyStack[] = false;
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

                // Detect VO constructor: readonly class + __construct + all promoted + empty body
                if ($node->name->toString() === '__construct' && $this->isCurrentClassReadonly()) {
                    if ($this->isVoConstructor($node)) {
                        $this->voConstructors[$fqn] = true;
                    }
                }
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
            array_pop($this->readonlyStack);
            $this->currentClass = $this->classStack !== [] ? $this->classStack[array_key_last($this->classStack)] : null;
        }

        // Exit namespace scope
        if ($node instanceof Node\Stmt\Namespace_) {
            $this->currentNamespace = null;
        }

        return null;
    }

    /**
     * Checks if the current class scope has the readonly modifier.
     */
    private function isCurrentClassReadonly(): bool
    {
        return $this->readonlyStack !== [] && $this->readonlyStack[array_key_last($this->readonlyStack)];
    }

    /**
     * Detects a VO constructor: all parameters must be promoted properties and body must be empty.
     *
     * Promoted parameters have a visibility modifier (public/protected/private).
     * Empty body means no statements (property promotion is not a statement).
     */
    private function isVoConstructor(ClassMethod $node): bool
    {
        // Must have at least one parameter to be a meaningful VO constructor
        if ($node->params === []) {
            return false;
        }

        // All parameters must be promoted (have visibility flags)
        foreach ($node->params as $param) {
            if ($param->flags === 0) {
                return false;
            }
        }

        // Body must be empty or absent (no statements)
        return $node->stmts === null || $node->stmts === [];
    }
}
