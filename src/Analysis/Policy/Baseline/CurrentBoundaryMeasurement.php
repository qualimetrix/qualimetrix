<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Policy\Baseline;

use LogicException;
use Qualimetrix\Analysis\Finding\Contract\ChannelDeclaration;
use Qualimetrix\Analysis\Finding\Contract\ChannelDeclarationRegistryInterface;
use Qualimetrix\Analysis\Finding\Contract\ChannelShape;
use Qualimetrix\Analysis\Finding\Contract\Finding;
use Qualimetrix\Analysis\Policy\Baseline\Ceiling\BaselineCeilingStage;
use Qualimetrix\Analysis\Policy\Baseline\Ceiling\GroupMeasurement;
use Qualimetrix\Analysis\Policy\Baseline\Contract\CeilingOutcome;
use Qualimetrix\Analysis\Policy\Baseline\Contract\CurrentMeasurement;
use Qualimetrix\Analysis\Policy\Baseline\Contract\RunCoverage;
use Qualimetrix\Core\Symbol\MetricSubject;

/** Projects current identity evidence and its accepted source from one ceiling verdict. */
final readonly class CurrentBoundaryMeasurement
{
    public function __construct(private RunRuleCoverage $ruleCoverage) {}

    /**
     * @param list<BaselineIdentity> $identities
     * @param array<string, list<Finding>> $groups
     * @param list<Finding> $measuredFindings
     *
     * @return list<array{CurrentMeasurement, ?EffectiveBoundaryBaselineSource}>
     */
    public function measure(
        ?Baseline $baseline,
        array $identities,
        array $groups,
        array $measuredFindings,
        ChannelDeclarationRegistryInterface $declarations,
        RunCoverage $coverage,
    ): array {
        $outcome = $this->ceilingVerdict($baseline, $measuredFindings, $declarations, $coverage);
        $evidence = [];
        foreach ($identities as $identity) {
            $evidence[] = [
                $this->currentMeasurement($identity, $baseline, $groups[$identity->key()] ?? [], $declarations, $coverage, $outcome),
                self::baselineSourceFor($identity, $baseline, $outcome),
            ];
        }

        return $evidence;
    }

    /** @param list<Finding> $measuredFindings */
    private function ceilingVerdict(
        ?Baseline $baseline,
        array $measuredFindings,
        ChannelDeclarationRegistryInterface $declarations,
        RunCoverage $coverage,
    ): ?CeilingOutcome {
        if ($baseline === null) {
            return null;
        }
        $identities = array_map(
            static fn(BaselineEntry $entry): BaselineIdentity => $entry->identity,
            array_filter($baseline->entries, static fn(BaselineEntry $entry): bool => \in_array(
                MetricSubject::levelOfCanonical($entry->identity->subjectKey),
                $declarations->declarationFor($entry->identity->channel)->levels ?? [],
                true,
            )),
        );

        return (new BaselineCeilingStage($baseline, $declarations, $coverage, $this->ruleCoverage->classify($identities)))->judgeAll($measuredFindings);
    }

    private static function baselineSourceFor(BaselineIdentity $identity, ?Baseline $baseline, ?CeilingOutcome $outcome): ?EffectiveBoundaryBaselineSource
    {
        $entry = $baseline?->findByIdentity($identity);
        if ($entry !== null) {
            return EffectiveBoundaryBaselineSource::applicable($entry, $outcome?->statusFor($identity), $outcome?->reasonFor($identity));
        }
        $inert = $baseline?->findInertByIdentity($identity);

        return $inert !== null ? EffectiveBoundaryBaselineSource::inert($inert) : null;
    }

    /** @param list<Finding> $group */
    private function currentMeasurement(
        BaselineIdentity $identity,
        ?Baseline $baseline,
        array $group,
        ChannelDeclarationRegistryInterface $declarations,
        RunCoverage $coverage,
        ?CeilingOutcome $outcome,
    ): CurrentMeasurement {
        $declaration = $declarations->declarationFor($identity->channel);
        $level = MetricSubject::levelOfCanonical($identity->subjectKey);
        if ($declaration !== null && !\in_array($level, $declaration->levels, true)) {
            return new CurrentMeasurement(
                CurrentMeasurement::LEVEL_NOT_REPORTED,
                null,
                0,
                null,
                0,
                declaredLevels: array_map(static fn($declared): string => $declared->value, $declaration->levels),
                subjectLevel: $level->value,
            );
        }
        $shape = self::shapeOf($declaration);
        $measurement = self::groupMeasurement($group, $declaration);
        if ($baseline?->findByIdentity($identity) !== null) {
            [$state, $reason] = self::knownState($identity, $group, $outcome);
        } else {
            [$state, $reason] = $this->unrecordedState($identity, $group, $declaration, $declarations, $coverage, $measurement);
        }

        return new CurrentMeasurement($state, $shape, \count($group), $measurement?->magnitudes, $measurement->membersWithoutMagnitude ?? 0, $reason);
    }

    /**
     * @param list<Finding> $group
     *
     * @return array{string, ?string}
     */
    private function unrecordedState(
        BaselineIdentity $identity,
        array $group,
        ?ChannelDeclaration $declaration,
        ChannelDeclarationRegistryInterface $declarations,
        RunCoverage $coverage,
        ?GroupMeasurement $measurement,
    ): array {
        [$state, $reason] = $this->currentState($identity, $group, $declaration, $declarations, $coverage);
        if ($measurement !== null && !$measurement->complete() && $state === CurrentMeasurement::REPORTED) {
            return [CurrentMeasurement::NOT_COMPARED, 'magnitude-unavailable'];
        }

        return [$state, $reason];
    }

    private static function shapeOf(?ChannelDeclaration $declaration): ?string
    {
        if ($declaration === null) {
            return null;
        }

        return $declaration->direction === null ? 'occurrence' : 'magnitude';
    }

    /** @param list<Finding> $group */
    private static function groupMeasurement(array $group, ?ChannelDeclaration $declaration): ?GroupMeasurement
    {
        if ($group === [] || $declaration === null) {
            return null;
        }

        return GroupMeasurement::fromFindings($group, $declaration->direction === null ? ChannelShape::Occurrence : ChannelShape::Magnitude);
    }

    /**
     * @param list<Finding> $group
     *
     * @return array{string, ?string}
     */
    private static function knownState(BaselineIdentity $identity, array $group, ?CeilingOutcome $outcome): array
    {
        $status = $outcome?->statusFor($identity) ?? throw new LogicException('An explained entry requires its ceiling verdict.');
        $states = [
            'stale' => CurrentMeasurement::NOTHING_REPORTED,
            'unmeasured' => CurrentMeasurement::NOT_MEASURED,
            'outside-coverage' => CurrentMeasurement::OUTSIDE_COVERAGE,
        ];
        $state = $status === 'not-compared'
            ? ($group === [] ? CurrentMeasurement::OUTSIDE_COVERAGE : CurrentMeasurement::NOT_COMPARED)
            : ($states[$status] ?? CurrentMeasurement::REPORTED);

        return [$state, $outcome->reasonFor($identity)];
    }

    /**
     * @param list<Finding> $group
     *
     * @return array{string, ?string}
     */
    private function currentState(
        BaselineIdentity $identity,
        array $group,
        ?ChannelDeclaration $declaration,
        ChannelDeclarationRegistryInterface $declarations,
        RunCoverage $coverage,
    ): array {
        if (!$coverage->analysis->isComplete()) {
            return [$group === [] ? CurrentMeasurement::NOT_MEASURED : CurrentMeasurement::NOT_COMPARED, 'analysis-incomplete'];
        }
        if ($declaration === null) {
            return [$group === [] ? CurrentMeasurement::NOT_MEASURED : CurrentMeasurement::REPORTED, 'channel-not-declared'];
        }
        if ($this->ruleCoverage->classify([$identity]) !== []) {
            return [CurrentMeasurement::NOT_MEASURED, 'producer-not-measured'];
        }
        if ($group !== []) {
            return [CurrentMeasurement::REPORTED, null];
        }
        return CurrentAbsentMeasurement::measure($identity, $declarations, $coverage);
    }
}
