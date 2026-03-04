<?php

declare(strict_types=1);

namespace AiMessDetector\Metrics\Size;

use AiMessDetector\Core\Metric\AggregationStrategy;
use AiMessDetector\Core\Metric\MetricBag;
use AiMessDetector\Core\Metric\MetricDefinition;
use AiMessDetector\Core\Metric\SymbolLevel;
use AiMessDetector\Metrics\AbstractCollector;
use Override;
use PhpParser\Node;
use SplFileInfo;

/**
 * Collects class, interface, trait, enum, and function counts for files.
 *
 * Metrics format:
 * - classCount:{path}
 * - abstractClassCount:{path}
 * - interfaceCount:{path}
 * - traitCount:{path}
 * - enumCount:{path}
 * - functionCount:{path}
 *
 * Anonymous classes are ignored.
 * Only standalone functions are counted (not class methods).
 */
final class ClassCountCollector extends AbstractCollector
{
    private const NAME = 'class-count';
    private const METRIC_CLASS_COUNT = 'classCount';
    private const METRIC_ABSTRACT_CLASS_COUNT = 'abstractClassCount';
    private const METRIC_INTERFACE_COUNT = 'interfaceCount';
    private const METRIC_TRAIT_COUNT = 'traitCount';
    private const METRIC_ENUM_COUNT = 'enumCount';
    private const METRIC_FUNCTION_COUNT = 'functionCount';

    public function __construct()
    {
        $this->visitor = new ClassCountVisitor();
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
            self::METRIC_CLASS_COUNT,
            self::METRIC_ABSTRACT_CLASS_COUNT,
            self::METRIC_INTERFACE_COUNT,
            self::METRIC_TRAIT_COUNT,
            self::METRIC_ENUM_COUNT,
            self::METRIC_FUNCTION_COUNT,
        ];
    }

    /**
     * @param Node[] $ast
     */
    public function collect(SplFileInfo $file, array $ast): MetricBag
    {
        \assert($this->visitor instanceof ClassCountVisitor);

        return (new MetricBag())
            ->with(self::METRIC_CLASS_COUNT, $this->visitor->getClassCount())
            ->with(self::METRIC_ABSTRACT_CLASS_COUNT, $this->visitor->getAbstractClassCount())
            ->with(self::METRIC_INTERFACE_COUNT, $this->visitor->getInterfaceCount())
            ->with(self::METRIC_TRAIT_COUNT, $this->visitor->getTraitCount())
            ->with(self::METRIC_ENUM_COUNT, $this->visitor->getEnumCount())
            ->with(self::METRIC_FUNCTION_COUNT, $this->visitor->getFunctionCount());
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
            ],
            SymbolLevel::Project->value => [
                AggregationStrategy::Sum,
            ],
        ];

        return [
            new MetricDefinition(
                name: self::METRIC_CLASS_COUNT,
                collectedAt: SymbolLevel::File,
                aggregations: $aggregations,
            ),
            new MetricDefinition(
                name: self::METRIC_ABSTRACT_CLASS_COUNT,
                collectedAt: SymbolLevel::File,
                aggregations: $aggregations,
            ),
            new MetricDefinition(
                name: self::METRIC_INTERFACE_COUNT,
                collectedAt: SymbolLevel::File,
                aggregations: $aggregations,
            ),
            new MetricDefinition(
                name: self::METRIC_TRAIT_COUNT,
                collectedAt: SymbolLevel::File,
                aggregations: $aggregations,
            ),
            new MetricDefinition(
                name: self::METRIC_ENUM_COUNT,
                collectedAt: SymbolLevel::File,
                aggregations: $aggregations,
            ),
            new MetricDefinition(
                name: self::METRIC_FUNCTION_COUNT,
                collectedAt: SymbolLevel::File,
                aggregations: $aggregations,
            ),
        ];
    }
}
