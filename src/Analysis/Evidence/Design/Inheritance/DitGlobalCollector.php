<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Evidence\Design\Inheritance;

use LogicException;
use Qualimetrix\Analysis\Evidence\DependencyModel\Contract\DependencyGraphInterface;
use Qualimetrix\Analysis\Evidence\Measurement\Contract\AggregationStrategy;
use Qualimetrix\Analysis\Evidence\Measurement\Contract\GlobalContextCollectorInterface;
use Qualimetrix\Analysis\Evidence\Measurement\Contract\MetricDefinition;
use Qualimetrix\Analysis\Evidence\Measurement\Contract\MetricName;
use Qualimetrix\Analysis\Evidence\Measurement\Contract\MetricRepositoryInterface;
use Qualimetrix\Core\Symbol\MetricSubject;
use Qualimetrix\Core\Symbol\SymbolLevel;

/** Publishes exact declaration inheritance evidence from the positive graph class roster. */
final class DitGlobalCollector implements GlobalContextCollectorInterface
{
    private const NAME = 'dit-global';

    public function __construct(private readonly ExternalAncestry $externalAncestry) {}

    public function getName(): string
    {
        return self::NAME;
    }

    public function requires(): array
    {
        return [];
    }

    public function provides(): array
    {
        return [MetricName::DESIGN_DIT, MetricName::DESIGN_DIT_UNRESOLVED, MetricName::DESIGN_IS_EXCEPTION];
    }

    public function getMetricDefinitions(): array
    {
        return [
            new MetricDefinition(
                name: MetricName::DESIGN_DIT,
                collectedAt: SymbolLevel::Class_,
                aggregations: [
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
                ],
            ),
            new MetricDefinition(
                MetricName::DESIGN_DIT_UNRESOLVED,
                SymbolLevel::Class_,
                aggregations: [
                    SymbolLevel::Namespace_->value => [AggregationStrategy::Sum],
                    SymbolLevel::Project->value => [AggregationStrategy::Sum],
                ],
            ),
            new MetricDefinition(
                MetricName::DESIGN_IS_EXCEPTION,
                SymbolLevel::Class_,
                aggregations: [
                    SymbolLevel::Namespace_->value => [AggregationStrategy::Sum],
                    SymbolLevel::Project->value => [AggregationStrategy::Sum],
                ],
            ),
        ];
    }

    public function calculate(DependencyGraphInterface $graph, MetricRepositoryInterface $repository): void
    {
        $resolver = InheritanceDepthResolver::fromGraph($graph, $this->externalAncestry);
        foreach ($graph->getClassLikeDeclarations() as $fact) {
            $subject = MetricSubject::declaration($fact->declaration);
            if (!$repository->hasSubject($subject)) {
                throw new LogicException('Graph class-like declaration requires an exact repository subject: ' . $fact->declaration->toCanonical());
            }
            if ($fact->type !== \Qualimetrix\Core\Symbol\ClassType::Class_) {
                $repository->addSubjectScalar($subject, MetricName::DESIGN_IS_EXCEPTION, 0);
                continue;
            }
            $answer = $resolver->depthOf($fact->declaration);
            if ($answer->depth !== null) {
                $repository->addSubjectScalar($subject, MetricName::DESIGN_DIT, $answer->depth);
            }
            $repository->addSubjectScalar($subject, MetricName::DESIGN_DIT_UNRESOLVED, $answer->outcome === InheritanceOutcome::Exact ? 0 : 1);
            if ($answer->reachesThrowable !== null) {
                $repository->addSubjectScalar($subject, MetricName::DESIGN_IS_EXCEPTION, $answer->reachesThrowable ? 1 : 0);
            }
        }
    }
}
