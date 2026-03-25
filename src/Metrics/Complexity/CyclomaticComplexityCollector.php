<?php

declare(strict_types=1);

namespace Qualimetrix\Metrics\Complexity;

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
 * Collects Cyclomatic Complexity metrics for methods and functions.
 *
 * Metric format: ccn:{FQN}
 * Example: ccn:App\Service\UserService::calculate
 */
final class CyclomaticComplexityCollector extends AbstractCollector implements MethodMetricsProviderInterface
{
    private const NAME = 'cyclomatic-complexity';

    public function __construct()
    {
        $this->visitor = new CyclomaticComplexityVisitor();
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
        return [MetricName::COMPLEXITY_CCN];
    }

    /**
     * @param Node[] $ast
     */
    public function collect(SplFileInfo $file, array $ast): MetricBag
    {
        $bag = new MetricBag();

        \assert($this->visitor instanceof CyclomaticComplexityVisitor);

        foreach ($this->visitor->getComplexities() as $fqn => $complexity) {
            $bag = $bag->with(MetricName::COMPLEXITY_CCN . ':' . $fqn, $complexity);
        }

        return $bag;
    }

    /**
     * @return list<MethodWithMetrics>
     */
    public function getMethodsWithMetrics(): array
    {
        \assert($this->visitor instanceof CyclomaticComplexityVisitor);

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
                name: MetricName::COMPLEXITY_CCN,
                collectedAt: SymbolLevel::Method,
                aggregations: [
                    SymbolLevel::Class_->value => [
                        AggregationStrategy::Sum,
                        AggregationStrategy::Average,
                        AggregationStrategy::Max,
                    ],
                    SymbolLevel::Namespace_->value => [
                        AggregationStrategy::Sum,
                        AggregationStrategy::Average,
                        AggregationStrategy::Max,
                        AggregationStrategy::Percentile95,
                    ],
                    SymbolLevel::Project->value => [
                        AggregationStrategy::Sum,
                        AggregationStrategy::Average,
                        AggregationStrategy::Max,
                        AggregationStrategy::Percentile95,
                    ],
                ],
            ),
        ];
    }
}
