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
 * Collects NPath Complexity metrics for methods and functions.
 *
 * NPath Complexity counts the number of acyclic execution paths through a method.
 * Unlike Cyclomatic Complexity (additive), NPath is multiplicative and grows exponentially.
 *
 * Metric format: npath:{FQN}
 * Example: npath:App\Service\UserService::calculate
 */
final class NpathComplexityCollector extends AbstractCollector implements MethodMetricsProviderInterface
{
    private const NAME = 'npath-complexity';

    public function __construct()
    {
        $this->visitor = new NpathComplexityVisitor();
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
        return [MetricName::COMPLEXITY_NPATH];
    }

    /**
     * @param Node[] $ast
     */
    public function collect(SplFileInfo $file, array $ast): MetricBag
    {
        $bag = new MetricBag();

        \assert($this->visitor instanceof NpathComplexityVisitor);

        foreach ($this->visitor->getNpath() as $fqn => $npath) {
            $bag = $bag->with(MetricName::COMPLEXITY_NPATH . ':' . $fqn, $npath);
        }

        return $bag;
    }

    /**
     * @return list<MethodWithMetrics>
     */
    public function getMethodsWithMetrics(): array
    {
        \assert($this->visitor instanceof NpathComplexityVisitor);

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
                name: MetricName::COMPLEXITY_NPATH,
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
