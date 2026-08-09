<?php

declare(strict_types=1);

namespace Qualimetrix\Metrics\CodeSmell;

use PhpParser\Node;
use PhpParser\Node\Expr\ArrowFunction;
use PhpParser\Node\Expr\Closure;
use PhpParser\Node\PropertyHook;
use PhpParser\Node\Stmt\ClassMethod;
use PhpParser\Node\Stmt\Function_;
use PhpParser\NodeVisitorAbstract;
use Qualimetrix\Core\Metric\CallableWithMetrics;
use Qualimetrix\Core\Metric\MetricBag;
use Qualimetrix\Core\Metric\MetricName;
use Qualimetrix\Core\Path\RelativePath;
use Qualimetrix\Core\Symbol\CallableKind;
use Qualimetrix\Metrics\ResettableVisitorInterface;
use Qualimetrix\Metrics\VisitorMethodTrackingTrait;

/**
 * Visitor for counting method/function parameters.
 *
 * Counts the number of parameters for each method and function.
 * Closures are intentionally skipped as they don't have meaningful
 * SymbolPath for callable-level metrics.
 */
final class ParameterCountVisitor extends NodeVisitorAbstract implements ResettableVisitorInterface
{
    use VisitorMethodTrackingTrait;

    /** @var array<string, int> Method/function FQN => parameter count */
    private array $parameterCounts = [];

    /** @var array<string, bool> Method/function FQN => is VO constructor */
    private array $voConstructors = [];

    /** @var array<string, array{logicalFqn: string, namespace: ?string, class: ?string, method: string, startFilePos: int, sourceLine: int, kind: CallableKind, anonymousSyntax: ?string, classStartFilePos: ?int}> traversal key => callable info */
    private array $methodInfos = [];

    private ?string $currentNamespace = null;
    private ?string $currentClass = null;
    private ?int $currentClassStartFilePos = null;
    private int $closureCounter = 0;
    private ?string $currentProperty = null;

    /** @var list<array{?string, ?int}> Class context before each nested class-like scope */
    private array $classStack = [];

    /** @var list<bool> Stack of readonly flags matching classStack */
    private array $readonlyStack = [];

    public function reset(): void
    {
        $this->parameterCounts = [];
        $this->voConstructors = [];
        $this->methodInfos = [];
        $this->currentNamespace = null;
        $this->currentClass = null;
        $this->currentClassStartFilePos = null;
        $this->closureCounter = 0;
        $this->currentProperty = null;
        $this->resetCallableTraversalKeys();
        $this->classStack = [];
        $this->readonlyStack = [];
    }

    /**
     * @return array<string, int>
     */
    public function getParameterCounts(): array
    {
        /** @var array<string, int> $projected */
        $projected = $this->projectLogicalMetricMap($this->parameterCounts, $this->methodInfos);

        return $projected;
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
        /** @var array<string, bool> $projected */
        $projected = $this->projectLogicalMetricMap($this->voConstructors, $this->methodInfos);

        return $projected;
    }

    /**
     * @return array<string, array{logicalFqn: string, namespace: ?string, class: ?string, method: string, startFilePos: int, kind: CallableKind, anonymousSyntax: ?string, classStartFilePos: ?int}>
     */
    public function getMethodInfos(): array
    {
        return $this->methodInfos;
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
            $metrics = (new MetricBag())->with(MetricName::CODE_SMELL_PARAMETER_COUNT, $this->parameterCounts[$fqn] ?? 0);

            if (isset($this->voConstructors[$fqn])) {
                $metrics = $metrics->with(MetricName::CODE_SMELL_IS_VO_CONSTRUCTOR, 1);
            }

            $result[] = $this->createCallableWithMetrics($info, $file, $metrics, $ordinals[$fqn]);
        }

