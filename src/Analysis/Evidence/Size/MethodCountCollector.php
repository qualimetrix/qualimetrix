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
            MetricName::STRUCTURE_METHOD_COUNT,
            MetricName::STRUCTURE_METHOD_COUNT_TOTAL,
            MetricName::SIZE_METHOD_COUNT_PUBLIC,
            MetricName::SIZE_METHOD_COUNT_PROTECTED,
            MetricName::SIZE_METHOD_COUNT_PRIVATE,
            MetricName::SIZE_GETTER_COUNT,
            MetricName::SIZE_SETTER_COUNT,
            MetricName::STRUCTURE_PROPERTY_COUNT,
            MetricName::SIZE_PROPERTY_COUNT_PUBLIC,
            MetricName::SIZE_PROPERTY_COUNT_PROTECTED,
            MetricName::SIZE_PROPERTY_COUNT_PRIVATE,
            MetricName::SIZE_PROMOTED_PROPERTY_COUNT,
            // RFC-008: Class characteristics for false positive reduction
            MetricName::STRUCTURE_IS_READONLY,
            MetricName::STRUCTURE_IS_PROMOTED_PROPERTIES_ONLY,
            MetricName::STRUCTURE_IS_DATA_CLASS,
            MetricName::STRUCTURE_IS_ABSTRACT,
            MetricName::STRUCTURE_IS_INTERFACE,
            MetricName::STRUCTURE_IS_EXCEPTION,
            MetricName::STRUCTURE_WOC,
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

            $bag = $bag
                ->with(MetricName::STRUCTURE_METHOD_COUNT . ':' . $classFqn, $metrics->methodCount())
                ->with(MetricName::STRUCTURE_METHOD_COUNT_TOTAL . ':' . $classFqn, $metrics->methodCountTotal)
                ->with(MetricName::SIZE_METHOD_COUNT_PUBLIC . ':' . $classFqn, $metrics->methodCountPublic)
                ->with(MetricName::SIZE_METHOD_COUNT_PROTECTED . ':' . $classFqn, $metrics->methodCountProtected)
                ->with(MetricName::SIZE_METHOD_COUNT_PRIVATE . ':' . $classFqn, $metrics->methodCountPrivate)
                ->with(MetricName::SIZE_GETTER_COUNT . ':' . $classFqn, $metrics->getterCount)
                ->with(MetricName::SIZE_SETTER_COUNT . ':' . $classFqn, $metrics->setterCount)
                ->with(MetricName::STRUCTURE_PROPERTY_COUNT . ':' . $classFqn, $metrics->propertyCount)
                ->with(MetricName::SIZE_PROPERTY_COUNT_PUBLIC . ':' . $classFqn, $metrics->propertyCountPublic)
                ->with(MetricName::SIZE_PROPERTY_COUNT_PROTECTED . ':' . $classFqn, $metrics->propertyCountProtected)
                ->with(MetricName::SIZE_PROPERTY_COUNT_PRIVATE . ':' . $classFqn, $metrics->propertyCountPrivate)
                ->with(MetricName::SIZE_PROMOTED_PROPERTY_COUNT . ':' . $classFqn, $metrics->promotedPropertyCount)
                // RFC-008: Class characteristics for false positive reduction
                ->with(MetricName::STRUCTURE_IS_READONLY . ':' . $classFqn, $metrics->isReadonly ? 1 : 0)
                ->with(MetricName::STRUCTURE_IS_PROMOTED_PROPERTIES_ONLY . ':' . $classFqn, $isPromotedOnly ? 1 : 0)
                ->with(MetricName::STRUCTURE_IS_DATA_CLASS . ':' . $classFqn, $metrics->isDataClass() ? 1 : 0)
                ->with(MetricName::STRUCTURE_IS_ABSTRACT . ':' . $classFqn, $metrics->isAbstract ? 1 : 0)
                ->with(MetricName::STRUCTURE_IS_INTERFACE . ':' . $classFqn, $metrics->isInterface ? 1 : 0)
                ->with(MetricName::STRUCTURE_IS_EXCEPTION . ':' . $classFqn, $metrics->isException ? 1 : 0)
                ->with(MetricName::STRUCTURE_WOC . ':' . $classFqn, $metrics->woc());
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
            // RFC-008: Calculate derived class characteristics
            $isPromotedOnly = $metrics->propertyCount > 0
                && $metrics->propertyCount === $metrics->promotedPropertyCount;

            $bag = (new MetricBag())
                ->with(MetricName::STRUCTURE_METHOD_COUNT, $metrics->methodCount())
                ->with(MetricName::STRUCTURE_METHOD_COUNT_TOTAL, $metrics->methodCountTotal)
                ->with(MetricName::SIZE_METHOD_COUNT_PUBLIC, $metrics->methodCountPublic)
                ->with(MetricName::SIZE_METHOD_COUNT_PROTECTED, $metrics->methodCountProtected)
                ->with(MetricName::SIZE_METHOD_COUNT_PRIVATE, $metrics->methodCountPrivate)
                ->with(MetricName::SIZE_GETTER_COUNT, $metrics->getterCount)
                ->with(MetricName::SIZE_SETTER_COUNT, $metrics->setterCount)
                ->with(MetricName::STRUCTURE_PROPERTY_COUNT, $metrics->propertyCount)
                ->with(MetricName::SIZE_PROPERTY_COUNT_PUBLIC, $metrics->propertyCountPublic)
                ->with(MetricName::SIZE_PROPERTY_COUNT_PROTECTED, $metrics->propertyCountProtected)
                ->with(MetricName::SIZE_PROPERTY_COUNT_PRIVATE, $metrics->propertyCountPrivate)
                ->with(MetricName::SIZE_PROMOTED_PROPERTY_COUNT, $metrics->promotedPropertyCount)
                // RFC-008: Class characteristics for false positive reduction
                ->with(MetricName::STRUCTURE_IS_READONLY, $metrics->isReadonly ? 1 : 0)
                ->with(MetricName::STRUCTURE_IS_PROMOTED_PROPERTIES_ONLY, $isPromotedOnly ? 1 : 0)
                ->with(MetricName::STRUCTURE_IS_DATA_CLASS, $metrics->isDataClass() ? 1 : 0)
                ->with(MetricName::STRUCTURE_IS_ABSTRACT, $metrics->isAbstract ? 1 : 0)
                ->with(MetricName::STRUCTURE_IS_INTERFACE, $metrics->isInterface ? 1 : 0)
                ->with(MetricName::STRUCTURE_IS_EXCEPTION, $metrics->isException ? 1 : 0)
                ->with(MetricName::STRUCTURE_WOC, $metrics->woc());

            $result[] = $this->classWithMetrics(SymbolPath::forClass($metrics->namespace ?? '', $metrics->className), $file, $metrics->startFilePos, $metrics->line, $bag);
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
                name: MetricName::STRUCTURE_METHOD_COUNT,
                collectedAt: SymbolLevel::Class_,
                aggregations: $aggregations,
            ),
            new MetricDefinition(
                name: MetricName::STRUCTURE_METHOD_COUNT_TOTAL,
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
                name: MetricName::STRUCTURE_PROPERTY_COUNT,
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
                name: MetricName::STRUCTURE_IS_READONLY,
                collectedAt: SymbolLevel::Class_,
                aggregations: [
                    SymbolLevel::Namespace_->value => [AggregationStrategy::Sum],
                    SymbolLevel::Project->value => [AggregationStrategy::Sum],
                ],
            ),
            new MetricDefinition(
                name: MetricName::STRUCTURE_IS_PROMOTED_PROPERTIES_ONLY,
                collectedAt: SymbolLevel::Class_,
                aggregations: [
                    SymbolLevel::Namespace_->value => [AggregationStrategy::Sum],
                    SymbolLevel::Project->value => [AggregationStrategy::Sum],
                ],
            ),
            new MetricDefinition(
                name: MetricName::STRUCTURE_IS_DATA_CLASS,
                collectedAt: SymbolLevel::Class_,
                aggregations: [
                    SymbolLevel::Namespace_->value => [AggregationStrategy::Sum],
                    SymbolLevel::Project->value => [AggregationStrategy::Sum],
                ],
            ),
            new MetricDefinition(
                name: MetricName::STRUCTURE_IS_ABSTRACT,
                collectedAt: SymbolLevel::Class_,
                aggregations: [
                    SymbolLevel::Namespace_->value => [AggregationStrategy::Sum],
                    SymbolLevel::Project->value => [AggregationStrategy::Sum],
                ],
            ),
            new MetricDefinition(
                name: MetricName::STRUCTURE_IS_INTERFACE,
                collectedAt: SymbolLevel::Class_,
                aggregations: [
                    SymbolLevel::Namespace_->value => [AggregationStrategy::Sum],
                    SymbolLevel::Project->value => [AggregationStrategy::Sum],
                ],
            ),
            new MetricDefinition(
                name: MetricName::STRUCTURE_IS_EXCEPTION,
                collectedAt: SymbolLevel::Class_,
                aggregations: [
                    SymbolLevel::Namespace_->value => [AggregationStrategy::Sum],
                    SymbolLevel::Project->value => [AggregationStrategy::Sum],
                ],
            ),
            new MetricDefinition(
                name: MetricName::STRUCTURE_WOC,
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
