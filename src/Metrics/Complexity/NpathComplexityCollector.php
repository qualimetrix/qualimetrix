<?php

declare(strict_types=1);

namespace AiMessDetector\Metrics\Complexity;

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
    private const METRIC_NPATH = 'npath';

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
        return [self::METRIC_NPATH];
    }

    /**
     * @param Node[] $ast
     */
    public function collect(SplFileInfo $file, array $ast): MetricBag
    {
        $bag = new MetricBag();

        \assert($this->visitor instanceof NpathComplexityVisitor);

        foreach ($this->visitor->getNpath() as $fqn => $npath) {
            $bag = $bag->with(self::METRIC_NPATH . ':' . $fqn, $npath);
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
                name: self::METRIC_NPATH,
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
