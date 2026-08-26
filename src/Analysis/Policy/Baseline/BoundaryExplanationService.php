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
use Qualimetrix\Core\Path\RelativePath;
use Qualimetrix\Core\Symbol\MetricSubject;
use Qualimetrix\Core\Symbol\SymbolLevel;
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
 * declaration subject.
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
        $identities = self::relevantIdentities($subjectKey, $channelFilter, $baseline, $measuredFindings);
        $repositoryIndex = self::repositoryIndex($symbolLocations);
        $repositoryRecord = self::repositoryRecord($subjectKey, $repositoryIndex);

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
     * @param ?array{subject: ?MetricSubject, location: ?array{0: RelativePath, 1: int}} $repositoryRecord
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
     * Every identity worth reporting on: every baseline entry for this
     * symbol, plus every channel currently firing for it, narrowed to one
     * channel when `$channelFilter` is given. When neither source has
     * anything and a channel was explicitly asked for, a bare identity with
     * no edge is still returned — `qmx.yaml` and the annotation may have
     * something to say about a channel that simply is not breaching right
     * now.
     *
     * @param list<Finding> $measuredFindings
     *
     * @return list<BaselineIdentity>
     */
    private static function relevantIdentities(
        string $symbolKey,
        ?FindingChannel $channelFilter,
        ?Baseline $baseline,
        array $measuredFindings,
    ): array {
        /** @var array<string, BaselineIdentity> $byKey */
        $byKey = [];

        if ($baseline !== null) {
            foreach ($baseline->entries as $entry) {
                $identity = $entry->identity;

                if ($identity->subjectKey !== $symbolKey) {
                    continue;
                }

                if ($channelFilter !== null && !$identity->channel->equals($channelFilter)) {
                    continue;
                }

                $byKey[$identity->key()] = $identity;
            }
        }

        foreach ($measuredFindings as $finding) {
            if ($finding->subject->toCanonical() !== $symbolKey) {
                continue;
            }

            $identity = BaselineIdentity::forFinding($finding);

            if ($channelFilter !== null && !$identity->channel->equals($channelFilter)) {
                continue;
            }

            $byKey[$identity->key()] ??= $identity;
        }

        if ($byKey === [] && $channelFilter !== null) {
            $bare = new BaselineIdentity($symbolKey, $channelFilter);
            $byKey[$bare->key()] = $bare;
        }

        return array_values($byKey);
    }

    /**
     * @param list<Finding> $measuredFindings
     * @param array<string, list<ThresholdOverride>> $thresholdOverridesByFile
     * @param array<string, array<string, int|float>> $configuredThresholds
     * @param ?array{subject: ?MetricSubject, location: ?array{0: RelativePath, 1: int}} $repositoryRecord
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

        $subject = self::subjectForIdentity($identity, $measuredFindings, $repositoryRecord);
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
     * @return array<string, array{subject: ?MetricSubject, location: ?array{0: RelativePath, 1: int}}>
     */
    private static function repositoryIndex(?MetricRepositoryInterface $repository): array
    {
        if ($repository === null) {
            return [];
        }

        $index = [];
        foreach ([$repository->allDeclarations(), $repository->allCallables()] as $symbols) {
            $exactRows = iterator_to_array($symbols);
            array_walk($exactRows, static function ($info) use (&$index): void {
                $subject = $info->subject ?? throw new InvalidArgumentException(
                    'Exact repository rows must retain their typed subject.',
                );
                $index[$subject->toCanonical()] ??= self::recordFor($subject, $info->file, $info->line);
            });
        }

        $projectedSources = [$repository->allLogicalClasses()];
        foreach (SymbolLevel::cases() as $level) {
            $projectedSources[] = $repository->all($level);
        }

        foreach ($projectedSources as $symbols) {
            foreach ($symbols as $info) {
                $key = $info->subject?->toCanonical() ?? $info->symbolPath->toCanonical();
                $index[$key] ??= self::recordFor($info->subject, $info->file, $info->line);
            }
        }

        return $index;
    }

    /** @return array{subject: ?MetricSubject, location: ?array{0: RelativePath, 1: int}} */
    private static function recordFor(?MetricSubject $subject, ?RelativePath $file, ?int $line): array
    {
        return [
            'subject' => $subject,
            'location' => $file !== null && $line !== null ? [$file, $line] : null,
        ];
    }

    /**
     * @param array<string, array{subject: ?MetricSubject, location: ?array{0: RelativePath, 1: int}}> $index
     *
     * @return ?array{subject: ?MetricSubject, location: ?array{0: RelativePath, 1: int}}
     */
    private static function repositoryRecord(string $subjectKey, array $index): ?array
    {
        return $index[$subjectKey] ?? null;
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

    /**
     * @param list<Finding> $measuredFindings
     * @param ?array{subject: ?MetricSubject, location: ?array{0: RelativePath, 1: int}} $repositoryRecord
     */
    private static function subjectForIdentity(
        BaselineIdentity $identity,
        array $measuredFindings,
        ?array $repositoryRecord,
    ): ?MetricSubject {
        foreach ($measuredFindings as $finding) {
            if ($finding->subject->toCanonical() === $identity->subjectKey) {
                return $finding->subject;
            }
        }

        return $repositoryRecord['subject'] ?? null;
    }
}
