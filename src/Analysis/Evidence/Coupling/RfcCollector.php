<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Evidence\Coupling;

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
 * Collects RFC (Response for a Class) metrics.
 *
 * RFC = M + R
 * Where:
 * - M = number of methods in the class
 * - R = number of unique external methods called from the class
 *
 * Provides three metrics per class:
 * - rfc: total RFC value (M + R)
 * - rfc_own: own methods count (M)
 * - rfc_external: external methods count (R)
 *
 * High RFC indicates:
 * - Complex class with many dependencies
 * - Difficult to test and understand
 * - Potential for refactoring
 *
 * Typical thresholds:
 * - 0-20: Simple class
 * - 20-50: Medium complexity
 * - 50-100: Complex class
 * - > 100: Very complex, hard to test
 */
final class RfcCollector extends AbstractCollector implements DeclarationIndexAwareInterface, ClassMetricsProviderInterface
{
    use DeclarationIndexAwareTrait;

    private const NAME = 'rfc';

    private const METRIC_RFC = MetricName::RFC_TOTAL;
    private const METRIC_RFC_OWN = MetricName::RFC_OWN;
    private const METRIC_RFC_EXTERNAL = MetricName::RFC_EXTERNAL;

    public function __construct()
    {
        $this->visitor = new RfcVisitor();
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
            self::METRIC_RFC,
            self::METRIC_RFC_OWN,
            self::METRIC_RFC_EXTERNAL,
        ];
    }

    /**
     * @param Node[] $ast
     */
    public function collect(SplFileInfo $file, array $ast): MetricBag
    {
        $bag = new MetricBag();

        \assert($this->visitor instanceof RfcVisitor);

        foreach ($this->visitor->getClassesData() as $classFqn => $data) {
            $bag = $bag
                ->with(self::METRIC_RFC . ':' . $classFqn, $data->getRfc())
                ->with(self::METRIC_RFC_OWN . ':' . $classFqn, $data->getOwnMethodsCount())
                ->with(self::METRIC_RFC_EXTERNAL . ':' . $classFqn, $data->getExternalMethodsCount());
        }

        return $bag;
    }

    /**
     * @return list<ClassWithMetrics>
     */
    public function getClassesWithMetrics(RelativePath $file): array
    {
        \assert($this->visitor instanceof RfcVisitor);

        $result = [];

        foreach ($this->visitor->getClassesData() as $data) {
            $bag = (new MetricBag())
                ->with(self::METRIC_RFC, $data->getRfc())
                ->with(self::METRIC_RFC_OWN, $data->getOwnMethodsCount())
                ->with(self::METRIC_RFC_EXTERNAL, $data->getExternalMethodsCount());

            $result[] = $this->classWithMetrics(SymbolPath::forClass($data->namespace ?? '', $data->className), $file, $data->startFilePos, $data->line, $bag);
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
                name: self::METRIC_RFC,
                collectedAt: SymbolLevel::Class_,
                aggregations: $aggregations,
            ),
            new MetricDefinition(
                name: self::METRIC_RFC_OWN,
                collectedAt: SymbolLevel::Class_,
                aggregations: $aggregations,
            ),
            new MetricDefinition(
                name: self::METRIC_RFC_EXTERNAL,
                collectedAt: SymbolLevel::Class_,
                aggregations: $aggregations,
            ),
        ];
    }
}
