<?php

declare(strict_types=1);

namespace AiMessDetector\Metrics\CodeSmell;

use AiMessDetector\Core\Metric\AggregationStrategy;
use AiMessDetector\Core\Metric\MethodMetricsProviderInterface;
use AiMessDetector\Core\Metric\MethodWithMetrics;
use AiMessDetector\Core\Metric\MetricBag;
use AiMessDetector\Core\Metric\MetricDefinition;
use AiMessDetector\Core\Metric\SymbolLevel;
use AiMessDetector\Metrics\AbstractCollector;
use Override;
use PhpParser\Node;
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
    private const METRIC = 'parameterCount';

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
        return [self::METRIC];
    }

    /**
     * @param Node[] $ast
     */
    public function collect(SplFileInfo $file, array $ast): MetricBag
    {
        $bag = new MetricBag();

        \assert($this->visitor instanceof ParameterCountVisitor);

        foreach ($this->visitor->getParameterCounts() as $fqn => $count) {
            $bag = $bag->with(self::METRIC . ':' . $fqn, $count);
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
                name: self::METRIC,
                collectedAt: SymbolLevel::Method,
                aggregations: [
                    SymbolLevel::Class_->value => [
                        AggregationStrategy::Max,
                        AggregationStrategy::Average,
                    ],
                    SymbolLevel::Namespace_->value => [
                        AggregationStrategy::Max,
                        AggregationStrategy::Average,
                    ],
                    SymbolLevel::Project->value => [
                        AggregationStrategy::Max,
                        AggregationStrategy::Average,
                    ],
                ],
            ),
        ];
    }
}
