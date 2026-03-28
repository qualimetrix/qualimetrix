<?php

declare(strict_types=1);

namespace Qualimetrix\Metrics\Structure;

use Override;
use PhpParser\Node;
use Qualimetrix\Core\Metric\AggregationStrategy;
use Qualimetrix\Core\Metric\ClassMetricsProviderInterface;
use Qualimetrix\Core\Metric\ClassWithMetrics;
use Qualimetrix\Core\Metric\MetricBag;
use Qualimetrix\Core\Metric\MetricDefinition;
use Qualimetrix\Core\Metric\MetricName;
use Qualimetrix\Core\Metric\SymbolLevel;
use Qualimetrix\Metrics\AbstractCollector;
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
            $lcom = $this->adjustedLcom($classData);

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
            $lcom = $this->adjustedLcom($classData);

            $bag = (new MetricBag())->with(MetricName::STRUCTURE_LCOM, $lcom);

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
     * Returns LCOM adjusted for trivial classes (null objects, stubs).
     *
     * Classes where ALL methods are trivial (empty, return constant/null)
     * get LCOM=1 instead of the calculated value, since they lack cohesion
     * by design, not by poor structure.
     */
    private function adjustedLcom(LcomClassData $classData): int
    {
        $lcom = $classData->calculateLcom();

        return ($lcom > 1 && $classData->hasOnlyTrivialMethods()) ? 1 : $lcom;
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
