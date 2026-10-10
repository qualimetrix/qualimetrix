<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Evidence\ComputedMetrics\Health\Offender;

use LogicException;
use Qualimetrix\Analysis\Evidence\ComputedMetrics\Contract\Definition\HealthDimension;
use Qualimetrix\Analysis\Evidence\ComputedMetrics\Health\Contract\Offender\RankBy;
use Qualimetrix\Analysis\Evidence\ComputedMetrics\Health\Contract\Offender\WorstOffender;
use Qualimetrix\Analysis\Evidence\Measurement\Contract\AggregationStrategy;
use Qualimetrix\Analysis\Evidence\Measurement\Contract\MetricBag;
use Qualimetrix\Analysis\Evidence\Measurement\Contract\MetricName;
use Qualimetrix\Analysis\Evidence\Measurement\Contract\MetricRepositoryInterface;
use Qualimetrix\Analysis\Evidence\Measurement\Contract\NamespaceTree;
use Qualimetrix\Analysis\Finding\Contract\Finding;
use Qualimetrix\Core\Symbol\ClassNameSpelling;
use Qualimetrix\Core\Symbol\SymbolLevel;

use Qualimetrix\Core\Symbol\SymbolPath;

final readonly class OffenderRanking
{
    public function __construct(private WorstOffenderBuilder $offenderBuilder = new WorstOffenderBuilder()) {}

    /** @param list<Finding> $findings */
    public function rank(MetricRepositoryInterface $metrics, NamespaceTree $tree, array $findings, OffenderThresholds $thresholds): RankedOffenders
    {
        $population = new OffenderPopulation($metrics, $tree, $findings);

        return new RankedOffenders(
            $this->buildWorstOffenders($population, SymbolLevel::Namespace_, $thresholds),
            $this->buildWorstOffenders($population, SymbolLevel::Class_, $thresholds),
        );
    }

    /** @return list<WorstOffender> */
    private function buildWorstOffenders(OffenderPopulation $population, SymbolLevel $level, OffenderThresholds $thresholds): array
    {
        $candidates = self::rankedCandidates($population, $level);
        $violationCounts = $this->countFindingsPerSymbol($population->repository, $population->findings, $level, $population->tree);
        $offenders = [];

        foreach ($candidates as $candidate) {
            $offenders[] = $this->offenderFromCandidate($candidate, $violationCounts, $level, $thresholds)
                ?? throw new LogicException('Ranked offender candidates require an overall score');
        }

        return WorstOffender::rank($offenders, RankBy::Score);
    }

    /** @return list<array{score: float, info: \Qualimetrix\Core\Symbol\SymbolInfo, metrics: MetricBag}> */
    private static function rankedCandidates(OffenderPopulation $population, SymbolLevel $level): array
    {
        $candidates = [];
        $repository = $population->repository;
        $symbols = $population->symbols($level);
        foreach ($symbols as $symbolInfo) {
            $metrics = $symbolInfo->subject === null
                ? $repository->get($symbolInfo->symbolPath)
                : $repository->getSubject($symbolInfo->subject);
            $healthOverall = $metrics->get(HealthDimension::Overall->value);

            if ($healthOverall === null) {
                continue;
            }

            $scoreValue = (float) $healthOverall;

            if ($level === SymbolLevel::Namespace_ && !$population->declaresType($symbolInfo->symbolPath->namespace ?? '')) {
                continue;
            }

            $candidates[] = ['score' => $scoreValue, 'info' => $symbolInfo, 'metrics' => $metrics];
        }

        return $candidates;
    }

    /**
     * @param array{score: float, info: \Qualimetrix\Core\Symbol\SymbolInfo, metrics: MetricBag} $candidate
     * @param array<string, int> $violationCounts
     */
    private function offenderFromCandidate(
        array $candidate,
        array $violationCounts,
        SymbolLevel $level,
        OffenderThresholds $thresholds,
    ): ?WorstOffender {
        $symbolInfo = $candidate['info'];
        $metrics = $candidate['metrics'];
        $symbolCanonical = $level === SymbolLevel::Namespace_
            ? SymbolPath::forNamespace(ClassNameSpelling::fold($symbolInfo->symbolPath->namespace ?? ''))->toCanonical()
            : ($symbolInfo->subject?->toCanonical() ?? $symbolInfo->symbolPath->toCanonical());
        $classCount = $level === SymbolLevel::Namespace_
            ? (int) ($metrics->get(MetricName::agg(MetricName::SIZE_CLASS_COUNT, AggregationStrategy::Sum)) ?? 0)
            : 0;

        return $this->offenderBuilder->build(
            [
                'symbol' => $symbolInfo,
                'overall' => $candidate['score'],
                'dimensionScores' => $this->getPerDimensionScores($metrics),
                'loc' => $metrics->get(
                    $level === SymbolLevel::Namespace_
                        ? MetricName::agg(MetricName::SIZE_LOC, AggregationStrategy::Sum)
                        : MetricName::SIZE_CLASS_LOC,
                ),
                'notableMetrics' => $this->getNotableMetrics($metrics, $level),
            ],
            new WorstOffenderEvidence($violationCounts[$symbolCanonical] ?? 0, $classCount, [], []),
            $thresholds,
        );
    }

    /**
     * @return array<string, float>
     */
    private function getPerDimensionScores(MetricBag $metrics): array
    {
        $scores = [];

        foreach (HealthDimension::all() as $dim) {
            if ($dim === HealthDimension::Overall) {
                continue;
            }
            $value = $metrics->get($dim->value);

            if ($value !== null) {
                $scores[$dim->shortName()] = (float) $value;
            }
        }

        return $scores;
    }

    /**
     * @param list<Finding> $findings
     *
     * @return array<string, int>
     */
    private function countFindingsPerSymbol(MetricRepositoryInterface $repository, array $findings, SymbolLevel $level, NamespaceTree $tree): array
    {
        if ($level === SymbolLevel::Class_) {
            return $this->offenderBuilder->countClassFindings($repository, $findings);
        }
        return $this->countNamespaceFindings($findings, $tree);
    }

    /**
     * @param list<Finding> $findings
     *
     * @return array<string, int>
     */
    private function countNamespaceFindings(array $findings, NamespaceTree $tree): array
    {
        $tree = new NamespaceTree(array_map(ClassNameSpelling::fold(...), $tree->getAllNamespaces()));
        $counts = [];

        foreach ($findings as $finding) {
            $ns = $finding->subject->declarationPath()?->logical->namespace ?? $finding->symbolPath->namespace;
            if ($ns === null || $ns === '') {
                continue;
            }
            $ns = ClassNameSpelling::fold($ns);
            $key = SymbolPath::forNamespace($ns)->toCanonical();
            $counts[$key] = ($counts[$key] ?? 0) + 1;

            foreach ($tree->getAncestors($ns) as $ancestor) {
                $key = SymbolPath::forNamespace($ancestor)->toCanonical();
                $counts[$key] = ($counts[$key] ?? 0) + 1;
            }
        }

        return $counts;
    }

    /**
     * @return array<string, int|float>
     */
    private function getNotableMetrics(MetricBag $metrics, SymbolLevel $level): array
    {
        $notable = [];
        $keys = $level === SymbolLevel::Class_
            ? [
                MetricName::SIZE_METHOD_COUNT,
                MetricName::SIZE_PROPERTY_COUNT,
                MetricName::COUPLING_CBO,
                MetricName::agg(MetricName::COMPLEXITY_CCN, AggregationStrategy::Average),
                MetricName::COHESION_TCC,
                MetricName::COMPLEXITY_WMC,
                MetricName::agg(MetricName::MAINTAINABILITY_MI, AggregationStrategy::Average),
            ]
            : [];

        foreach ($keys as $key) {
            $value = $metrics->get($key);

            if ($value !== null) {
                $notable[$key] = $value;
            }
        }

        return $notable;
    }

}
