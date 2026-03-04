<?php

declare(strict_types=1);

namespace AiMessDetector\Metrics\Structure;

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
 * Collects method count and property count metrics for classes.
 *
 * Metrics per class:
 * - methodCount: methods excluding getters/setters
 * - methodCountTotal: all methods
 * - methodCountPublic: public methods (excluding getters/setters)
 * - methodCountProtected: protected methods (excluding getters/setters)
 * - methodCountPrivate: private methods (excluding getters/setters)
 * - getterCount: getter methods (get*, is*, has*)
 * - setterCount: setter methods (set*)
 * - propertyCount: total number of properties
 * - propertyCountPublic: public properties
 * - propertyCountProtected: protected properties
 * - propertyCountPrivate: private properties
 * - promotedPropertyCount: constructor promoted properties (PHP 8+)
 * - woc: Weight of Class (ratio of public methods to total methods, 0-100)
 *
 * Anonymous classes are ignored.
 */
final class MethodCountCollector extends AbstractCollector implements ClassMetricsProviderInterface
{
    private const NAME = 'method-count';

    private const METRIC_METHOD_COUNT = 'methodCount';
    private const METRIC_METHOD_COUNT_TOTAL = 'methodCountTotal';
    private const METRIC_METHOD_COUNT_PUBLIC = 'methodCountPublic';
    private const METRIC_METHOD_COUNT_PROTECTED = 'methodCountProtected';
    private const METRIC_METHOD_COUNT_PRIVATE = 'methodCountPrivate';
    private const METRIC_GETTER_COUNT = 'getterCount';
    private const METRIC_SETTER_COUNT = 'setterCount';
    private const METRIC_PROPERTY_COUNT = 'propertyCount';
    private const METRIC_PROPERTY_COUNT_PUBLIC = 'propertyCountPublic';
    private const METRIC_PROPERTY_COUNT_PROTECTED = 'propertyCountProtected';
    private const METRIC_PROPERTY_COUNT_PRIVATE = 'propertyCountPrivate';
    private const METRIC_PROMOTED_PROPERTY_COUNT = 'promotedPropertyCount';

    // RFC-008: Class characteristics for false positive reduction
    private const METRIC_IS_READONLY = 'isReadonly';
    private const METRIC_IS_PROMOTED_PROPERTIES_ONLY = 'isPromotedPropertiesOnly';
    private const METRIC_IS_DATA_CLASS = 'isDataClass';

    // PDepend WOC metric
    private const METRIC_WOC = 'woc';

