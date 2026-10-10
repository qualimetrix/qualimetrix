<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Evidence\Size;

use Override;
use PhpParser\Node;
use Qualimetrix\Analysis\Evidence\Measurement\Contract\AbstractCollector;
use Qualimetrix\Analysis\Evidence\Measurement\Contract\AggregationStrategy;
use Qualimetrix\Analysis\Evidence\Measurement\Contract\ClassMetricsProviderInterface;
use Qualimetrix\Analysis\Evidence\Measurement\Contract\ClassWithMetrics;
use Qualimetrix\Analysis\Evidence\Measurement\Contract\DeclarationIndexAwareInterface;
use Qualimetrix\Analysis\Evidence\Measurement\Contract\DeclarationIndexAwareTrait;
use Qualimetrix\Analysis\Evidence\Measurement\Contract\MetricBag;
use Qualimetrix\Analysis\Evidence\Measurement\Contract\MetricDefinition;
use Qualimetrix\Analysis\Evidence\Measurement\Contract\MetricName;
use Qualimetrix\Core\Path\RelativePath;
use Qualimetrix\Core\Symbol\SymbolLevel;
use Qualimetrix\Core\Symbol\SymbolPath;
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
 * - woc: Weight of Class (non-accessor public methods over all public members, 0-100)
 *
 * Anonymous classes are ignored.
 */
final class MethodCountCollector extends AbstractCollector implements DeclarationIndexAwareInterface, ClassMetricsProviderInterface
{
    use DeclarationIndexAwareTrait;

    private const NAME = 'method-count';