        return $result;
    }

    public function enterNode(Node $node): ?int
    {
        // Track namespace
        if ($node instanceof Node\Stmt\Namespace_) {
            $this->currentNamespace = $node->name?->toString() ?? '';
        }

        // Track class-like types, including anonymous declarations.
        $className = $this->extractClassLikeName($node);
        if ($this->isClassLikeNode($node)) {
            $this->classStack[] = [$this->currentClass, $this->currentClassStartFilePos];
            $this->readonlyStack[] = $node instanceof Node\Stmt\Class_ && $node->isReadonly();
        }
        if ($className !== null) {
            $this->currentClass = $className;
            $this->currentClassStartFilePos = $node->getStartFilePos();
        } elseif ($node instanceof Node\Stmt\Class_ && $node->name === null) {
            $this->currentClass = $this->buildAnonymousClassName($node->getStartFilePos());
            $this->currentClassStartFilePos = $node->getStartFilePos();
        }

        // Class method
        if ($node instanceof ClassMethod) {
            $fqn = $this->buildMethodFqn($node->name->toString());
            $fqn = $this->createCallableTraversalKey($fqn, $node->getStartFilePos());
            $this->parameterCounts[$fqn] = \count($node->params);
            $this->methodInfos[$fqn] = [
                'logicalFqn' => $this->buildMethodFqn($node->name->toString()),
                'namespace' => $this->currentNamespace,
                'class' => $this->currentClass,
                'method' => $node->name->toString(),
                'startFilePos' => $node->getStartFilePos(),
                'sourceLine' => $node->getStartLine(),
                'kind' => CallableKind::Method,
                'anonymousSyntax' => null,
                'classStartFilePos' => $this->currentClassStartFilePos,
            ];

            // Detect VO constructor: readonly class + __construct + all promoted + empty body
            if ($node->name->toString() === '__construct' && $this->isCurrentClassReadonly()) {
                if ($this->isVoConstructor($node)) {
                    $this->voConstructors[$fqn] = true;
                }
            }

            return null;
        }

        if ($node instanceof Node\Stmt\Property && $this->currentClass !== null && \count($node->props) === 1) {
            $this->currentProperty = $node->props[0]->name->toString();
        }

        if ($node instanceof PropertyHook && $this->currentClass !== null && $this->currentProperty !== null) {
            $name = $this->currentProperty . '::' . $node->name->toString();
            $fqn = $this->buildMethodFqn($name);
            $fqn = $this->createCallableTraversalKey($fqn, $node->getStartFilePos());
            $this->parameterCounts[$fqn] = \count($node->params);
            $this->methodInfos[$fqn] = [
                'logicalFqn' => $this->buildMethodFqn($name),
                'namespace' => $this->currentNamespace,
                'class' => $this->currentClass,
                'method' => $name,
                'startFilePos' => $node->getStartFilePos(),
                'sourceLine' => $node->getStartLine(),
                'kind' => CallableKind::PropertyHook,
                'anonymousSyntax' => null,
                'classStartFilePos' => $this->currentClassStartFilePos,
            ];

            return null;
        }

        // Global function
        if ($node instanceof Function_) {
            $fqn = $this->buildFunctionFqn($node->name->toString());
            $fqn = $this->createCallableTraversalKey($fqn, $node->getStartFilePos());
            $this->parameterCounts[$fqn] = \count($node->params);
            $this->methodInfos[$fqn] = [
                'logicalFqn' => $this->buildFunctionFqn($node->name->toString()),
                'namespace' => $this->currentNamespace,
                'class' => null,
                'method' => $node->name->toString(),
                'startFilePos' => $node->getStartFilePos(),
                'sourceLine' => $node->getStartLine(),
                'kind' => CallableKind::Function,
                'anonymousSyntax' => null,
                'classStartFilePos' => null,
            ];

            return null;
        }

        if ($node instanceof Closure || $node instanceof ArrowFunction) {
            ++$this->closureCounter;
            $name = '{closure#' . $this->closureCounter . '}';
            $fqn = $this->buildClosureFqn();
            $fqn = $this->createCallableTraversalKey($fqn, $node->getStartFilePos());
            $this->parameterCounts[$fqn] = \count($node->params);
            $this->methodInfos[$fqn] = [
                'logicalFqn' => $this->buildClosureFqn(),
                'namespace' => $this->currentNamespace,
                'class' => $this->currentClass,
                'method' => $name,
                'startFilePos' => $node->getStartFilePos(),
                'sourceLine' => $node->getStartLine(),
                'kind' => CallableKind::AnonymousCallable,
                'anonymousSyntax' => $node instanceof Closure ? 'closure' : 'arrow',
                'classStartFilePos' => $this->currentClassStartFilePos,
            ];

            return null;
        }

        return null;
    }

    public function leaveNode(Node $node): ?int
    {
        if ($node instanceof Node\Stmt\Property) {
            $this->currentProperty = null;
        }

        // Exit class-like scope — pop stack and restore previous class context
        if ($this->isClassLikeNode($node)) {
            [$this->currentClass, $this->currentClassStartFilePos] = array_pop($this->classStack) ?? [null, null];
            array_pop($this->readonlyStack);
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
