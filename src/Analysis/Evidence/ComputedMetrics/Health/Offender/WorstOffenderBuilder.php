<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Evidence\ComputedMetrics\Health\Offender;

use LogicException;
use Qualimetrix\Analysis\Evidence\ComputedMetrics\Health\Contract\Offender\WorstOffender;
use Qualimetrix\Analysis\Evidence\ComputedMetrics\Health\Metadata\HealthDimensionCatalog;
use Qualimetrix\Analysis\Evidence\Measurement\Contract\MetricRepositoryInterface;
use Qualimetrix\Analysis\Finding\Contract\Finding;
use Qualimetrix\Core\Pattern\NamespacePattern;
use Qualimetrix\Core\Symbol\CallableKind;
use Qualimetrix\Core\Symbol\DeclarationPath;
use Qualimetrix\Core\Symbol\LogicalClassPath;
use Qualimetrix\Core\Symbol\MetricSubject;
use Qualimetrix\Core\Symbol\SymbolInfo;
use Qualimetrix\Core\Symbol\SymbolType;

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
     * @param list<Finding> $findings
     *
     * @return list<WorstOffender>
     */
    public function buildWorstClasses(
        MetricRepositoryInterface $repository,
        iterable $snapshots,
        NamespacePattern $namespace,
        array $findings,
        float $warningThreshold,
        float $errorThreshold,
    ): array {
        return $this->buildClassList($repository, $snapshots, $namespace, $findings, $warningThreshold, $errorThreshold);
    }

    /**
     * @param iterable<array{symbol: SymbolInfo, overall: float|null, dimensionScores: array<string, float>, loc: int|float|null, notableMetrics: array<string, int|float>}> $snapshots
     * @param list<Finding> $findings
     *
     * @return list<WorstOffender>
     */
    private function buildClassList(
        MetricRepositoryInterface $repository,
        iterable $snapshots,
        NamespacePattern $namespace,
        array $findings,
        float $warningThreshold,
        float $errorThreshold,
    ): array {
        $violationCounts = $this->countClassFindings($repository, $findings);
        $offenders = [];

        foreach ($snapshots as $snapshot) {
            $symbol = $snapshot['symbol'];
            if (!$namespace->matches($symbol->symbolPath->namespace ?? '')) {
                continue;
            }

            $offender = $this->build(
                $snapshot,
                new WorstOffenderEvidence($violationCounts[$symbol->subject?->toCanonical() ?? ''] ?? 0, 0, [], []),
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
        $logicalOwner = $info->classAggregationOwner;
        $exactOwner = $info->classAggregationOwnerDeclaration;
        if (!\in_array($info->callableKind, [CallableKind::Method, CallableKind::PropertyHook], true)) {
            self::assertNoOwner($logicalOwner, $exactOwner, 'Non-method callable cannot have a class owner: ' . $subjectKey);

            return null;
        }
        if ($info->anonymousClassContext) {
            self::assertNoOwner($logicalOwner, $exactOwner, 'Anonymous-class callable cannot have a named class owner: ' . $subjectKey);

            return null;
        }
        if (($logicalOwner === null) !== ($exactOwner === null)) {
            throw new LogicException('Invalid paired class owner metadata for ' . $subjectKey);
        }
        if ($logicalOwner === null) {
            throw new LogicException('Named callable requires paired class owner metadata for ' . $subjectKey);
        }
        if ($exactOwner === null) {
            throw new LogicException('Named callable owner requires an exact declaration');
        }

        return self::validatedNamedOwner($repository, $exactOwner, $logicalOwner, $subjectKey);
    }

    private static function assertNoOwner(?LogicalClassPath $logicalOwner, ?DeclarationPath $exactOwner, string $message): void
    {
        if ($logicalOwner !== null || $exactOwner !== null) {
            throw new LogicException($message);
        }
    }

    private static function validatedNamedOwner(
        MetricRepositoryInterface $repository,
        DeclarationPath $exactOwner,
        LogicalClassPath $logicalOwner,
        string $subjectKey,
    ): MetricSubject {
        $owner = MetricSubject::declaration($exactOwner);
        if ($exactOwner->logical->getType() !== SymbolType::Class_
            || $logicalOwner->toCanonical() !== (new LogicalClassPath($exactOwner->logical))->toCanonical()
            || !$repository->hasSubject($owner)) {
            throw new LogicException('Missing or mismatched named-owner declaration for ' . $subjectKey);
        }

        return $owner;
    }

}
