<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Evidence\Coupling\Rfc;

use Qualimetrix\Analysis\Evidence\DependencyModel\Contract\DependencyGraphInterface;
use Qualimetrix\Analysis\Evidence\Measurement\Contract\AggregationStrategy;
use Qualimetrix\Analysis\Evidence\Measurement\Contract\GlobalContextCollectorInterface;
use Qualimetrix\Analysis\Evidence\Measurement\Contract\MetricDefinition;
use Qualimetrix\Analysis\Evidence\Measurement\Contract\MetricName;
use Qualimetrix\Analysis\Evidence\Measurement\Contract\MetricReach;
use Qualimetrix\Analysis\Evidence\Measurement\Contract\MetricRepositoryInterface;
use Qualimetrix\Core\Symbol\MetricSubject;
use Qualimetrix\Core\Symbol\SymbolLevel;

/**
 * Publishes RFC = |M| + |Ri outside M| on exact declarations.
 * M counts exposed own and imported methods, including abstract methods and
 * property hooks. It also counts an enum's implicit cases() method and a backed
 * enum's implicit from() and tryFrom() methods. Responses resolve declared targets
 * when the run knows them; unknown receivers and unread ancestors retain the
 * method-name fallback. Global functions are a PHP adaptation and use a
 * separate response identity.
 */
final class RfcGlobalCollector implements GlobalContextCollectorInterface
{
    public function getName(): string
    {
        return 'rfc-global';
    }

    public function requires(): array
    {
        return [RfcCollector::FACTS];
    }

    public function getMetricReach(): MetricReach
    {
        return MetricReach::Run;
    }

    public function provides(): array
    {
        return [MetricName::COUPLING_RFC, MetricName::COUPLING_RFC_OWN, MetricName::COUPLING_RFC_EXTERNAL];
    }

    public function getMetricDefinitions(): array
    {
        $definitions = [];
        foreach ($this->provides() as $name) {
            $definitions[] = new MetricDefinition(name: $name, collectedAt: SymbolLevel::Class_, aggregations: [
                SymbolLevel::Namespace_->value => [AggregationStrategy::Sum, AggregationStrategy::Average, AggregationStrategy::Max, AggregationStrategy::Percentile95],
                SymbolLevel::Project->value => [AggregationStrategy::Sum, AggregationStrategy::Average, AggregationStrategy::Max, AggregationStrategy::Percentile95],
            ]);
        }

        return $definitions;
    }

    public function calculate(DependencyGraphInterface $graph, MetricRepositoryInterface $repository): void
    {
        $resolver = new RfcResponseResolver(new RfcDeclarationIndex($graph, $repository));
        foreach ($resolver->declarations() as $declaration) {
            $counts = $resolver->counts($declaration);
            $subject = MetricSubject::declaration($declaration->declaration);
            $repository->addSubjectScalar($subject, MetricName::COUPLING_RFC, $counts['own'] + $counts['external']);
            $repository->addSubjectScalar($subject, MetricName::COUPLING_RFC_OWN, $counts['own']);
            $repository->addSubjectScalar($subject, MetricName::COUPLING_RFC_EXTERNAL, $counts['external']);
        }
    }
}
