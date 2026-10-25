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

        foreach ($this->scopes as $fqn => $scope) {
            $metrics = (new MetricBag())->with(MetricName::CODE_SMELL_PARAMETER_COUNT, $this->parameterCounts[$fqn] ?? 0);

            if (isset($this->voConstructors[$fqn])) {
                $metrics = $metrics->with(MetricName::CODE_SMELL_IS_VO_CONSTRUCTOR, 1);
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
}
