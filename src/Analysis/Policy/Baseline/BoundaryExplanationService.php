<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Policy\Baseline;

use LogicException;
use Qualimetrix\Analysis\Evidence\Measurement\Contract\MetricRepositoryInterface;
use Qualimetrix\Analysis\Finding\Contract\ChannelDeclaration;
use Qualimetrix\Analysis\Finding\Contract\ChannelDeclarationRegistryInterface;
use Qualimetrix\Analysis\Finding\Contract\ChannelIdentityInterface;
use Qualimetrix\Analysis\Finding\Contract\Finding;
use Qualimetrix\Analysis\Finding\Contract\FindingChannel;
use Qualimetrix\Analysis\Finding\Contract\Threshold\ThresholdOverride;
use Qualimetrix\Analysis\Policy\Baseline\Ceiling\BaselineCeilingStage;
use Qualimetrix\Analysis\Policy\Baseline\Ceiling\GroupMeasurement;
use Qualimetrix\Analysis\Policy\Baseline\Ceiling\SubjectRegion;
use Qualimetrix\Analysis\Policy\Baseline\Contract\CeilingOutcome;
use Qualimetrix\Analysis\Policy\Baseline\Contract\CurrentMeasurement;
use Qualimetrix\Analysis\Policy\Baseline\Contract\RunCoverage;
use Qualimetrix\Analysis\Run\Contract\Discovery\ProjectEntryPresence;
use Qualimetrix\Core\Symbol\MetricSubject;
use Qualimetrix\Core\Symbol\SymbolLevelProjection;

/**
 * Builds a {@see BoundaryExplanation} for `bin/qmx baseline:explain`, as
 * specified by ADR 0017: the effective boundary for a symbol, and where
 * every part of it comes from.
 *
 * Annotation ownership is resolved by exact typed subject. A current
 * finding is the first source even when its occurrence or dependency edge
 * differs from the baseline identity; the repository supplies the same exact
 * subject when no current finding does. Logical projections never invent a
 * declaration subject. Which identities and which subject those are is
 * {@see ExplainedSubject}'s answer, not this class's.
 *
 * @phpstan-import-type SubjectRecord from ExplainedSubject
 */
