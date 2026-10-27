<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Evidence\CodeSmell\ParameterCount;

use PhpParser\Node;
use PhpParser\Node\Stmt\ClassMethod;
use PhpParser\NodeVisitorAbstract;
use Qualimetrix\Analysis\Evidence\Measurement\Contract\CallableWithMetrics;
use Qualimetrix\Analysis\Evidence\Measurement\Contract\DeclarationIndexAwareInterface;
use Qualimetrix\Analysis\Evidence\Measurement\Contract\MetricBag;
use Qualimetrix\Analysis\Evidence\Measurement\Contract\MetricName;
use Qualimetrix\Analysis\Evidence\Measurement\Contract\ResettableVisitorInterface;
use Qualimetrix\Analysis\Evidence\Measurement\Contract\VisitorCallableScope;
use Qualimetrix\Analysis\Evidence\Measurement\Contract\VisitorMethodTrackingTrait;
use Qualimetrix\Core\Path\RelativePath;

/**
 * Visitor for counting method/function parameters.
 *
 * Counts the parameters of every callable scope the method context opens: methods, functions,
 * closures and arrow functions. Which of those a rule judges is the rule's decision.
 */
final class ParameterCountVisitor extends NodeVisitorAbstract implements DeclarationIndexAwareInterface, ResettableVisitorInterface
{
    use VisitorMethodTrackingTrait;

    /** @var array<string, int> Method/function FQN => parameter count */
    private array $parameterCounts = [];

    /** @var array<string, bool> Method/function FQN => is VO constructor */
    private array $voConstructors = [];

    /** @var array<string, true> Method FQN => declared as a constructor in a class-like */
    private array $constructors = [];

    /** @var array<string, VisitorCallableScope> */
    private array $scopes = [];

    private ValueObjectClassification $valueObjects;

    public function __construct()
    {
        $this->valueObjects = new ValueObjectClassification();
    }

    public function reset(): void
    {
        $this->parameterCounts = [];
        $this->voConstructors = [];
        $this->constructors = [];
        $this->scopes = [];
        $this->resetVisitorMethodContext();
        $this->valueObjects->reset();
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
     * See {@see ValueObjectClassification} for the criteria.
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
     * Returns FQNs of constructors that are not value-object constructors: the ones whose parameters
     * are injected dependencies, judged by `code-smell.constructor-overinjection`.
     *
     * @return array<string, bool>
     */
    public function getInjectionConstructors(): array
    {
        /** @var array<string, bool> $projected */
        $projected = $this->projectLogicalMetricMap($this->injectionConstructorKeys(), $this->scopes);

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
        $injectionConstructors = $this->injectionConstructorKeys();

        foreach ($this->scopes as $fqn => $scope) {
            $metrics = (new MetricBag())->with(MetricName::CODE_SMELL_PARAMETER_COUNT, $this->parameterCounts[$fqn] ?? 0);

            if (isset($this->voConstructors[$fqn])) {
                $metrics = $metrics->with(MetricName::CODE_SMELL_IS_VO_CONSTRUCTOR, 1);
            }

            if (isset($injectionConstructors[$fqn])) {
                $metrics = $metrics->with(MetricName::CODE_SMELL_IS_INJECTION_CONSTRUCTOR, 1);
            }

            $result[] = $this->createCallableWithMetrics($scope, $file, $metrics);
        }

        return $result;
    }

    public function enterNode(Node $node): ?int
    {
        $this->valueObjects->enter($node);
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

        if ($node instanceof ClassMethod && strtolower($scope->member) === '__construct') {
            $this->constructors[$fqn] = true;
            $this->valueObjects->proposeConstructor($fqn, $node);
        }

        return null;
    }

    public function leaveNode(Node $node): ?int
    {
        $this->leaveVisitorMethodContext($node);
        $voConstructor = $this->valueObjects->leave($node);
        if ($voConstructor !== null) {
            $this->voConstructors[$voConstructor] = true;
        }

        return null;
    }

    /**
     * @return array<string, true>
     */
    private function injectionConstructorKeys(): array
    {
        return array_diff_key($this->constructors, $this->voConstructors);
    }
}
