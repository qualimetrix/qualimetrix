<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Evidence\Complexity;

use Override;
use PhpParser\Node;
use Qualimetrix\Analysis\Evidence\Measurement\Contract\AbstractCollector;
use Qualimetrix\Analysis\Evidence\Measurement\Contract\AggregationStrategy;
use Qualimetrix\Analysis\Evidence\Measurement\Contract\CallableMetricsProviderInterface;
use Qualimetrix\Analysis\Evidence\Measurement\Contract\CallableWithMetrics;
use Qualimetrix\Analysis\Evidence\Measurement\Contract\MetricBag;
use Qualimetrix\Analysis\Evidence\Measurement\Contract\MetricDefinition;
use Qualimetrix\Analysis\Evidence\Measurement\Contract\MetricName;
use Qualimetrix\Core\Path\RelativePath;
use Qualimetrix\Core\Symbol\SymbolLevel;
use SplFileInfo;

/**
 * Collects Cyclomatic Complexity metrics for methods and functions.
 *
 * Metric format: ccn:{FQN}
 * Example: complexity.ccn:App\Service\UserService::calculate
 */
final class CyclomaticComplexityCollector extends AbstractCollector implements CallableMetricsProviderInterface
{
    private const NAME = 'cyclomatic-complexity';

    public function __construct()
    {
        $this->visitor = new CyclomaticComplexityVisitor();
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
        return [MetricName::COMPLEXITY_CCN];
    }

    /**
     * @param Node[] $ast
     */
    public function collect(SplFileInfo $file, array $ast): MetricBag
    {
        $bag = new MetricBag();

        \assert($this->visitor instanceof CyclomaticComplexityVisitor);

        foreach ($this->visitor->getComplexities() as $fqn => $complexity) {
            $bag = $bag->with(MetricName::COMPLEXITY_CCN . ':' . $fqn, $complexity);
        }

        return $bag;
    }

    /**
     * @return list<CallableWithMetrics>
     */
    public function getCallablesWithMetrics(RelativePath $file): array
    {
        \assert($this->visitor instanceof CyclomaticComplexityVisitor);

        return $this->visitor->getCallablesWithMetrics($file);
    }

    /**
     * @return list<MetricDefinition>
     */
    #[Override]
    public function getMetricDefinitions(): array
    {
        return [
            new MetricDefinition(
                name: MetricName::COMPLEXITY_CCN,
                collectedAt: SymbolLevel::Callable,
                aggregations: [
                    SymbolLevel::Class_->value => [
                        AggregationStrategy::Sum,
                        AggregationStrategy::Average,
                        AggregationStrategy::Max,
                    ],
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
                ],
            ),
        ];
    }
}
