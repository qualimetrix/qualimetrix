<?php

declare(strict_types=1);

namespace Qualimetrix\Metrics\Size;

use Override;
use PhpParser\Node;
use Qualimetrix\Core\Metric\AggregationStrategy;
use Qualimetrix\Core\Metric\MethodMetricsProviderInterface;
use Qualimetrix\Core\Metric\MethodWithMetrics;
use Qualimetrix\Core\Metric\MetricBag;
use Qualimetrix\Core\Metric\MetricDefinition;
use Qualimetrix\Core\Metric\MetricName;
use Qualimetrix\Core\Metric\SymbolLevel;
use Qualimetrix\Metrics\AbstractCollector;
use SplFileInfo;

/** Collects formatting-independent statement counts for methods and functions. */
final class MethodStatementCountCollector extends AbstractCollector implements MethodMetricsProviderInterface
{
    private const NAME = 'method-statement-count';

    public function __construct()
    {
        $this->visitor = new MethodStatementCountVisitor();
    }

    public function getName(): string
    {
        return self::NAME;
    }

    /** @return list<string> */
    public function provides(): array
    {
        return [MetricName::SIZE_METHOD_STATEMENT_COUNT];
    }

    /** @param Node[] $ast */
    public function collect(SplFileInfo $file, array $ast): MetricBag
    {
        $bag = new MetricBag();

        foreach ($this->getMethodsWithMetrics() as $method) {
            $fqn = ($method->namespace !== null ? $method->namespace . '\\' : '')
                . ($method->class !== null ? $method->class . '::' : '')
                . $method->method;
            $bag = $bag->with(
                MetricName::SIZE_METHOD_STATEMENT_COUNT . ':' . $fqn,
                $method->metrics->get(MetricName::SIZE_METHOD_STATEMENT_COUNT) ?? 0,
            );
        }

        return $bag;
    }

    /** @return list<MethodWithMetrics> */
    public function getMethodsWithMetrics(): array
    {
        \assert($this->visitor instanceof MethodStatementCountVisitor);

        return $this->visitor->getMethodsWithMetrics();
    }

    /** @return list<MetricDefinition> */
    #[Override]
    public function getMetricDefinitions(): array
    {
        $aggregations = [
            AggregationStrategy::Sum,
            AggregationStrategy::Average,
            AggregationStrategy::Max,
        ];

        return [
            new MetricDefinition(
                name: MetricName::SIZE_METHOD_STATEMENT_COUNT,
                collectedAt: SymbolLevel::Method,
                aggregations: [
                    SymbolLevel::Class_->value => $aggregations,
                    SymbolLevel::Namespace_->value => $aggregations,
                    SymbolLevel::Project->value => $aggregations,
                ],
            ),
        ];
    }
}