    public function __construct()
    {
        $this->visitor = new MethodCountVisitor();
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
            self::METRIC_METHOD_COUNT,
            self::METRIC_METHOD_COUNT_TOTAL,
            self::METRIC_METHOD_COUNT_PUBLIC,
            self::METRIC_METHOD_COUNT_PROTECTED,
            self::METRIC_METHOD_COUNT_PRIVATE,
            self::METRIC_GETTER_COUNT,
            self::METRIC_SETTER_COUNT,
            self::METRIC_PROPERTY_COUNT,
            self::METRIC_PROPERTY_COUNT_PUBLIC,
            self::METRIC_PROPERTY_COUNT_PROTECTED,
            self::METRIC_PROPERTY_COUNT_PRIVATE,
            self::METRIC_PROMOTED_PROPERTY_COUNT,
            // RFC-008: Class characteristics for false positive reduction
            self::METRIC_IS_READONLY,
            self::METRIC_IS_PROMOTED_PROPERTIES_ONLY,
            self::METRIC_IS_DATA_CLASS,
            // PDepend WOC metric
            self::METRIC_WOC,
        ];
    }

    /**
     * @param Node[] $ast
     */
    public function collect(SplFileInfo $file, array $ast): MetricBag
    {
        $bag = new MetricBag();

        \assert($this->visitor instanceof MethodCountVisitor);

        foreach ($this->visitor->getClassMetrics() as $classFqn => $metrics) {
            // RFC-008: Calculate derived class characteristics
            // isPromotedPropertiesOnly = all properties are promoted
            $isPromotedOnly = $metrics->propertyCount > 0
                && $metrics->propertyCount === $metrics->promotedPropertyCount;

            // isDataClass = only getters/setters/constructor (no other logic)
            $nonAccessorMethods = $metrics->methodCountTotal
                - $metrics->getterCount
                - $metrics->setterCount
                - ($metrics->hasConstructor ? 1 : 0);
            $isDataClass = $nonAccessorMethods === 0;

            // WOC = publicMethods / totalMethods (percentage 0-100)
            // Higher WOC = more public surface, potentially less encapsulation
            $woc = $metrics->methodCountTotal > 0
                ? (int) round(($metrics->methodCountPublic / $metrics->methodCountTotal) * 100)
                : 0;

            $bag = $bag
                ->with(self::METRIC_METHOD_COUNT . ':' . $classFqn, $metrics->methodCount())
                ->with(self::METRIC_METHOD_COUNT_TOTAL . ':' . $classFqn, $metrics->methodCountTotal)
                ->with(self::METRIC_METHOD_COUNT_PUBLIC . ':' . $classFqn, $metrics->methodCountPublic)
                ->with(self::METRIC_METHOD_COUNT_PROTECTED . ':' . $classFqn, $metrics->methodCountProtected)
                ->with(self::METRIC_METHOD_COUNT_PRIVATE . ':' . $classFqn, $metrics->methodCountPrivate)
                ->with(self::METRIC_GETTER_COUNT . ':' . $classFqn, $metrics->getterCount)
                ->with(self::METRIC_SETTER_COUNT . ':' . $classFqn, $metrics->setterCount)
                ->with(self::METRIC_PROPERTY_COUNT . ':' . $classFqn, $metrics->propertyCount)
                ->with(self::METRIC_PROPERTY_COUNT_PUBLIC . ':' . $classFqn, $metrics->propertyCountPublic)
                ->with(self::METRIC_PROPERTY_COUNT_PROTECTED . ':' . $classFqn, $metrics->propertyCountProtected)
                ->with(self::METRIC_PROPERTY_COUNT_PRIVATE . ':' . $classFqn, $metrics->propertyCountPrivate)
                ->with(self::METRIC_PROMOTED_PROPERTY_COUNT . ':' . $classFqn, $metrics->promotedPropertyCount)
                // RFC-008: Class characteristics for false positive reduction
                ->with(self::METRIC_IS_READONLY . ':' . $classFqn, $metrics->isReadonly ? 1 : 0)
                ->with(self::METRIC_IS_PROMOTED_PROPERTIES_ONLY . ':' . $classFqn, $isPromotedOnly ? 1 : 0)
                ->with(self::METRIC_IS_DATA_CLASS . ':' . $classFqn, $isDataClass ? 1 : 0)
                // PDepend WOC metric
                ->with(self::METRIC_WOC . ':' . $classFqn, $woc);
        }

        return $bag;
    }

    /**
     * @return list<ClassWithMetrics>
     */
    public function getClassesWithMetrics(): array
    {
        \assert($this->visitor instanceof MethodCountVisitor);

        $result = [];

        foreach ($this->visitor->getClassMetrics() as $metrics) {
            // RFC-008: Calculate derived class characteristics
            $isPromotedOnly = $metrics->propertyCount > 0
                && $metrics->propertyCount === $metrics->promotedPropertyCount;

            $nonAccessorMethods = $metrics->methodCountTotal
                - $metrics->getterCount
                - $metrics->setterCount
                - ($metrics->hasConstructor ? 1 : 0);
            $isDataClass = $nonAccessorMethods === 0;

            // WOC = publicMethods / totalMethods (percentage 0-100)
            $woc = $metrics->methodCountTotal > 0
                ? (int) round(($metrics->methodCountPublic / $metrics->methodCountTotal) * 100)
                : 0;

            $bag = (new MetricBag())
                ->with(self::METRIC_METHOD_COUNT, $metrics->methodCount())
                ->with(self::METRIC_METHOD_COUNT_TOTAL, $metrics->methodCountTotal)
                ->with(self::METRIC_METHOD_COUNT_PUBLIC, $metrics->methodCountPublic)
                ->with(self::METRIC_METHOD_COUNT_PROTECTED, $metrics->methodCountProtected)
                ->with(self::METRIC_METHOD_COUNT_PRIVATE, $metrics->methodCountPrivate)
                ->with(self::METRIC_GETTER_COUNT, $metrics->getterCount)
                ->with(self::METRIC_SETTER_COUNT, $metrics->setterCount)
                ->with(self::METRIC_PROPERTY_COUNT, $metrics->propertyCount)
                ->with(self::METRIC_PROPERTY_COUNT_PUBLIC, $metrics->propertyCountPublic)
                ->with(self::METRIC_PROPERTY_COUNT_PROTECTED, $metrics->propertyCountProtected)
                ->with(self::METRIC_PROPERTY_COUNT_PRIVATE, $metrics->propertyCountPrivate)
                ->with(self::METRIC_PROMOTED_PROPERTY_COUNT, $metrics->promotedPropertyCount)
                // RFC-008: Class characteristics for false positive reduction
                ->with(self::METRIC_IS_READONLY, $metrics->isReadonly ? 1 : 0)
                ->with(self::METRIC_IS_PROMOTED_PROPERTIES_ONLY, $isPromotedOnly ? 1 : 0)
                ->with(self::METRIC_IS_DATA_CLASS, $isDataClass ? 1 : 0)
                // PDepend WOC metric
                ->with(self::METRIC_WOC, $woc);

            $result[] = new ClassWithMetrics(
                namespace: $metrics->namespace,
                class: $metrics->className,
                line: $metrics->line,
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
        $aggregations = [
            SymbolLevel::Namespace_->value => [
                AggregationStrategy::Sum,
                AggregationStrategy::Average,
                AggregationStrategy::Max,
            ],
            SymbolLevel::Project->value => [
                AggregationStrategy::Sum,
                AggregationStrategy::Average,
                AggregationStrategy::Max,
            ],
        ];

        return [
            new MetricDefinition(
                name: self::METRIC_METHOD_COUNT,
                collectedAt: SymbolLevel::Class_,
                aggregations: $aggregations,
            ),
            new MetricDefinition(
                name: self::METRIC_METHOD_COUNT_TOTAL,
                collectedAt: SymbolLevel::Class_,
                aggregations: $aggregations,
            ),
            new MetricDefinition(
                name: self::METRIC_METHOD_COUNT_PUBLIC,
                collectedAt: SymbolLevel::Class_,
                aggregations: $aggregations,
            ),
            new MetricDefinition(
                name: self::METRIC_METHOD_COUNT_PROTECTED,
                collectedAt: SymbolLevel::Class_,
                aggregations: $aggregations,
            ),
            new MetricDefinition(
                name: self::METRIC_METHOD_COUNT_PRIVATE,
                collectedAt: SymbolLevel::Class_,
                aggregations: $aggregations,
            ),
            new MetricDefinition(
                name: self::METRIC_GETTER_COUNT,
                collectedAt: SymbolLevel::Class_,
                aggregations: $aggregations,
            ),
            new MetricDefinition(
                name: self::METRIC_SETTER_COUNT,
                collectedAt: SymbolLevel::Class_,
                aggregations: $aggregations,
            ),
            new MetricDefinition(
                name: self::METRIC_PROPERTY_COUNT,
                collectedAt: SymbolLevel::Class_,
                aggregations: $aggregations,
            ),
            new MetricDefinition(
                name: self::METRIC_PROPERTY_COUNT_PUBLIC,
                collectedAt: SymbolLevel::Class_,
                aggregations: $aggregations,
            ),
            new MetricDefinition(
                name: self::METRIC_PROPERTY_COUNT_PROTECTED,
                collectedAt: SymbolLevel::Class_,
                aggregations: $aggregations,
            ),
            new MetricDefinition(
                name: self::METRIC_PROPERTY_COUNT_PRIVATE,
                collectedAt: SymbolLevel::Class_,
                aggregations: $aggregations,
            ),
            new MetricDefinition(
                name: self::METRIC_PROMOTED_PROPERTY_COUNT,
                collectedAt: SymbolLevel::Class_,
                aggregations: $aggregations,
            ),
            // RFC-008: Class characteristics for false positive reduction
            // These are boolean flags (0/1), so Sum gives count of matching classes
            new MetricDefinition(
                name: self::METRIC_IS_READONLY,
                collectedAt: SymbolLevel::Class_,
                aggregations: [
                    SymbolLevel::Namespace_->value => [AggregationStrategy::Sum],
                    SymbolLevel::Project->value => [AggregationStrategy::Sum],
                ],
            ),
            new MetricDefinition(
                name: self::METRIC_IS_PROMOTED_PROPERTIES_ONLY,
                collectedAt: SymbolLevel::Class_,
                aggregations: [
                    SymbolLevel::Namespace_->value => [AggregationStrategy::Sum],
                    SymbolLevel::Project->value => [AggregationStrategy::Sum],
                ],
            ),
            new MetricDefinition(
                name: self::METRIC_IS_DATA_CLASS,
                collectedAt: SymbolLevel::Class_,
                aggregations: [
                    SymbolLevel::Namespace_->value => [AggregationStrategy::Sum],
                    SymbolLevel::Project->value => [AggregationStrategy::Sum],
                ],
            ),
            // PDepend WOC metric (percentage 0-100)
            new MetricDefinition(
                name: self::METRIC_WOC,
                collectedAt: SymbolLevel::Class_,
                aggregations: [
                    SymbolLevel::Namespace_->value => [
                        AggregationStrategy::Average,
                        AggregationStrategy::Min,
                        AggregationStrategy::Max,
                    ],
                    SymbolLevel::Project->value => [
                        AggregationStrategy::Average,
                        AggregationStrategy::Min,
                        AggregationStrategy::Max,
                    ],
                ],
            ),
        ];
    }
}
