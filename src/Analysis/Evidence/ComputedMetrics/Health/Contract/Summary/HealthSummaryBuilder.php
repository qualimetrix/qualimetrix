<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Evidence\ComputedMetrics\Health\Contract\Summary;

use LogicException;
use Qualimetrix\Analysis\Evidence\ComputedMetrics\Contract\Definition\ComputedMetricDefinitionCatalogInterface;
use Qualimetrix\Analysis\Evidence\ComputedMetrics\Contract\Definition\HealthDimension;
use Qualimetrix\Analysis\Evidence\ComputedMetrics\Health\Contract\Offender\WorstOffender;
use Qualimetrix\Analysis\Evidence\ComputedMetrics\Health\Metadata\HealthMetricCatalog;
use Qualimetrix\Analysis\Evidence\ComputedMetrics\Health\Offender\WorstOffenderBuilder;
use Qualimetrix\Analysis\Evidence\ComputedMetrics\Health\Offender\WorstOffenderEvidence;
use Qualimetrix\Analysis\Evidence\ComputedMetrics\Health\Score\ProjectHealthScoreBuilder;
use Qualimetrix\Analysis\Evidence\Measurement\Contract\AggregationStrategy;
use Qualimetrix\Analysis\Evidence\Measurement\Contract\MetricBag;
use Qualimetrix\Analysis\Evidence\Measurement\Contract\MetricName;
use Qualimetrix\Analysis\Evidence\Measurement\Contract\MetricRepositoryInterface;
use Qualimetrix\Analysis\Evidence\Measurement\Contract\NamespaceTree;
use Qualimetrix\Analysis\Finding\Contract\Finding;
use Qualimetrix\Core\Symbol\SymbolLevel;
use Qualimetrix\Core\Symbol\SymbolPath;

/**
 * Builds health scores and offender projections from measured evidence.
 */
