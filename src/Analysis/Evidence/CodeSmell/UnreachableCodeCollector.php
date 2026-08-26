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
 * Collects unreachable code metrics for methods and functions.
 *
 * Metric format: unreachableCode:{FQN} — count of unreachable statements
 * Metric format: unreachableCode.firstLine:{FQN} — line number of first unreachable statement
 */
final class UnreachableCodeCollector extends AbstractCollector implements CallableMetricsProviderInterface
{
    private const NAME = 'unreachable-code';

    public function __construct()
    {
        $this->visitor = new UnreachableCodeVisitor();
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
        return [MetricName::CODE_SMELL_UNREACHABLE_CODE, MetricName::CODE_SMELL_UNREACHABLE_CODE_FIRST_LINE];
    }

    /**
     * @param Node[] $ast
     */
    public function collect(SplFileInfo $file, array $ast): MetricBag
    {
        $bag = new MetricBag();

        \assert($this->visitor instanceof UnreachableCodeVisitor);

        foreach ($this->visitor->getUnreachableCounts() as $fqn => $count) {
            $bag = $bag->with(MetricName::CODE_SMELL_UNREACHABLE_CODE . ':' . $fqn, $count);
        }

        foreach ($this->visitor->getFirstUnreachableLines() as $fqn => $line) {
            $bag = $bag->with(MetricName::CODE_SMELL_UNREACHABLE_CODE_FIRST_LINE . ':' . $fqn, $line);
        }

        return $bag;
    }

    /**
     * @return list<CallableWithMetrics>
     */
    public function getCallablesWithMetrics(RelativePath $file): array
    {
        \assert($this->visitor instanceof UnreachableCodeVisitor);

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
                name: MetricName::CODE_SMELL_UNREACHABLE_CODE,
                collectedAt: SymbolLevel::Callable,
                aggregations: [
                    SymbolLevel::Class_->value => [
                        AggregationStrategy::Sum,
                    ],
                    SymbolLevel::Namespace_->value => [
                        AggregationStrategy::Sum,
                    ],
                    SymbolLevel::Project->value => [
                        AggregationStrategy::Sum,
                    ],
                ],
            ),
            new MetricDefinition(
                name: MetricName::CODE_SMELL_UNREACHABLE_CODE_FIRST_LINE,
                collectedAt: SymbolLevel::Callable,
                aggregations: [],
            ),
        ];
    }
}
