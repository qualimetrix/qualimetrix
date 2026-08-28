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
 * Collects Cognitive Complexity metrics for methods and functions.
 *
 * Cognitive Complexity measures how difficult code is to understand,
 * considering nesting depth and control flow structures.
 *
 * Metric format: cognitive:{FQN}
 * Example: complexity.cognitive:App\Service\UserService::calculate
 *
 * @see https://www.sonarsource.com/docs/CognitiveComplexity.pdf
 */
final class CognitiveComplexityCollector extends AbstractCollector implements CallableMetricsProviderInterface
{
    private const NAME = 'cognitive-complexity';

    public function __construct()
    {
        $this->visitor = new CognitiveComplexityVisitor();
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
        return [MetricName::COMPLEXITY_COGNITIVE];
    }

    /**
     * @param Node[] $ast
     */
    public function collect(SplFileInfo $file, array $ast): MetricBag
    {
        $bag = new MetricBag();

        \assert($this->visitor instanceof CognitiveComplexityVisitor);

        foreach ($this->visitor->getComplexities() as $fqn => $complexity) {
            $bag = $bag->with(MetricName::COMPLEXITY_COGNITIVE . ':' . $fqn, $complexity);
        }

        return $bag;
    }

    /**
     * @return list<CallableWithMetrics>
     */
    public function getCallablesWithMetrics(RelativePath $file): array
    {
        \assert($this->visitor instanceof CognitiveComplexityVisitor);

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
                name: MetricName::COMPLEXITY_COGNITIVE,
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
