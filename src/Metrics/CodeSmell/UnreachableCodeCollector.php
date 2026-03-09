<?php

declare(strict_types=1);

namespace AiMessDetector\Metrics\CodeSmell;

use AiMessDetector\Core\Metric\AggregationStrategy;
use AiMessDetector\Core\Metric\MethodMetricsProviderInterface;
use AiMessDetector\Core\Metric\MethodWithMetrics;
use AiMessDetector\Core\Metric\MetricBag;
use AiMessDetector\Core\Metric\MetricDefinition;
use AiMessDetector\Core\Metric\MetricName;
use AiMessDetector\Core\Metric\SymbolLevel;
use AiMessDetector\Metrics\AbstractCollector;
use Override;
use PhpParser\Node;
use SplFileInfo;

/**
 * Collects unreachable code metrics for methods and functions.
 *
 * Metric format: unreachableCode:{FQN} — count of unreachable statements
 * Metric format: unreachableCode.firstLine:{FQN} — line number of first unreachable statement
 */
final class UnreachableCodeCollector extends AbstractCollector implements MethodMetricsProviderInterface
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
     * @return list<MethodWithMetrics>
     */
    public function getMethodsWithMetrics(): array
    {
        \assert($this->visitor instanceof UnreachableCodeVisitor);

        return $this->visitor->getMethodsWithMetrics();
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
                collectedAt: SymbolLevel::Method,
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
                collectedAt: SymbolLevel::Method,
                aggregations: [],
            ),
        ];
    }
}
