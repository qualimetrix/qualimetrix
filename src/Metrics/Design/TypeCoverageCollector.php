<?php

declare(strict_types=1);

namespace AiMessDetector\Metrics\Design;

use AiMessDetector\Core\Metric\AggregationStrategy;
use AiMessDetector\Core\Metric\ClassMetricsProviderInterface;
use AiMessDetector\Core\Metric\ClassWithMetrics;
use AiMessDetector\Core\Metric\MetricBag;
use AiMessDetector\Core\Metric\MetricDefinition;
use AiMessDetector\Core\Metric\SymbolLevel;
use AiMessDetector\Metrics\AbstractCollector;
use Override;
use PhpParser\Node;
use SplFileInfo;

/**
 * Collects type coverage metrics for classes.
 *
 * Metrics per class:
 * - typeCoverage.paramTotal: total number of parameters
 * - typeCoverage.paramTyped: number of typed parameters
 * - typeCoverage.param: percentage of typed parameters (0-100)
 * - typeCoverage.returnTotal: total number of methods (excluding __construct, __destruct, __clone)
 * - typeCoverage.returnTyped: number of methods with return type declarations
 * - typeCoverage.return: percentage of methods with return types (0-100)
 * - typeCoverage.propertyTotal: total number of properties (including promoted)
 * - typeCoverage.propertyTyped: number of typed properties
 * - typeCoverage.property: percentage of typed properties (0-100)
 *
 * Anonymous classes are ignored.
 */
final class TypeCoverageCollector extends AbstractCollector implements ClassMetricsProviderInterface
{
    private const NAME = 'type-coverage';

    private const METRIC_PARAM_TOTAL = 'typeCoverage.paramTotal';
    private const METRIC_PARAM_TYPED = 'typeCoverage.paramTyped';
    private const METRIC_PARAM = 'typeCoverage.param';
    private const METRIC_RETURN_TOTAL = 'typeCoverage.returnTotal';
    private const METRIC_RETURN_TYPED = 'typeCoverage.returnTyped';
    private const METRIC_RETURN = 'typeCoverage.return';
    private const METRIC_PROPERTY_TOTAL = 'typeCoverage.propertyTotal';
    private const METRIC_PROPERTY_TYPED = 'typeCoverage.propertyTyped';
    private const METRIC_PROPERTY = 'typeCoverage.property';

    public function __construct()
    {
        $this->visitor = new TypeCoverageVisitor();
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
        return [
            self::METRIC_PARAM_TOTAL,
            self::METRIC_PARAM_TYPED,
            self::METRIC_PARAM,
            self::METRIC_RETURN_TOTAL,
            self::METRIC_RETURN_TYPED,
            self::METRIC_RETURN,
            self::METRIC_PROPERTY_TOTAL,
            self::METRIC_PROPERTY_TYPED,
            self::METRIC_PROPERTY,
        ];
    }

    /**
     * @param Node[] $ast
     */
    public function collect(SplFileInfo $file, array $ast): MetricBag
    {
        $bag = new MetricBag();

        \assert($this->visitor instanceof TypeCoverageVisitor);

        foreach ($this->visitor->getClassTypeInfo() as $fqn => $info) {
            $bag = $bag
                ->with(self::METRIC_PARAM_TOTAL . ':' . $fqn, $info['paramTotal'])
                ->with(self::METRIC_PARAM_TYPED . ':' . $fqn, $info['paramTyped'])
                ->with(self::METRIC_RETURN_TOTAL . ':' . $fqn, $info['returnTotal'])
                ->with(self::METRIC_RETURN_TYPED . ':' . $fqn, $info['returnTyped'])
                ->with(self::METRIC_PROPERTY_TOTAL . ':' . $fqn, $info['propertyTotal'])
                ->with(self::METRIC_PROPERTY_TYPED . ':' . $fqn, $info['propertyTyped']);

            // Only store percentages when total > 0
            if ($info['paramTotal'] > 0) {
                $bag = $bag->with(
                    self::METRIC_PARAM . ':' . $fqn,
                    round($info['paramTyped'] / $info['paramTotal'] * 100, 2),
                );
            }

            if ($info['returnTotal'] > 0) {
                $bag = $bag->with(
                    self::METRIC_RETURN . ':' . $fqn,
                    round($info['returnTyped'] / $info['returnTotal'] * 100, 2),
                );
            }

            if ($info['propertyTotal'] > 0) {
                $bag = $bag->with(
                    self::METRIC_PROPERTY . ':' . $fqn,
                    round($info['propertyTyped'] / $info['propertyTotal'] * 100, 2),
                );
            }
        }

        return $bag;
    }

    /**
     * @return list<ClassWithMetrics>
     */
    public function getClassesWithMetrics(): array
    {
        \assert($this->visitor instanceof TypeCoverageVisitor);

        return $this->visitor->getClassesWithMetrics();
    }

    /**
     * @return list<MetricDefinition>
     */
    #[Override]
    public function getMetricDefinitions(): array
    {
        $totalAggregations = [
            SymbolLevel::Namespace_->value => [
                AggregationStrategy::Sum,
            ],
            SymbolLevel::Project->value => [
                AggregationStrategy::Sum,
            ],
        ];

        return [
            new MetricDefinition(
                name: self::METRIC_PARAM_TOTAL,
                collectedAt: SymbolLevel::Class_,
                aggregations: $totalAggregations,
            ),
            new MetricDefinition(
                name: self::METRIC_PARAM_TYPED,
                collectedAt: SymbolLevel::Class_,
                aggregations: $totalAggregations,
            ),
            new MetricDefinition(
                name: self::METRIC_PARAM,
                collectedAt: SymbolLevel::Class_,
                aggregations: [],
            ),
            new MetricDefinition(
                name: self::METRIC_RETURN_TOTAL,
                collectedAt: SymbolLevel::Class_,
                aggregations: $totalAggregations,
            ),
            new MetricDefinition(
                name: self::METRIC_RETURN_TYPED,
                collectedAt: SymbolLevel::Class_,
                aggregations: $totalAggregations,
            ),
            new MetricDefinition(
                name: self::METRIC_RETURN,
                collectedAt: SymbolLevel::Class_,
                aggregations: [],
            ),
            new MetricDefinition(
                name: self::METRIC_PROPERTY_TOTAL,
                collectedAt: SymbolLevel::Class_,
                aggregations: $totalAggregations,
            ),
            new MetricDefinition(
                name: self::METRIC_PROPERTY_TYPED,
                collectedAt: SymbolLevel::Class_,
                aggregations: $totalAggregations,
            ),
            new MetricDefinition(
                name: self::METRIC_PROPERTY,
                collectedAt: SymbolLevel::Class_,
                aggregations: [],
            ),
        ];
    }
}
