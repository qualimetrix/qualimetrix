<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Evidence\CodeSmell;

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
 * Collects parameter count metrics for methods and functions.
 *
 * Metric format: parameterCount:{FQN}
 * Example: code-smell.parameter-count:App\Service\UserService::calculate
 */
final class ParameterCountCollector extends AbstractCollector implements CallableMetricsProviderInterface
{
    private const NAME = 'parameter-count';

    public function __construct()
    {
        $this->visitor = new ParameterCountVisitor();
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
            MetricName::CODE_SMELL_PARAMETER_COUNT,
            MetricName::CODE_SMELL_IS_VO_CONSTRUCTOR,
        ];
    }

    /**
     * @param Node[] $ast
     */
    public function collect(SplFileInfo $file, array $ast): MetricBag
    {
        $bag = new MetricBag();

        \assert($this->visitor instanceof ParameterCountVisitor);

        foreach ($this->visitor->getParameterCounts() as $fqn => $count) {
            $bag = $bag->with(MetricName::CODE_SMELL_PARAMETER_COUNT . ':' . $fqn, $count);
        }

        foreach ($this->visitor->getVoConstructors() as $fqn => $_) {
            $bag = $bag->with(MetricName::CODE_SMELL_IS_VO_CONSTRUCTOR . ':' . $fqn, 1);
        }

        return $bag;
    }

    /**
     * @return list<CallableWithMetrics>
     */
    public function getCallablesWithMetrics(RelativePath $file): array
    {
        \assert($this->visitor instanceof ParameterCountVisitor);

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
                name: MetricName::CODE_SMELL_PARAMETER_COUNT,
                collectedAt: SymbolLevel::Callable,
                aggregations: [
                    SymbolLevel::Class_->value => [
                        AggregationStrategy::Max,
                        AggregationStrategy::Average,
                    ],
                    SymbolLevel::Namespace_->value => [
                        AggregationStrategy::Max,
                        AggregationStrategy::Average,
                        AggregationStrategy::Percentile95,
                    ],
                    SymbolLevel::Project->value => [
                        AggregationStrategy::Max,
                        AggregationStrategy::Average,
                        AggregationStrategy::Percentile95,
                    ],
                ],
            ),
            // Boolean flag (0/1) for VO constructor detection — no aggregation needed
            new MetricDefinition(
                name: MetricName::CODE_SMELL_IS_VO_CONSTRUCTOR,
                collectedAt: SymbolLevel::Callable,
                aggregations: [],
            ),
        ];
    }
}