    // RFC-008: Class characteristics for false positive reduction

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
            MetricName::SIZE_METHOD_COUNT,
            MetricName::SIZE_METHOD_COUNT_TOTAL,
            MetricName::SIZE_METHOD_COUNT_PUBLIC,
            MetricName::SIZE_METHOD_COUNT_PROTECTED,
            MetricName::SIZE_METHOD_COUNT_PRIVATE,
            MetricName::SIZE_GETTER_COUNT,
            MetricName::SIZE_SETTER_COUNT,
            MetricName::SIZE_PROPERTY_COUNT,
            MetricName::SIZE_PROPERTY_COUNT_PUBLIC,
            MetricName::SIZE_PROPERTY_COUNT_PROTECTED,
            MetricName::SIZE_PROPERTY_COUNT_PRIVATE,
            MetricName::SIZE_PROMOTED_PROPERTY_COUNT,
            // RFC-008: Class characteristics for false positive reduction
            MetricName::DESIGN_IS_READONLY,
            MetricName::DESIGN_IS_PROMOTED_PROPERTIES_ONLY,
            MetricName::DESIGN_IS_DATA_CLASS,
            MetricName::DESIGN_IS_ABSTRACT,
            MetricName::DESIGN_IS_INTERFACE,
            MetricName::DESIGN_WOC,
        ];
    }

    /**
     * @param Node[] $ast
     */
    public function collect(SplFileInfo $file, array $ast): MetricBag
    {
        $bag = new MetricBag();

        \assert($this->visitor instanceof MethodCountVisitor);

        foreach ($this->visitor->getClassMetrics() as $metrics) {
            $classFqn = $metrics->namespace !== null && $metrics->namespace !== ''
                ? $metrics->namespace . '\\' . $metrics->className
                : $metrics->className;
            foreach (self::metricValues($metrics) as $name => $value) {
                $bag = $bag->with($name . ':' . $classFqn, $value);
            }
        }

        return $bag;
    }

    /**
     * @return list<ClassWithMetrics>
     */
    public function getClassesWithMetrics(RelativePath $file): array
    {
        \assert($this->visitor instanceof MethodCountVisitor);

        $result = [];

        foreach ($this->visitor->getClassMetrics() as $metrics) {
            $bag = new MetricBag();
            foreach (self::metricValues($metrics) as $name => $value) {
                $bag = $bag->with($name, $value);
            }

            $result[] = $this->classWithMetrics(SymbolPath::forClass($metrics->namespace ?? '', $metrics->className), $file, $metrics->startFilePos, $metrics->line, $bag);
        }

        return $result;
    }

    /** @return array<string, int|float> */
    private static function metricValues(MethodCountMetrics $metrics): array
    {
        $isPromotedOnly = $metrics->propertyCount > 0
            && $metrics->propertyCount === $metrics->promotedPropertyCount;

        return [
            MetricName::SIZE_METHOD_COUNT => $metrics->methodCount(),
            MetricName::SIZE_METHOD_COUNT_TOTAL => $metrics->methodCountTotal,
            MetricName::SIZE_METHOD_COUNT_PUBLIC => $metrics->methodCountPublic,
            MetricName::SIZE_METHOD_COUNT_PROTECTED => $metrics->methodCountProtected,
            MetricName::SIZE_METHOD_COUNT_PRIVATE => $metrics->methodCountPrivate,
            MetricName::SIZE_GETTER_COUNT => $metrics->getterCount,
            MetricName::SIZE_SETTER_COUNT => $metrics->setterCount,
            MetricName::SIZE_PROPERTY_COUNT => $metrics->propertyCount,
            MetricName::SIZE_PROPERTY_COUNT_PUBLIC => $metrics->propertyCountPublic,
            MetricName::SIZE_PROPERTY_COUNT_PROTECTED => $metrics->propertyCountProtected,
            MetricName::SIZE_PROPERTY_COUNT_PRIVATE => $metrics->propertyCountPrivate,
            MetricName::SIZE_PROMOTED_PROPERTY_COUNT => $metrics->promotedPropertyCount,
            MetricName::DESIGN_IS_READONLY => $metrics->isReadonly ? 1 : 0,
            MetricName::DESIGN_IS_PROMOTED_PROPERTIES_ONLY => $isPromotedOnly ? 1 : 0,
            MetricName::DESIGN_IS_DATA_CLASS => $metrics->isDataClass() ? 1 : 0,
            MetricName::DESIGN_IS_ABSTRACT => $metrics->isAbstract ? 1 : 0,
            MetricName::DESIGN_IS_INTERFACE => $metrics->isInterface ? 1 : 0,
            MetricName::DESIGN_WOC => $metrics->woc(),
        ];
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
                AggregationStrategy::Percentile95,
            ],
            SymbolLevel::Project->value => [
                AggregationStrategy::Sum,
                AggregationStrategy::Average,
                AggregationStrategy::Max,
                AggregationStrategy::Percentile95,
            ],
        ];

        return [
            new MetricDefinition(
                name: MetricName::SIZE_METHOD_COUNT,
                collectedAt: SymbolLevel::Class_,
                aggregations: $aggregations,
            ),
            new MetricDefinition(
                name: MetricName::SIZE_METHOD_COUNT_TOTAL,
                collectedAt: SymbolLevel::Class_,
                aggregations: $aggregations,
            ),
            new MetricDefinition(
                name: MetricName::SIZE_METHOD_COUNT_PUBLIC,
                collectedAt: SymbolLevel::Class_,
                aggregations: $aggregations,
            ),
            new MetricDefinition(
                name: MetricName::SIZE_METHOD_COUNT_PROTECTED,
                collectedAt: SymbolLevel::Class_,
                aggregations: $aggregations,
            ),
            new MetricDefinition(
                name: MetricName::SIZE_METHOD_COUNT_PRIVATE,
                collectedAt: SymbolLevel::Class_,
                aggregations: $aggregations,
            ),
            new MetricDefinition(
                name: MetricName::SIZE_GETTER_COUNT,
                collectedAt: SymbolLevel::Class_,
                aggregations: $aggregations,
            ),
            new MetricDefinition(
                name: MetricName::SIZE_SETTER_COUNT,
                collectedAt: SymbolLevel::Class_,
                aggregations: $aggregations,
            ),
            new MetricDefinition(
                name: MetricName::SIZE_PROPERTY_COUNT,
                collectedAt: SymbolLevel::Class_,
                aggregations: $aggregations,
            ),
            new MetricDefinition(
                name: MetricName::SIZE_PROPERTY_COUNT_PUBLIC,
                collectedAt: SymbolLevel::Class_,
                aggregations: $aggregations,
            ),
            new MetricDefinition(
                name: MetricName::SIZE_PROPERTY_COUNT_PROTECTED,
                collectedAt: SymbolLevel::Class_,
                aggregations: $aggregations,
            ),
            new MetricDefinition(
                name: MetricName::SIZE_PROPERTY_COUNT_PRIVATE,
                collectedAt: SymbolLevel::Class_,
                aggregations: $aggregations,
            ),
            new MetricDefinition(
                name: MetricName::SIZE_PROMOTED_PROPERTY_COUNT,
                collectedAt: SymbolLevel::Class_,
                aggregations: $aggregations,
            ),
            // RFC-008: Class characteristics for false positive reduction
            // These are boolean flags (0/1), so Sum gives count of matching classes
            new MetricDefinition(
                name: MetricName::DESIGN_IS_READONLY,
                collectedAt: SymbolLevel::Class_,
                aggregations: [
                    SymbolLevel::Namespace_->value => [AggregationStrategy::Sum],
                    SymbolLevel::Project->value => [AggregationStrategy::Sum],
                ],
            ),
            new MetricDefinition(
                name: MetricName::DESIGN_IS_PROMOTED_PROPERTIES_ONLY,
                collectedAt: SymbolLevel::Class_,
                aggregations: [
                    SymbolLevel::Namespace_->value => [AggregationStrategy::Sum],
                    SymbolLevel::Project->value => [AggregationStrategy::Sum],
                ],
            ),
            new MetricDefinition(
                name: MetricName::DESIGN_IS_DATA_CLASS,
                collectedAt: SymbolLevel::Class_,
                aggregations: [
                    SymbolLevel::Namespace_->value => [AggregationStrategy::Sum],
                    SymbolLevel::Project->value => [AggregationStrategy::Sum],
                ],
            ),
            new MetricDefinition(
                name: MetricName::DESIGN_IS_ABSTRACT,
                collectedAt: SymbolLevel::Class_,
                aggregations: [
                    SymbolLevel::Namespace_->value => [AggregationStrategy::Sum],
                    SymbolLevel::Project->value => [AggregationStrategy::Sum],
                ],
            ),
            new MetricDefinition(
                name: MetricName::DESIGN_IS_INTERFACE,
                collectedAt: SymbolLevel::Class_,
                aggregations: [
                    SymbolLevel::Namespace_->value => [AggregationStrategy::Sum],
                    SymbolLevel::Project->value => [AggregationStrategy::Sum],
                ],
            ),
            new MetricDefinition(
                name: MetricName::DESIGN_WOC,
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
