<?php

declare(strict_types=1);

namespace Qualimetrix\Metrics\Size;

use Override;
use PhpParser\Node;
use Qualimetrix\Analysis\Evidence\Measurement\Contract\AbstractCollector;
use Qualimetrix\Analysis\Evidence\Measurement\Contract\AggregationStrategy;
use Qualimetrix\Analysis\Evidence\Measurement\Contract\CallableMetricsProviderInterface;
use Qualimetrix\Analysis\Evidence\Measurement\Contract\CallableWithMetrics;
use Qualimetrix\Analysis\Evidence\Measurement\Contract\MetricBag;
use Qualimetrix\Analysis\Evidence\Measurement\Contract\MetricDefinition;
use Qualimetrix\Analysis\Evidence\Measurement\Contract\MetricName;
use Qualimetrix\Analysis\Evidence\Measurement\Contract\SymbolLevel;
use Qualimetrix\Core\Path\RelativePath;
use SplFileInfo;

/** Collects formatting-independent statement counts for methods and functions. */
final class MethodStatementCountCollector extends AbstractCollector implements CallableMetricsProviderInterface
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

        \assert($this->visitor instanceof MethodStatementCountVisitor);

        foreach ($this->visitor->getStatementCounts() as $fqn => $count) {
            $bag = $bag->with(
                MetricName::SIZE_METHOD_STATEMENT_COUNT . ':' . $fqn,
                $count,
            );
        }

        return $bag;
    }

    /** @return list<CallableWithMetrics> */
    public function getCallablesWithMetrics(RelativePath $file): array
    {
        \assert($this->visitor instanceof MethodStatementCountVisitor);

        return $this->visitor->getCallablesWithMetrics($file);
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
                collectedAt: SymbolLevel::Callable,
                aggregations: [
                    SymbolLevel::Class_->value => $aggregations,
                    SymbolLevel::Namespace_->value => $aggregations,
                    SymbolLevel::Project->value => $aggregations,
                ],
            ),
        ];
    }
}
