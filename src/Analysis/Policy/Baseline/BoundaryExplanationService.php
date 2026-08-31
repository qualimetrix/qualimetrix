<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Policy\Baseline;

use InvalidArgumentException;
use Qualimetrix\Analysis\Evidence\Measurement\Contract\MetricRepositoryInterface;
use Qualimetrix\Analysis\Finding\Contract\AcceptedLevel;
use Qualimetrix\Analysis\Finding\Contract\ChannelIdentityInterface;
use Qualimetrix\Analysis\Finding\Contract\Finding;
use Qualimetrix\Analysis\Finding\Contract\FindingChannel;
use Qualimetrix\Analysis\Finding\Contract\Threshold\ThresholdOverride;
use Qualimetrix\Analysis\Run\Contract\Pipeline\AnalysisResult;
use Qualimetrix\Core\Symbol\MetricSubject;
use Qualimetrix\Core\Symbol\SymbolLevelProjection;

/**
 * Builds a {@see BoundaryExplanation} for `bin/qmx baseline:explain`, as
 * specified by ADR 0017: the effective boundary for a symbol, and where
 * every part of it comes from.
 *
 * **Why `$configuredThresholds` arrives pre-resolved rather than being
 * looked up here.** `qmx.yaml` says `baseline: [core]` — this package may
 * depend on nothing but `Core` — and reading a rule's configured threshold
 * means building its `RuleOptionsInterface` through `RuleOptionsFactory`,
 * which lives in `Configuration`. The command that owns `explain` already
 * has that machinery (the same one that ran the analysis); this service
 * takes the resolved numbers as data instead of reaching for the
 * `Configuration` layer itself.
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
     *                                                                         `AnalysisResult::$thresholdOverrides`
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
        ?MetricRepositoryInterface $symbolLocations = null,
    ): BoundaryExplanation {
        $identities = ExplainedSubject::identities($subjectKey, $channelFilter, $baseline, $measuredFindings);
        $repositoryRecord = ExplainedSubject::recordFor($subjectKey, ExplainedSubject::index($symbolLocations));

        $boundaries = [];
        foreach ($identities as $identity) {
            $boundaries[] = $this->explainIdentity(
                $identity,
                $baseline,
                $measuredFindings,
                $thresholdOverridesByFile,
                $configuredThresholds,
                $repositoryRecord,
            );
        }

        return new BoundaryExplanation(
            $subjectKey,
            $boundaries,
            self::statusFor($subjectKey, $baseline, $measuredFindings, $repositoryRecord),
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
     * @param list<Finding> $measuredFindings
     * @param array<string, list<ThresholdOverride>> $thresholdOverridesByFile
     * @param array<string, array<string, int|float>> $configuredThresholds
     * @param ?SubjectRecord $repositoryRecord
     */
    private function explainIdentity(
        BaselineIdentity $identity,
        ?Baseline $baseline,
        array $measuredFindings,
        array $thresholdOverridesByFile,
        array $configuredThresholds,
        ?array $repositoryRecord,
    ): EffectiveBoundary {
        $baselineSource = self::baselineSourceFor($identity, $baseline, $measuredFindings);

        $subject = ExplainedSubject::subjectFor($identity, $measuredFindings, $repositoryRecord);
        $configuredThreshold = self::configuredThresholdFor(
            $configuredThresholds[$identity->channel->code] ?? [],
            $subject,
        );
        $annotation = $subject !== null
            ? $this->annotationFor($identity->channel, $thresholdOverridesByFile, $subject)
            : null;

        return new EffectiveBoundary($identity, $baselineSource, $configuredThreshold, $annotation);
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

    /**
     * @param list<Finding> $measuredFindings
     */
    private static function baselineSourceFor(
        BaselineIdentity $identity,
        ?Baseline $baseline,
        array $measuredFindings,
    ): ?EffectiveBoundaryBaselineSource {
        $entry = $baseline?->findByIdentity($identity);

        if ($entry === null) {
            return null;
        }

        $group = [];
        foreach ($measuredFindings as $finding) {
            if (BaselineIdentity::forFinding($finding)->key() === $identity->key()) {
                $group[] = $finding;
            }
        }

        $currentMagnitudes = null;

        if ($entry->magnitudes !== null) {
            $currentMagnitudes = [];

            foreach ($group as $finding) {
                if ($finding->metricValue === null) {
                    continue;
                }

                try {
                    $currentMagnitudes[] = BaselineEntry::normalizeMagnitude($finding->metricValue);
                } catch (InvalidArgumentException) {
                    // Not finite: not a boundary, left out of the current reading.
                    continue;
                }
            }
        }

        return new EffectiveBoundaryBaselineSource(
            accepted: new AcceptedLevel($entry->magnitudes, $entry->count),
            currentMagnitudes: $currentMagnitudes,
            currentCount: \count($group),
        );
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
