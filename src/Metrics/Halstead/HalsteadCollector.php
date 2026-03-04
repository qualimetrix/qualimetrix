<?php

declare(strict_types=1);

namespace AiMessDetector\Metrics\Halstead;

use AiMessDetector\Core\Metric\AggregationStrategy;
use AiMessDetector\Core\Metric\MethodMetricsProviderInterface;
use AiMessDetector\Core\Metric\MethodWithMetrics;
use AiMessDetector\Core\Metric\MetricBag;
use AiMessDetector\Core\Metric\MetricDefinition;
use AiMessDetector\Core\Metric\SymbolLevel;
use AiMessDetector\Metrics\AbstractCollector;
use Override;
use SplFileInfo;

/**
 * Halstead Complexity Metrics Collector
 *
 * Implements complexity metrics based on Maurice Halstead's methodology (1977).
 *
 * ## Methodology
 *
 * AIMD uses a **semantic approach** to token classification:
 *
 * ### Operators (n1, N1) - actions on data:
 * - Arithmetic: +, -, *, /, %, **
 * - Logical: &&, ||, !, and, or, xor
 * - Comparison: ==, ===, !=, <, >, <=, >=, <=>
 * - Assignment: =, +=, -=, *=, /=, ??=
 * - Bitwise: &, |, ^, ~, <<, >>
 * - Control flow: if, else, switch, case, for, foreach, while, do, return, throw, try, catch
 * - Access: ->, ::, ??
 * - Calls: function call, method call, new
 * - Arrays: [], array()
 *
 * ### Operands (n2, N2) - data:
 * - Variables: $var, $this
 * - Literals: numbers, strings, true, false, null
 * - Constants: CONST_NAME, self::CONST
 * - Identifiers: function, method, and class names
 *
 * ### NOT counted (syntactic "noise"):
 * - Semicolons: ;
 * - Brackets: (, ), {, }, [, ]
 * - Commas: ,
 * - Colons in types: : int
 *
 * ## Comparison with PDepend
 *
 * PDepend uses a token-oriented approach, counting syntactic
 * elements (;, (, ,) as operators. This leads to inflated values:
 * - Difficulty: +75-220%
 * - Effort: +100-350%
 *
 * AIMD follows the original Halstead methodology, measuring semantic
 * code complexity rather than syntactic density.
 *
 * ## Formulas
 *
 * - Vocabulary: n = n1 + n2
 * - Length: N = N1 + N2
 * - Volume: V = N x log2(n)
 * - Difficulty: D = (n1/2) x (N2/n2)
 * - Effort: E = D x V
 * - Time: T = E / 18 (seconds)
 * - Bugs: B = V / 3000 (expected number of bugs)
 *
 * @see https://en.wikipedia.org/wiki/Halstead_complexity_measures
 * @see Halstead, M.H. (1977). "Elements of Software Science". Elsevier.
 */
final class HalsteadCollector extends AbstractCollector implements MethodMetricsProviderInterface
{
    private const NAME = 'halstead';

    private const METRIC_VOLUME = 'halstead.volume';
    private const METRIC_DIFFICULTY = 'halstead.difficulty';
    private const METRIC_EFFORT = 'halstead.effort';
    private const METRIC_BUGS = 'halstead.bugs';
    private const METRIC_TIME = 'halstead.time';

    public function __construct()
    {
        $this->visitor = new HalsteadVisitor();
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
            self::METRIC_VOLUME,
            self::METRIC_DIFFICULTY,
            self::METRIC_EFFORT,
            self::METRIC_BUGS,
            self::METRIC_TIME,
        ];
    }

    /**
     * @param \PhpParser\Node[] $ast
     */
    public function collect(SplFileInfo $file, array $ast): MetricBag
    {
        $bag = new MetricBag();

        \assert($this->visitor instanceof HalsteadVisitor);

        foreach ($this->visitor->getMetrics() as $fqn => $halstead) {
            $bag = $bag
                ->with(self::METRIC_VOLUME . ':' . $fqn, $halstead->volume())
                ->with(self::METRIC_DIFFICULTY . ':' . $fqn, $halstead->difficulty())
                ->with(self::METRIC_EFFORT . ':' . $fqn, $halstead->effort())
                ->with(self::METRIC_BUGS . ':' . $fqn, $halstead->bugs())
                ->with(self::METRIC_TIME . ':' . $fqn, $halstead->time());
        }

        return $bag;
    }

    /**
     * @return list<MethodWithMetrics>
     */
    public function getMethodsWithMetrics(): array
    {
        \assert($this->visitor instanceof HalsteadVisitor);

        return $this->visitor->getMethodsWithMetrics();
    }

    /**
     * @return list<MetricDefinition>
     */
    #[Override]
    public function getMetricDefinitions(): array
    {
        $aggregations = [
            SymbolLevel::Class_->value => [
                AggregationStrategy::Average,
                AggregationStrategy::Max,
            ],
            SymbolLevel::Namespace_->value => [
                AggregationStrategy::Average,
                AggregationStrategy::Max,
            ],
            SymbolLevel::Project->value => [
                AggregationStrategy::Average,
                AggregationStrategy::Max,
            ],
        ];

        return [
            new MetricDefinition(
                name: self::METRIC_VOLUME,
                collectedAt: SymbolLevel::Method,
                aggregations: $aggregations,
            ),
            new MetricDefinition(
                name: self::METRIC_DIFFICULTY,
                collectedAt: SymbolLevel::Method,
                aggregations: $aggregations,
            ),
            new MetricDefinition(
                name: self::METRIC_EFFORT,
                collectedAt: SymbolLevel::Method,
                aggregations: $aggregations,
            ),
            new MetricDefinition(
                name: self::METRIC_BUGS,
                collectedAt: SymbolLevel::Method,
                aggregations: [
                    SymbolLevel::Class_->value => [
                        AggregationStrategy::Sum,
                        AggregationStrategy::Max,
                    ],
                    SymbolLevel::Namespace_->value => [
                        AggregationStrategy::Sum,
                        AggregationStrategy::Max,
                    ],
                    SymbolLevel::Project->value => [
                        AggregationStrategy::Sum,
                    ],
                ],
            ),
            new MetricDefinition(
                name: self::METRIC_TIME,
                collectedAt: SymbolLevel::Method,
                aggregations: [
                    SymbolLevel::Class_->value => [
                        AggregationStrategy::Sum,
                        AggregationStrategy::Max,
                    ],
                    SymbolLevel::Namespace_->value => [
                        AggregationStrategy::Sum,
                        AggregationStrategy::Max,
                    ],
                    SymbolLevel::Project->value => [
                        AggregationStrategy::Sum,
                    ],
                ],
            ),
        ];
    }
}
