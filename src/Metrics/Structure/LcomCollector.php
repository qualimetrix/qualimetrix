<?php

declare(strict_types=1);

namespace AiMessDetector\Metrics\Structure;

use AiMessDetector\Core\Metric\AggregationStrategy;
use AiMessDetector\Core\Metric\ClassMetricsProviderInterface;
use AiMessDetector\Core\Metric\ClassWithMetrics;
use AiMessDetector\Core\Metric\MetricBag;
use AiMessDetector\Core\Metric\MetricDefinition;
use AiMessDetector\Core\Metric\MetricName;
use AiMessDetector\Core\Metric\SymbolLevel;
use AiMessDetector\Metrics\AbstractCollector;
use Override;
use PhpParser\Node;
use SplFileInfo;

/**
 * Collects LCOM4 (Lack of Cohesion of Methods) metric for classes.
 *
 * LCOM4 measures class cohesion by counting connected components in the graph where:
 * - Vertices = instance methods in the class (static methods are excluded)
 * - Edges = (m1, m2) if m1 and m2 share a property OR one calls the other via $this->
 *
 * Interpretation:
 * - LCOM = 1: perfectly cohesive class (all methods share properties)
 * - LCOM > 1: class could potentially be split into LCOM separate classes
 * - LCOM = 0: class has no methods
 *
 * Anonymous classes are ignored.
 */
final class LcomCollector extends AbstractCollector implements ClassMetricsProviderInterface
{
    private const NAME = 'lcom';

    public function __construct()
    {
        $this->visitor = new LcomVisitor();
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
        return [MetricName::STRUCTURE_LCOM];
    }

    /**
     * @param Node[] $ast
     */
    public function collect(SplFileInfo $file, array $ast): MetricBag
    {
        $bag = new MetricBag();

        \assert($this->visitor instanceof LcomVisitor);

        foreach ($this->visitor->getClassData() as $classFqn => $classData) {
            $lcom = $classData->calculateLcom();
            $bag = $bag->with(MetricName::STRUCTURE_LCOM . ':' . $classFqn, $lcom);
        }

        return $bag;
    }

    /**
     * @return list<ClassWithMetrics>
     */
    public function getClassesWithMetrics(): array
    {
        \assert($this->visitor instanceof LcomVisitor);

        $result = [];

        foreach ($this->visitor->getClassData() as $classData) {
            $bag = (new MetricBag())->with(MetricName::STRUCTURE_LCOM, $classData->calculateLcom());

            $result[] = new ClassWithMetrics(
                namespace: $classData->namespace,
                class: $classData->className,
                line: $classData->line,
                metrics: $bag,
            );
        }

        return $result;
    }

    /**
     * @return list<MetricDefinition>
     */
    #[Override]
    public function getMetricDefinitions(): array
    {
        return [
            new MetricDefinition(
                name: MetricName::STRUCTURE_LCOM,
                collectedAt: SymbolLevel::Class_,
                aggregations: [
                    SymbolLevel::Namespace_->value => [
                        AggregationStrategy::Average,
                        AggregationStrategy::Max,
                        AggregationStrategy::Percentile95,
                    ],
                    SymbolLevel::Project->value => [
                        AggregationStrategy::Average,
                        AggregationStrategy::Max,
                        AggregationStrategy::Percentile95,
                    ],
                ],
            ),
        ];
    }
}