final readonly class HealthSummaryBuilder
{
    private const int DEFAULT_TOP_NAMESPACES = 10;
    private const int DEFAULT_TOP_CLASSES = 10;

    private ProjectHealthScoreBuilder $projectScores;
    private WorstOffenderBuilder $offenderBuilder;

    public function __construct(
        private HealthMetricCatalog $hintProvider,
        private ComputedMetricDefinitionCatalogInterface $definitionCatalog,
    ) {
        $this->projectScores = new ProjectHealthScoreBuilder($this->hintProvider, $this->definitionCatalog);
        $this->offenderBuilder = new WorstOffenderBuilder();
    }

    /** @param list<Finding> $findings */
    public function build(
        MetricRepositoryInterface $metrics,
        NamespaceTree $tree,
        array $findings,
    ): HealthSummary {
        $healthScores = $this->projectScores->build($metrics);
        // The levels ranked here are published as
        // RankedOffenderLevels::LEVELS: a caller asking what a `--namespace`
        // value can select has to know which symbols get a canonical name into
        // a comparison, and a second enumeration of that would drift.
        $worstNamespaces = $this->buildWorstOffenders($metrics, $findings, SymbolLevel::Namespace_, self::DEFAULT_TOP_NAMESPACES, $tree);
        $worstClasses = $this->buildWorstOffenders($metrics, $findings, SymbolLevel::Class_, self::DEFAULT_TOP_CLASSES, $tree);

        return new HealthSummary(
            healthScores: $healthScores,
            worstNamespaces: $worstNamespaces,
            worstClasses: $worstClasses,
        );
    }

    /**
     * @param list<Finding> $findings
     *
     * @return list<WorstOffender>
     */
    private function buildWorstOffenders(
        MetricRepositoryInterface $repository,
        array $findings,
        SymbolLevel $level,
        int $limit,
        NamespaceTree $tree,
    ): array {
        $definition = $this->definitionCatalog->find(HealthDimension::Overall->value);
        $warnThreshold = $definition->warningThreshold ?? 50.0;
        $errorThreshold = $definition->errorThreshold ?? 30.0;
        $candidates = self::rankedCandidates($repository, $level);
        $violationCounts = $this->countFindingsPerSymbol($repository, $findings, $level, $tree);
        $offenders = [];

        foreach (\array_slice($candidates, 0, $limit) as $candidate) {
            $offenders[] = $this->offenderFromCandidate($candidate, $violationCounts, $level, $warnThreshold, $errorThreshold)
                ?? throw new LogicException('Ranked offender candidates require an overall score');
        }

        return $offenders;
    }

    /** @return list<array{score: float, info: \Qualimetrix\Core\Symbol\SymbolInfo, metrics: MetricBag}> */
    private static function rankedCandidates(MetricRepositoryInterface $repository, SymbolLevel $level): array
    {
        $candidates = [];

        $symbols = $level === SymbolLevel::Class_ ? $repository->allClassDeclarations() : $repository->all($level);
        foreach ($symbols as $symbolInfo) {
            $metrics = $symbolInfo->subject === null
                ? $repository->get($symbolInfo->symbolPath)
                : $repository->getSubject($symbolInfo->subject);
            $healthOverall = $metrics->get(HealthDimension::Overall->value);

            if ($healthOverall === null) {
                continue;
            }

            $scoreValue = (float) $healthOverall;

            // Skip namespaces with no direct classes (e.g., root namespace
            // containers like "PHPUnit"). The unsuffixed key is the namespace's
            // own count; the `.sum` this used to read is the subtree's, which is
            // positive for exactly the containers named here — so the guard let
            // through the case it was written for, and a container ranked beside
            // its own children carrying their weight.
            if ($level === SymbolLevel::Namespace_ && (int) ($metrics->get(MetricName::SIZE_CLASS_COUNT) ?? 0) === 0) {
                continue;
            }

            $candidates[] = ['score' => $scoreValue, 'info' => $symbolInfo, 'metrics' => $metrics];
        }

        // Sort by score ascending (worst first), with stable secondary sort by canonical path
        usort($candidates, static fn(array $a, array $b): int => ($a['score'] <=> $b['score']) !== 0 ? ($a['score'] <=> $b['score'])
                : (($a['info']->subject?->toCanonical() ?? $a['info']->symbolPath->toCanonical())
                    <=> ($b['info']->subject?->toCanonical() ?? $b['info']->symbolPath->toCanonical())));

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
        float $warnThreshold,
        float $errorThreshold,
    ): ?WorstOffender {
        $symbolInfo = $candidate['info'];
        $metrics = $candidate['metrics'];
        $symbolCanonical = $symbolInfo->subject?->toCanonical() ?? $symbolInfo->symbolPath->toCanonical();
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
            $warnThreshold,
            $errorThreshold,
        );
    }

    /**
     * @return array<string, float>
     */
    private function getPerDimensionScores(MetricBag $metrics): array
    {
        $scores = [];

        foreach (HealthDimension::all() as $dim) {
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
        $counts = [];

        foreach ($findings as $finding) {
            $ns = $finding->symbolPath->namespace;
            if ($ns === null || $ns === '') {
                continue;
            }
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
                MetricName::SIZE_LOC,
            ]
            : [
                MetricName::agg(MetricName::SIZE_CLASS_COUNT, AggregationStrategy::Sum),
                MetricName::agg(MetricName::COUPLING_CBO, AggregationStrategy::Average),
                MetricName::agg(MetricName::COMPLEXITY_CCN, AggregationStrategy::Average),
                MetricName::COUPLING_DISTANCE,
                MetricName::agg(MetricName::MAINTAINABILITY_MI, AggregationStrategy::Average),
            ];

        foreach ($keys as $key) {
            $value = $metrics->get($key);

            if ($value !== null) {
                $notable[$key] = $value;
            }
        }

        return $notable;
    }

}
