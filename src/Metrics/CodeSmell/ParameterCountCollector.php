<?php

declare(strict_types=1);

namespace Qualimetrix\Metrics\CodeSmell;

use Override;
use PhpParser\Node;
use Qualimetrix\Core\Metric\AggregationStrategy;
use Qualimetrix\Core\Metric\MethodMetricsProviderInterface;
use Qualimetrix\Core\Metric\MethodWithMetrics;
use Qualimetrix\Core\Metric\MetricBag;
use Qualimetrix\Core\Metric\MetricDefinition;
use Qualimetrix\Core\Metric\MetricName;
use Qualimetrix\Core\Metric\SymbolLevel;
use Qualimetrix\Metrics\AbstractCollector;
use SplFileInfo;

/**
 * Collects parameter count metrics for methods and functions.
 *
 * Metric format: parameterCount:{FQN}
 * Example: parameterCount:App\Service\UserService::calculate
 */
final class ParameterCountCollector extends AbstractCollector implements MethodMetricsProviderInterface
{
    private const NAME = 'parameter-count';

    public function __construct()
    {
        $this->visitor = new ParameterCountVisitor();
    }

    public function getName(): string
    {
        return self::NAME;
    }

    /**
     * @return list<string>
     */
    public function provides(): array
    {
        return [MetricName::CODE_SMELL_PARAMETER_COUNT];
    }

    /**
     * @param Node[] $ast
     */
    public function collect(SplFileInfo $file, array $ast): MetricBag
    {
        $bag = new MetricBag();

        \assert($this->visitor instanceof ParameterCountVisitor);

        foreach ($this->visitor->getParameterCounts() as $fqn => $count) {
            $bag = $bag->with(MetricName::CODE_SMELL_PARAMETER_COUNT . ':' . $fqn, $count);
        }

        return $bag;
    }

    /**
     * @return list<MethodWithMetrics>
     */
    public function getMethodsWithMetrics(): array
    {
        \assert($this->visitor instanceof ParameterCountVisitor);

        return $this->visitor->getMethodsWithMetrics();
    }

    /**
     * @return list<MetricDefinition>
     */
    #[Override]
    public function getMetricDefinitions(): array
    {
        return [
            new MetricDefinition(
                name: MetricName::CODE_SMELL_PARAMETER_COUNT,
                collectedAt: SymbolLevel::Method,
                aggregations: [
                    SymbolLevel::Class_->value => [
                        AggregationStrategy::Max,
                        AggregationStrategy::Average,
                    ],
                    SymbolLevel::Namespace_->value => [
                        AggregationStrategy::Max,
                        AggregationStrategy::Average,
                        AggregationStrategy::Percentile95,
                    ],
                    SymbolLevel::Project->value => [
                        AggregationStrategy::Max,
                        AggregationStrategy::Average,
                        AggregationStrategy::Percentile95,
                    ],
                ],
            ),
        ];
    }
}
