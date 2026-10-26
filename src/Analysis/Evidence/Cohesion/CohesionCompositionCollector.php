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
        return MetricReach::Run;
    }

    public function provides(): array
    {
        return [MetricName::COHESION_LCOM, MetricName::COHESION_TCC, MetricName::COHESION_LCC, MetricName::COHESION_PURE_METHOD_COUNT, MetricName::COHESION_LCOM_METHOD_COUNT, MetricName::COHESION_TCC_METHOD_COUNT, MetricName::COHESION_UNREAD_TRAIT_COUNT];
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
            new MetricDefinition(name: MetricName::COHESION_PURE_METHOD_COUNT, collectedAt: SymbolLevel::Class_, aggregations: [
                SymbolLevel::Namespace_->value => [AggregationStrategy::Average],
                SymbolLevel::Project->value => [AggregationStrategy::Average],
            ]),
            new MetricDefinition(name: MetricName::COHESION_LCOM_METHOD_COUNT, collectedAt: SymbolLevel::Class_, aggregations: []),
            new MetricDefinition(name: MetricName::COHESION_TCC_METHOD_COUNT, collectedAt: SymbolLevel::Class_, aggregations: [
                SymbolLevel::Namespace_->value => [AggregationStrategy::Average],
                SymbolLevel::Project->value => [AggregationStrategy::Average],
            ]),
            new MetricDefinition(name: MetricName::COHESION_UNREAD_TRAIT_COUNT, collectedAt: SymbolLevel::Class_, aggregations: []),
        ];
    }

    public function calculate(DependencyGraphInterface $graph, MetricRepositoryInterface $repository): void
    {
        $classes = [];
        $traits = [];
        foreach ($graph->getClassLikeDeclarations() as $declaration) {
            if (!\in_array($declaration->type, [ClassType::Class_, ClassType::Trait_], true)) {
                continue;
            }
            $subject = MetricSubject::declaration($declaration->declaration);
            $canonical = $declaration->declaration->toCanonical();
            $own = $this->readLcomFacts($subject, $canonical, $repository);
            if ($declaration->type === ClassType::Trait_) {
                $traits[CohesionTraitComposition::key($declaration->logical->symbolPath->toString())][] = $own;
                continue;
            }
            $classes[] = [$subject, $own, $this->readTccFacts($subject, $canonical, $repository)];
        }

        foreach ($classes as [$subject, $own, $ownTcc]) {
            try {
                $this->publishClass($subject, CohesionTraitComposition::compose($own, $ownTcc, $traits), $repository);
            } catch (\Qualimetrix\Core\Symbol\TraitComposition\Contract\TraitCompositionFailure $failure) {
                $declaration = $subject->declarationPath() ?? throw new LogicException('Cohesion composition requires an exact declaration.');
                throw $failure->at($declaration);
            }
        }
    }

    private function readLcomFacts(MetricSubject $subject, string $canonical, MetricRepositoryInterface $repository): LcomClassData
    {
        if (!$repository->hasSubject($subject)) {
            throw new LogicException('Cohesion requires an exact class declaration subject: ' . $canonical);
        }
        $rows = $repository->getSubject($subject)->entries(LcomCollector::FACTS);
        if ($rows === []) {
            throw new LogicException('Cohesion facts are missing for ' . $canonical);
        }

        return CohesionFactWire::lcomFromRows($rows);
    }

    private function readTccFacts(MetricSubject $subject, string $canonical, MetricRepositoryInterface $repository): TccLccClassData
    {
        $rows = $repository->getSubject($subject)->entries(TccLccCollector::FACTS);
        if ($rows === []) {
            throw new LogicException('Cohesion facts are missing for ' . $canonical);
        }

        return CohesionFactWire::tccFromRows($rows);
    }

    /** @param array{LcomClassData, TccLccClassData, int} $composed */
    private function publishClass(MetricSubject $subject, array $composed, MetricRepositoryInterface $repository): void
    {
        [$effective, $tcc, $unreadCount] = $composed;
        $repository->addSubjectScalar($subject, MetricName::COHESION_UNREAD_TRAIT_COUNT, $unreadCount);
        $this->publishLcom($subject, $effective, $unreadCount, $repository);
        $this->publishTccLcc($subject, $tcc, $repository);
    }

    private function publishLcom(MetricSubject $subject, LcomClassData $effective, int $unreadCount, MetricRepositoryInterface $repository): void
    {
        $methodCount = $effective->lcomMethodCount($this->configuration->excludedMethods, $unreadCount);
        $repository->addSubjectScalar($subject, MetricName::COHESION_LCOM_METHOD_COUNT, $methodCount);
        if ($methodCount > 0) {
            $repository->addSubjectScalar($subject, MetricName::COHESION_LCOM, $effective->calculateLcom($this->configuration->excludedMethods, $unreadCount));
        }
    }

    private function publishTccLcc(MetricSubject $subject, TccLccClassData $tcc, MetricRepositoryInterface $repository): void
    {
        $methodCount = \count($tcc->getMethods());
        $repository->addSubjectScalar($subject, MetricName::COHESION_TCC_METHOD_COUNT, $methodCount);
        if ($methodCount < 2 || !$this->hasInstanceProperties($tcc)) {
            return;
        }
        $repository->addSubjectScalar($subject, MetricName::COHESION_TCC, round($tcc->calculateTcc(), 3));
        $repository->addSubjectScalar($subject, MetricName::COHESION_LCC, round($tcc->calculateLcc(), 3));
        $repository->addSubjectScalar($subject, MetricName::COHESION_PURE_METHOD_COUNT, $this->pureMethodCount($tcc));
    }

    private function pureMethodCount(TccLccClassData $tcc): int
    {
        $count = 0;
        foreach ($tcc->getMethods() as $method) {
            if ($tcc->getPropertiesAccessedBy($method) === []) {
                ++$count;
            }
        }

        return $count;
    }

    private function hasInstanceProperties(TccLccClassData $data): bool
    {
        if ($data->getPropertyCount() > 0) {
            return true;
        }
        foreach ($data->getMethods() as $method) {
            if ($data->getPropertiesAccessedBy($method) !== []) {
                return true;
            }
        }

        return false;
    }

}