final readonly class BoundaryExplanationService
{
    /**
     * @param ChannelIdentityInterface $channels the registry edge from a channel to the rule
     *                                           that produces it — a channel is one name now,
     *                                           and `@qmx-threshold` addresses the producing
     *                                           rule, so the two are joined here rather than
     *                                           read off two halves of a key
     */
    public function __construct(
        private ChannelIdentityInterface $channels,
        private RunRuleCoverage $ruleCoverage,
    ) {}

    /**
     * @param list<Finding> $measuredFindings the measured set (ADR 0017) this run produced —
     *                                        both the "currently compared" magnitudes of
     *                                        ADR 0017 and the first exact typed subject for
     *                                        annotation ownership come from here
     * @param array<string, list<ThresholdOverride>> $thresholdOverridesByFile per-file
     *                                                                         `@qmx-threshold`
     *                                                                         overrides — read
     *                                                                         straight off
     *                                                                         `DirectiveObservations::$thresholdOverrides`
     * @param array<string, array<string, int|float>> $configuredThresholds the rule's `qmx.yaml`-configured
     *                                                                      boundary, keyed by channel name;
     *                                                                      a channel absent from this map
     *                                                                      reports {@see EffectiveBoundary::$configuredThreshold}
     *                                                                      as `null`
     * @param ?MetricRepositoryInterface $symbolLocations the run's measured exact subjects;
     *                                                    repository evidence is the fallback
     *                                                    when no current finding has that
     *                                                    canonical subject
     */
    public function explain(
        string $subjectKey,
        ?FindingChannel $channelFilter,
        ?Baseline $baseline,
        array $measuredFindings,
        array $thresholdOverridesByFile,
        array $configuredThresholds,
        ChannelDeclarationRegistryInterface $declarations,
        RunCoverage $coverage,
        ?MetricRepositoryInterface $symbolLocations = null,
    ): BoundaryExplanation {
        $identities = ExplainedSubject::identities($subjectKey, $channelFilter, $baseline, $measuredFindings);
        $repositoryRecord = ExplainedSubject::recordFor($subjectKey, ExplainedSubject::index($symbolLocations));
        $groups = self::groupsByIdentity($measuredFindings);
        $outcome = $baseline !== null
            ? (new BaselineCeilingStage(
                $baseline,
                $declarations,
                $coverage,
                $this->ruleCoverage->classify(array_map(
                    static fn(BaselineEntry $entry): BaselineIdentity => $entry->identity,
                    array_filter($baseline->entries, static fn(BaselineEntry $entry): bool => \in_array(
                        MetricSubject::levelOfCanonical($entry->identity->subjectKey),
                        $declarations->declarationFor($entry->identity->channel)->levels ?? [],
                        true,
                    )),
                )),
            ))->judgeAll($measuredFindings)
            : null;

        $boundaries = [];
        foreach ($identities as $identity) {
            $boundaries[] = $this->explainIdentity(
                $identity,
                $baseline,
                $groups[$identity->key()] ?? [],
                $measuredFindings,
                $thresholdOverridesByFile,
                $configuredThresholds,
                $repositoryRecord,
                $declarations,
                $coverage,
                $outcome,
            );
        }

        return new BoundaryExplanation(
            $subjectKey,
            $boundaries,
            self::statusFor($subjectKey, $baseline, $measuredFindings, $repositoryRecord),
            ExplainedSubject::unidentifiedEntries($subjectKey, $channelFilter, $baseline),
        );
    }

    /**
     * @param list<Finding> $measuredFindings
     * @param ?SubjectRecord $repositoryRecord
     */
    private static function statusFor(
        string $symbolKey,
        ?Baseline $baseline,
        array $measuredFindings,
        ?array $repositoryRecord,
    ): BoundaryExplanationStatus {
        if ($repositoryRecord !== null || array_any(
            $measuredFindings,
            static fn(Finding $finding): bool => $finding->subject->toCanonical() === $symbolKey,
        )) {
            return BoundaryExplanationStatus::Current;
        }

        if ($baseline !== null && (array_any(
            $baseline->entries,
            static fn(BaselineEntry $entry): bool => $entry->identity->subjectKey === $symbolKey,
        ) || array_any(
            $baseline->inertEntries,
            static fn(InertBaselineEntry $entry): bool => $entry->subjectKey === $symbolKey,
        ))) {
            return BoundaryExplanationStatus::BaselineOnly;
        }

        return BoundaryExplanationStatus::Unknown;
    }

    /**
     * The measured findings of each identity, in run order. Built once per
     * explanation: a block of N duplicate copies is N identities, and
     * filtering the whole measured set for each would cost N² identity keys.
     *
     * @param list<Finding> $measuredFindings
     *
     * @return array<string, list<Finding>>
     */
    private static function groupsByIdentity(array $measuredFindings): array
    {
        $groups = [];
        foreach ($measuredFindings as $finding) {
            $groups[BaselineIdentity::forFinding($finding)->key()][] = $finding;
        }

        return $groups;
    }

    /**
     * @param list<Finding> $group the measured findings sharing `$identity`
     * @param list<Finding> $measuredFindings
     * @param array<string, list<ThresholdOverride>> $thresholdOverridesByFile
     * @param array<string, array<string, int|float>> $configuredThresholds
     * @param ?SubjectRecord $repositoryRecord
     */
    private function explainIdentity(
        BaselineIdentity $identity,
        ?Baseline $baseline,
        array $group,
        array $measuredFindings,
        array $thresholdOverridesByFile,
        array $configuredThresholds,
        ?array $repositoryRecord,
        ChannelDeclarationRegistryInterface $declarations,
        RunCoverage $coverage,
        ?CeilingOutcome $outcome,
    ): EffectiveBoundary {
        $baselineSource = self::baselineSourceFor($identity, $baseline, $outcome);
        $now = $this->currentMeasurement($identity, $baseline, $group, $declarations, $coverage, $outcome);

        $subject = ExplainedSubject::subjectFor($identity, $measuredFindings, $repositoryRecord);
        $configuredThreshold = self::configuredThresholdFor(
            $configuredThresholds[$identity->channel->code] ?? [],
            $subject,
        );
        $annotation = $subject !== null
            ? $this->annotationFor($identity->channel, $thresholdOverridesByFile, $subject)
            : null;

        return new EffectiveBoundary(
            $identity,
            $baselineSource,
            $configuredThreshold,
            $annotation,
            $now,
            array_values(array_map(
                static fn(Finding $finding): string => $finding->location->toString(),
                array_filter($group, static fn(Finding $finding): bool => !$finding->location->isNone()),
            )),
        );
    }

    /**
     * The boundary a channel is judged against **at the level of the subject
     * being explained**.
     *
     * A channel reports at more than one level now, so the level is what
     * chooses between its boundaries. When the subject could not be resolved
     * at all there is nothing to choose with, and a channel with one boundary
     * still has an unambiguous answer; a channel with two does not, and
     * printing either would be a guess printed as a fact.
     *
     * @param array<string, int|float> $byLevel
     */
    private static function configuredThresholdFor(array $byLevel, ?MetricSubject $subject): int|float|null
    {
        if ($subject !== null) {
            return $byLevel[SymbolLevelProjection::ofDeclaration($subject->toSymbolPath()->getType())->value] ?? null;
        }

        return \count($byLevel) === 1 ? reset($byLevel) : null;
    }

    private static function baselineSourceFor(
        BaselineIdentity $identity,
        ?Baseline $baseline,
        ?CeilingOutcome $outcome,
    ): ?EffectiveBoundaryBaselineSource {
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

        $shape = $declaration === null ? null : ($declaration->direction === null ? 'occurrence' : 'magnitude');
        $measurement = $group !== [] && $declaration !== null
            ? GroupMeasurement::fromFindings($group, $shape === 'occurrence')
            : null;
        if ($baseline?->findByIdentity($identity) !== null) {
            $status = $outcome?->statusFor($identity) ?? throw new LogicException('An explained entry requires its ceiling verdict.');
            $reason = $outcome->reasonFor($identity);
            $state = match ($status) {
                'stale' => CurrentMeasurement::NOTHING_REPORTED,
                'unmeasured' => CurrentMeasurement::NOT_MEASURED,
                'outside-coverage' => CurrentMeasurement::OUTSIDE_COVERAGE,
                'not-compared' => $group === [] ? CurrentMeasurement::OUTSIDE_COVERAGE : CurrentMeasurement::NOT_COMPARED,
                default => CurrentMeasurement::REPORTED,
            };
        } else {
            [$state, $reason] = $this->currentState($identity, $group, $declaration, $declarations, $coverage);
            if ($measurement !== null && !$measurement->complete() && $state === CurrentMeasurement::REPORTED) {
                $state = CurrentMeasurement::NOT_COMPARED;
                $reason = 'magnitude-unavailable';
            }
        }

        return new CurrentMeasurement(
            $state,
            $shape,
            \count($group),
            $measurement?->magnitudes,
            $measurement->membersWithoutMagnitude ?? 0,
            $reason,
        );
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
        $region = SubjectRegion::forIdentity(
            $identity,
            $declarations->reachAt($identity->channel, MetricSubject::levelOfCanonical($identity->subjectKey)),
            $coverage->psr4Roots,
        );
        if ($region->file !== null) {
            $presence = $coverage->hasFile($region->file);

            return match (true) {
                $presence === ProjectEntryPresence::Absent => [CurrentMeasurement::NOTHING_REPORTED, null],
                $presence === ProjectEntryPresence::Unknown => [CurrentMeasurement::OUTSIDE_COVERAGE, 'metadata-unknown'],
                $coverage->analyzed($region->file) => [CurrentMeasurement::NOTHING_REPORTED, null],
                default => [CurrentMeasurement::OUTSIDE_COVERAGE, 'outside-coverage'],
            };
        }
        if ($coverage->scope->coversPath('.') || ($region->roots !== [] && array_all(
            $region->roots,
            static fn($root): bool => $coverage->scope->coversPath($root->value()),
        ))) {
            return [CurrentMeasurement::NOTHING_REPORTED, null];
        }
        $snapshot = $coverage->snapshot();
        if (!$snapshot->complete()) {
            return [CurrentMeasurement::OUTSIDE_COVERAGE, 'metadata-unknown'];
        }
        foreach ($snapshot->phpFiles as $file) {
            if ($region->contains($file) && !$coverage->scope->coversPath($file->value())) {
                return [CurrentMeasurement::OUTSIDE_COVERAGE, 'outside-coverage'];
            }
        }

        return [CurrentMeasurement::NOTHING_REPORTED, null];
    }

    /**
     * @param array<string, list<ThresholdOverride>> $thresholdOverridesByFile
     */
    private function annotationFor(
        FindingChannel $channel,
        array $thresholdOverridesByFile,
        MetricSubject $subject,
    ): ?ThresholdOverride {
        $producer = $this->channels->producerOf($channel->code);

        if ($producer === null) {
            return null;
        }

        $matches = [];
        foreach ($thresholdOverridesByFile as $overrides) {
            $matches = [...$matches, ...array_values(array_filter(
                $overrides,
                static fn(ThresholdOverride $override): bool => $override->subject->toCanonical() === $subject->toCanonical()
                    && $override->matches($producer),
            ))];
        }

        usort($matches, static function (ThresholdOverride $left, ThresholdOverride $right): int {
            $specificity = $right->controlScope->specificity() <=> $left->controlScope->specificity();
            if ($specificity !== 0) {
                return $specificity;
            }

            $leftSpan = $left->endLine === null ? \PHP_INT_MAX : max(0, $left->endLine - $left->line);
            $rightSpan = $right->endLine === null ? \PHP_INT_MAX : max(0, $right->endLine - $right->line);

            return $leftSpan <=> $rightSpan;
        });

        return $matches[0] ?? null;
    }

}
