<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Evidence\Cohesion;

use LogicException;
use Qualimetrix\Analysis\Evidence\Cohesion\Contract\LcomCollectionConfigurableInterface;
use Qualimetrix\Analysis\Evidence\Cohesion\Contract\LcomCollectionConfiguration;
use Qualimetrix\Analysis\Evidence\DependencyModel\Contract\DependencyGraphInterface;
use Qualimetrix\Analysis\Evidence\Measurement\Contract\AggregationStrategy;
use Qualimetrix\Analysis\Evidence\Measurement\Contract\GlobalContextCollectorInterface;
use Qualimetrix\Analysis\Evidence\Measurement\Contract\MetricDefinition;
use Qualimetrix\Analysis\Evidence\Measurement\Contract\MetricName;
use Qualimetrix\Analysis\Evidence\Measurement\Contract\MetricRepositoryInterface;
use Qualimetrix\Core\Symbol\ClassType;
use Qualimetrix\Core\Symbol\MetricSubject;
use Qualimetrix\Core\Symbol\SymbolLevel;

/** Calculates class cohesion from exact declaration facts after file collection. */
final class CohesionCompositionCollector implements GlobalContextCollectorInterface, LcomCollectionConfigurableInterface
{
    private LcomCollectionConfiguration $configuration;

    public function __construct()
    {
        $this->configuration = LcomCollectionConfiguration::defaults();
    }

    public function applyLcomCollectionConfiguration(LcomCollectionConfiguration $configuration): void
    {
        $this->configuration = $configuration;
    }

    public function getName(): string
    {
        return 'cohesion-composition';
    }

    public function requires(): array
    {
        return [LcomCollector::FACTS, TccLccCollector::FACTS];
    }

    public function provides(): array
    {
        return [MetricName::COHESION_LCOM, MetricName::COHESION_TCC, MetricName::COHESION_LCC, MetricName::COHESION_PURE_METHOD_COUNT];
    }

    /** @return list<MetricDefinition> */
    public function getMetricDefinitions(): array
    {
        return [
            new MetricDefinition(
                name: MetricName::COHESION_LCOM,
                collectedAt: SymbolLevel::Class_,
                aggregations: [
                    SymbolLevel::Namespace_->value => [AggregationStrategy::Average, AggregationStrategy::Max, AggregationStrategy::Percentile95],
                    SymbolLevel::Project->value => [AggregationStrategy::Average, AggregationStrategy::Max, AggregationStrategy::Percentile95],
                ],
            ),
            new MetricDefinition(
                name: MetricName::COHESION_TCC,
                collectedAt: SymbolLevel::Class_,
                aggregations: [
                    SymbolLevel::Namespace_->value => [AggregationStrategy::Average, AggregationStrategy::Min],
                    SymbolLevel::Project->value => [AggregationStrategy::Average, AggregationStrategy::Min],
                ],
            ),
            new MetricDefinition(
                name: MetricName::COHESION_LCC,
                collectedAt: SymbolLevel::Class_,
                aggregations: [
                    SymbolLevel::Namespace_->value => [AggregationStrategy::Average, AggregationStrategy::Min],
                    SymbolLevel::Project->value => [AggregationStrategy::Average, AggregationStrategy::Min],
                ],
            ),
            new MetricDefinition(name: MetricName::COHESION_PURE_METHOD_COUNT, collectedAt: SymbolLevel::Class_, aggregations: []),
        ];
    }

    public function calculate(DependencyGraphInterface $graph, MetricRepositoryInterface $repository): void
    {
        foreach ($graph->getClassLikeDeclarations() as $declaration) {
            if ($declaration->type !== ClassType::Class_) {
                continue;
            }
            $subject = MetricSubject::declaration($declaration->declaration);
            if (!$repository->hasSubject($subject)) {
                throw new LogicException('Cohesion requires an exact class declaration subject: ' . $declaration->declaration->toCanonical());
            }
            $bag = $repository->getSubject($subject);
            $lcomRows = $bag->entries(LcomCollector::FACTS);
            $tccRows = $bag->entries(TccLccCollector::FACTS);
            if ($lcomRows === [] || $tccRows === []) {
                throw new LogicException('Cohesion facts are missing for ' . $declaration->declaration->toCanonical());
            }

            $lcomData = LcomClassData::fromRows($lcomRows);
            $lcom = $lcomData->calculateLcom($this->configuration->excludedMethods);
            if ($lcom > 1 && $lcomData->hasOnlyTrivialMethods()) {
                $lcom = 1;
            }
            $repository->addSubjectScalar($subject, MetricName::COHESION_LCOM, $lcom);

            $tccData = TccLccClassData::fromRows($tccRows);
            if (\count($tccData->getMethods()) < 2 || $tccData->getPropertyCount() === 0) {
                continue;
            }
            $repository->addSubjectScalar($subject, MetricName::COHESION_TCC, round($tccData->calculateTcc(), 3));
            $repository->addSubjectScalar($subject, MetricName::COHESION_LCC, round($tccData->calculateLcc(), 3));

            $pureCount = 0;
            foreach ($tccData->getMethods() as $method) {
                if ($tccData->getPropertiesAccessedBy($method) === []) {
                    ++$pureCount;
                }
            }
            $repository->addSubjectScalar($subject, MetricName::COHESION_PURE_METHOD_COUNT, $pureCount);
        }
    }
}
