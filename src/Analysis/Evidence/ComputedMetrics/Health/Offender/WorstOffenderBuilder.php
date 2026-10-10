<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Evidence\ComputedMetrics\Health\Offender;

use LogicException;
use Qualimetrix\Analysis\Evidence\ComputedMetrics\Contract\Definition\HealthDimension;
use Qualimetrix\Analysis\Evidence\ComputedMetrics\Health\Contract\Offender\WorstOffender;
use Qualimetrix\Analysis\Evidence\ComputedMetrics\Health\Metadata\HealthDimensionCatalog;
use Qualimetrix\Analysis\Evidence\Measurement\Contract\MetricRepositoryInterface;
use Qualimetrix\Analysis\Finding\Contract\Finding;
use Qualimetrix\Core\Symbol\MetricSubject;
use Qualimetrix\Core\Symbol\SymbolInfo;
use Qualimetrix\Core\Symbol\SymbolType;

/**
 * @qmx-threshold coupling.instability warning=0.866667 -- Offender assembly joins metric, finding and identity vocabularies through few callers; extracting those projections transfers the same outward dependencies.
 */
final class WorstOffenderBuilder
{
    public function __construct(
        private readonly HealthDimensionCatalog $dimensions = new HealthDimensionCatalog(),
        private readonly HealthReasonBuilder $reasonBuilder = new HealthReasonBuilder(),
    ) {}

    /**
     * @param array{symbol: SymbolInfo, overall: float|null, dimensionScores: array<string, float>, loc: int|float|null, notableMetrics: array<string, int|float>} $snapshot
     */
    public function build(array $snapshot, WorstOffenderEvidence $evidence, OffenderThresholds $thresholds): ?WorstOffender
    {
        if ($snapshot['overall'] === null) {
            return null;
        }

        [$warningThreshold, $errorThreshold] = $thresholds->pair(HealthDimension::Overall);
        $symbol = $snapshot['symbol'];

        return WorstOffender::fromEvidence(
            $symbol->subject ?? MetricSubject::aggregate($symbol->symbolPath),
            $snapshot['overall'],
            $this->dimensions->getScoreLabel($snapshot['overall'], $warningThreshold, $errorThreshold),
            $this->reasonBuilder->buildReason($snapshot['dimensionScores'], $thresholds),
            new WorstOffenderEvidence(
                $evidence->violationCount,
                $evidence->classCount,
                $snapshot['notableMetrics'],
                $snapshot['dimensionScores'],
                WorstOffender::computeViolationDensity($evidence->violationCount, $snapshot['loc']),
            ),
            [$warningThreshold, $errorThreshold],
        );
    }

    /**
     * @param list<Finding> $findings
     *
     * @return array<string, int>
     */
    public function countClassFindings(MetricRepositoryInterface $repository, array $findings): array
    {
        $callables = self::callablesBySubject($repository);
        $counts = [];
        foreach ($findings as $finding) {
            $owner = self::ownerForFinding($repository, $callables, $finding);
            if ($owner === null) {
                continue;
            }
            $key = $owner->toCanonical();
            $counts[$key] = ($counts[$key] ?? 0) + 1;
        }

        return $counts;
    }

    /** @return array<string, SymbolInfo> */
    private static function callablesBySubject(MetricRepositoryInterface $repository): array
    {
        $callables = [];
        foreach ($repository->allCallables() as $info) {
            if ($info->subject !== null) {
                $callables[$info->subject->toCanonical()] = $info;
            }
        }

        return $callables;
    }

    /** @param array<string, SymbolInfo> $callables */
    private static function ownerForFinding(MetricRepositoryInterface $repository, array $callables, Finding $finding): ?MetricSubject
    {
        $type = $finding->subject->declarationPath()?->logical->getType();
        if ($type === SymbolType::Class_) {
            return $finding->subject;
        }
        if ($type !== SymbolType::Method) {
            return null;
        }

        $subjectKey = $finding->subject->toCanonical();
        $info = $callables[$subjectKey] ?? throw new LogicException(
            'Missing exact callable metadata for class finding attribution: ' . $subjectKey,
        );

        return self::ownerForMethodFinding($repository, $info, $subjectKey);
    }

    private static function ownerForMethodFinding(MetricRepositoryInterface $repository, SymbolInfo $info, string $subjectKey): ?MetricSubject
    {
        $exactOwner = $info->classAggregationOwner;
        if ($exactOwner === null) {
            return null;
        }
        $owner = MetricSubject::declaration($exactOwner);
        if (!$repository->hasSubject($owner)) {
            throw new LogicException('Missing named-owner declaration for ' . $subjectKey);
        }

        return $owner;
    }
}
