<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Evidence\ComputedMetrics\Health\Offender;

use Qualimetrix\Analysis\Evidence\ComputedMetrics\Health\Contract\Offender\WorstOffender;
use Qualimetrix\Analysis\Evidence\ComputedMetrics\Health\Metadata\HealthDimensionCatalog;
use Qualimetrix\Analysis\Finding\Contract\Violation;
use Qualimetrix\Core\Symbol\SymbolInfo;
use Qualimetrix\Core\Util\NamespaceMatcher;

final class WorstOffenderBuilder
{
    public function __construct(
        private readonly HealthDimensionCatalog $dimensions = new HealthDimensionCatalog(),
        private readonly HealthReasonBuilder $reasonBuilder = new HealthReasonBuilder(),
    ) {}

    /**
     * @param array{symbol: SymbolInfo, overall: float|null, dimensionScores: array<string, float>, loc: int|float|null, notableMetrics: array<string, int|float>} $snapshot
     */
    public function build(array $snapshot, WorstOffenderEvidence $evidence, float $warningThreshold, float $errorThreshold): ?WorstOffender
    {
        if ($snapshot['overall'] === null) {
            return null;
        }

        $symbol = $snapshot['symbol'];

        return WorstOffender::fromEvidence(
            $symbol->symbolPath,
            $symbol->symbolPath->type === null ? null : $symbol->file,
            $snapshot['overall'],
            $this->dimensions->getScoreLabel($snapshot['overall'], $warningThreshold, $errorThreshold),
            $this->reasonBuilder->buildReason($snapshot['dimensionScores']),
            new WorstOffenderEvidence(
                $evidence->violationCount,
                $evidence->classCount,
                $snapshot['notableMetrics'],
                $snapshot['dimensionScores'],
                WorstOffender::computeViolationDensity($evidence->violationCount, $snapshot['loc']),
            ),
        );
    }

    /**
     * @param iterable<array{symbol: SymbolInfo, overall: float|null, dimensionScores: array<string, float>, loc: int|float|null, notableMetrics: array<string, int|float>}> $snapshots
     * @param list<Violation> $violations
     *
     * @return list<WorstOffender>
     */
    public function buildWorstClasses(
        iterable $snapshots,
        string $namespace,
        array $violations,
        float $warningThreshold,
        float $errorThreshold,
    ): array {
        return $this->buildClassList($snapshots, $namespace, $violations, $warningThreshold, $errorThreshold);
    }

    /**
     * @param iterable<array{symbol: SymbolInfo, overall: float|null, dimensionScores: array<string, float>, loc: int|float|null, notableMetrics: array<string, int|float>}> $snapshots
     * @param list<Violation> $violations
     *
     * @return list<WorstOffender>
     */
    private function buildClassList(
        iterable $snapshots,
        string $namespace,
        array $violations,
        float $warningThreshold,
        float $errorThreshold,
    ): array {
        $violationCounts = $this->countClassViolations($violations);
        $offenders = [];

        foreach ($snapshots as $snapshot) {
            $symbol = $snapshot['symbol'];
            if (!NamespaceMatcher::matchesSingle($namespace, $symbol->symbolPath->namespace ?? '')) {
                continue;
            }

            $offender = $this->build(
                $snapshot,
                new WorstOffenderEvidence($violationCounts[$symbol->symbolPath->toCanonical()] ?? 0, 0, [], []),
                $warningThreshold,
                $errorThreshold,
            );
            if ($offender !== null) {
                $offenders[] = $offender;
            }
        }

        return $offenders;
    }

    /**
     * @param list<Violation> $violations
     *
     * @return array<string, int>
     */
    private function countClassViolations(array $violations): array
    {
        $counts = [];
        foreach ($violations as $violation) {
            if ($violation->symbolPath->type !== null) {
                $namespace = $violation->symbolPath->namespace ?? '';
                $class = 'class:' . ($namespace === '' ? '' : $namespace . '\\') . $violation->symbolPath->type;
                $counts[$class] = ($counts[$class] ?? 0) + 1;
            }
        }

        return $counts;
    }

}
