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
use Qualimetrix\Analysis\Evidence\Measurement\Contract\MetricReach;
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

    public function getMetricReach(): MetricReach
    {
        return MetricReach::Members;
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
            $this->calculateClass($subject, $declaration->declaration->toCanonical(), $repository);
        }
    }

    private function calculateClass(MetricSubject $subject, string $declaration, MetricRepositoryInterface $repository): void
    {
        if (!$repository->hasSubject($subject)) {
            throw new LogicException('Cohesion requires an exact class declaration subject: ' . $declaration);
        }
        $bag = $repository->getSubject($subject);
        $lcomRows = $bag->entries(LcomCollector::FACTS);
        $tccRows = $bag->entries(TccLccCollector::FACTS);
        if ($lcomRows === [] || $tccRows === []) {
            throw new LogicException('Cohesion facts are missing for ' . $declaration);
        }

        $this->publishLcom($subject, $lcomRows, $repository);
        $this->publishTccLcc($subject, $tccRows, $repository);
    }

    /** @param list<array<string, scalar>> $rows */
    private function publishLcom(MetricSubject $subject, array $rows, MetricRepositoryInterface $repository): void
    {
        $data = CohesionFactWire::lcomFromRows($rows);
        $lcom = $data->calculateLcom($this->configuration->excludedMethods);
        if ($lcom > 1 && $data->hasOnlyTrivialMethods()) {
            $lcom = 1;
        }
        $repository->addSubjectScalar($subject, MetricName::COHESION_LCOM, $lcom);
    }

    /** @param list<array<string, scalar>> $rows */
    private function publishTccLcc(MetricSubject $subject, array $rows, MetricRepositoryInterface $repository): void
    {
        $data = CohesionFactWire::tccFromRows($rows);
        if (\count($data->getMethods()) < 2 || $data->getPropertyCount() === 0) {
            return;
        }
        $repository->addSubjectScalar($subject, MetricName::COHESION_TCC, round($data->calculateTcc(), 3));
        $repository->addSubjectScalar($subject, MetricName::COHESION_LCC, round($data->calculateLcc(), 3));

        $pureCount = 0;
        foreach ($data->getMethods() as $method) {
            if ($data->getPropertiesAccessedBy($method) === []) {
                ++$pureCount;
            }
        }
        $repository->addSubjectScalar($subject, MetricName::COHESION_PURE_METHOD_COUNT, $pureCount);
    }
}
