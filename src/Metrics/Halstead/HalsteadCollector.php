<?php

declare(strict_types=1);

namespace Qualimetrix\Metrics\Halstead;

use Override;
use Qualimetrix\Core\Metric\AggregationStrategy;
use Qualimetrix\Core\Metric\MethodMetricsProviderInterface;
use Qualimetrix\Core\Metric\MethodWithMetrics;
use Qualimetrix\Core\Metric\MetricBag;
use Qualimetrix\Core\Metric\MetricDefinition;
use Qualimetrix\Core\Metric\MetricName;
use Qualimetrix\Core\Metric\SymbolLevel;
use Qualimetrix\Metrics\AbstractCollector;
use SplFileInfo;

/**
 * Halstead Complexity Metrics Collector
 *
 * Implements complexity metrics based on Maurice Halstead's methodology (1977).
 *
 * ## Methodology
 *
 * Qualimetrix uses a **semantic approach** to token classification:
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
 * Qualimetrix uses a semantic interpretation of Halstead's methodology, measuring
 * algorithmic complexity rather than syntactic density. The original paper
 * counted all tokens, but was designed for languages with minimal syntax noise.
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
            MetricName::HALSTEAD_VOLUME,
            MetricName::HALSTEAD_DIFFICULTY,
            MetricName::HALSTEAD_EFFORT,
            MetricName::HALSTEAD_BUGS,
            MetricName::HALSTEAD_TIME,
            MetricName::HALSTEAD_METHOD_LOC,
        ];
    }

    /**
     * @param \PhpParser\Node[] $ast
     */
    public function collect(SplFileInfo $file, array $ast): MetricBag
    {
        $bag = new MetricBag();

        \assert($this->visitor instanceof HalsteadVisitor);

        foreach ($this->visitor->getMethodsWithMetrics() as $method) {
            $fqn = ($method->namespace ? $method->namespace . '\\' : '')
                . ($method->class ? $method->class . '::' : '')
                . $method->method;
            $metrics = $method->metrics;

            $bag = $bag
                ->with(MetricName::HALSTEAD_VOLUME . ':' . $fqn, $metrics->get('halstead.volume') ?? 0.0)
                ->with(MetricName::HALSTEAD_DIFFICULTY . ':' . $fqn, $metrics->get('halstead.difficulty') ?? 0.0)
                ->with(MetricName::HALSTEAD_EFFORT . ':' . $fqn, $metrics->get('halstead.effort') ?? 0.0)
                ->with(MetricName::HALSTEAD_BUGS . ':' . $fqn, $metrics->get('halstead.bugs') ?? 0.0)
                ->with(MetricName::HALSTEAD_TIME . ':' . $fqn, $metrics->get('halstead.time') ?? 0.0)
                ->with(MetricName::HALSTEAD_METHOD_LOC . ':' . $fqn, $metrics->get(MetricName::HALSTEAD_METHOD_LOC) ?? 0);
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
                AggregationStrategy::Percentile95,
            ],
            SymbolLevel::Project->value => [
                AggregationStrategy::Average,
                AggregationStrategy::Max,
                AggregationStrategy::Percentile95,
            ],
        ];

        return [
            new MetricDefinition(
                name: MetricName::HALSTEAD_VOLUME,
                collectedAt: SymbolLevel::Method,
                aggregations: $aggregations,
            ),
            new MetricDefinition(
                name: MetricName::HALSTEAD_DIFFICULTY,
                collectedAt: SymbolLevel::Method,
                aggregations: $aggregations,
            ),
            new MetricDefinition(
                name: MetricName::HALSTEAD_EFFORT,
                collectedAt: SymbolLevel::Method,
                aggregations: $aggregations,
            ),
            new MetricDefinition(
                name: MetricName::HALSTEAD_BUGS,
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
                name: MetricName::HALSTEAD_TIME,
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
