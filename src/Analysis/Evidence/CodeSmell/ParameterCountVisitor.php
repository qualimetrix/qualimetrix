<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Evidence\CodeSmell;

use PhpParser\Node;
use PhpParser\Node\Stmt\ClassMethod;
use PhpParser\NodeVisitorAbstract;
use Qualimetrix\Analysis\Evidence\Measurement\Contract\CallableWithMetrics;
use Qualimetrix\Analysis\Evidence\Measurement\Contract\MetricBag;
use Qualimetrix\Analysis\Evidence\Measurement\Contract\MetricName;
use Qualimetrix\Analysis\Evidence\Measurement\Contract\ResettableVisitorInterface;
use Qualimetrix\Analysis\Evidence\Measurement\Contract\VisitorCallableScope;
use Qualimetrix\Analysis\Evidence\Measurement\Contract\VisitorMethodTrackingTrait;
use Qualimetrix\Core\Path\RelativePath;

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

    /** @var array<string, VisitorCallableScope> */
    private array $scopes = [];

    /** @var list<bool> Stack of readonly flags matching classStack */
    private array $readonlyStack = [];

    public function reset(): void
    {
        $this->parameterCounts = [];
        $this->voConstructors = [];
        $this->scopes = [];
        $this->resetVisitorMethodContext();
        $this->readonlyStack = [];
    }

    /**
     * @return array<string, int>
     */
    public function getParameterCounts(): array
    {
        /** @var array<string, int> $projected */
        $projected = $this->projectLogicalMetricMap($this->parameterCounts, $this->scopes);

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
        $projected = $this->projectLogicalMetricMap($this->voConstructors, $this->scopes);

        return $projected;
    }

    /**
     * @return array<string, VisitorCallableScope>
     */
    public function getMethodInfos(): array
    {
        return $this->scopes;
    }

    /**
     * Returns structured method metrics for each analyzed method.
     *
     * @return list<CallableWithMetrics>
     */
    public function getCallablesWithMetrics(RelativePath $file): array
    {
        $result = [];

        $ordinals = $this->callableCollisionOrdinals($this->scopes);
        foreach ($this->scopes as $fqn => $scope) {
            $metrics = (new MetricBag())->with(MetricName::CODE_SMELL_PARAMETER_COUNT, $this->parameterCounts[$fqn] ?? 0);

            if (isset($this->voConstructors[$fqn])) {
                $metrics = $metrics->with(MetricName::CODE_SMELL_IS_VO_CONSTRUCTOR, 1);
            }

            $result[] = $this->createCallableWithMetrics($scope, $file, $metrics, $ordinals[$fqn]);
        }

        return $result;
    }

    public function enterNode(Node $node): ?int
    {
        if ($node instanceof Node\Stmt\ClassLike) {
            $this->readonlyStack[] = $node instanceof Node\Stmt\Class_ && $node->isReadonly();
        }
        $scope = $this->enterVisitorMethodContext($node);
        if ($scope === null) {
            return null;
        }
        if (!$node instanceof Node\FunctionLike) {
            return null;
        }

        $fqn = $scope->traversalKey;
        $this->scopes[$fqn] = $scope;
        $this->parameterCounts[$fqn] = \count($node->getParams());

        if ($node instanceof ClassMethod
            && $scope->member === '__construct'
            && $this->isCurrentClassReadonly()
            && $this->isVoConstructor($node)
        ) {
            $this->voConstructors[$fqn] = true;
        }

        return null;
    }

    public function leaveNode(Node $node): ?int
    {
        $this->leaveVisitorMethodContext($node);
        if ($node instanceof Node\Stmt\ClassLike) {
            array_pop($this->readonlyStack);
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
