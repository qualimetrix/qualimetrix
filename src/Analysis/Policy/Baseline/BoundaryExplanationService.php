<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Policy\Baseline;

use InvalidArgumentException;

use Qualimetrix\Analysis\Evidence\Measurement\Contract\MetricRepositoryInterface;
use Qualimetrix\Analysis\Finding\Contract\AcceptedLevel;
use Qualimetrix\Analysis\Finding\Contract\Threshold\ThresholdOverride;
use Qualimetrix\Analysis\Finding\Contract\Violation;
use Qualimetrix\Analysis\Finding\Contract\ViolationChannel;
use Qualimetrix\Analysis\Run\Contract\Pipeline\AnalysisResult;
use Qualimetrix\Core\Path\RelativePath;
use Qualimetrix\Core\Symbol\MetricSubject;
use Qualimetrix\Core\Symbol\SymbolType;

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
 * violation is the first source even when its occurrence or dependency edge
 * differs from the baseline identity; the repository supplies the same exact
 * subject when no current finding does. Logical projections never invent a
 * declaration subject.
 */
final readonly class BoundaryExplanationService
{
    /**
     * @param list<Violation> $measuredViolations the measured set (ADR 0017) this run produced —
     *                                            both the "currently compared" magnitudes of
     *                                            ADR 0017 and the first exact typed subject for
     *                                            annotation ownership come from here
     * @param array<string, list<ThresholdOverride>> $thresholdOverridesByFile per-file
     *                                                                         `@qmx-threshold`
     *                                                                         overrides — read
     *                                                                         straight off
     *                                                                         `AnalysisResult::$thresholdOverrides`
     * @param array<string, int|float> $configuredThresholds the rule's `qmx.yaml`-configured
     *                                                       boundary, keyed by
     *                                                       {@see ViolationChannel::toKey()};
     *                                                       a channel absent from this map
     *                                                       reports {@see EffectiveBoundary::$configuredThreshold}
     *                                                       as `null`
     * @param ?MetricRepositoryInterface $symbolLocations the run's measured exact subjects;
     *                                                    repository evidence is the fallback
     *                                                    when no current violation has that
     *                                                    canonical subject
     */
    public function explain(
        string $subjectKey,
        ?ViolationChannel $channelFilter,
        ?Baseline $baseline,
        array $measuredViolations,
        array $thresholdOverridesByFile,
        array $configuredThresholds,
        ?MetricRepositoryInterface $symbolLocations = null,
    ): BoundaryExplanation {
        $identities = self::relevantIdentities($subjectKey, $channelFilter, $baseline, $measuredViolations);
        $repositoryIndex = self::repositoryIndex($symbolLocations);
        $repositoryRecord = self::repositoryRecord($subjectKey, $repositoryIndex);

        $boundaries = [];
        foreach ($identities as $identity) {
            $boundaries[] = self::explainIdentity(
                $identity,
                $baseline,
                $measuredViolations,
                $thresholdOverridesByFile,
                $configuredThresholds,
                $repositoryRecord,
            );
        }

        return new BoundaryExplanation(
            $subjectKey,
            $boundaries,
            self::statusFor($subjectKey, $baseline, $measuredViolations, $repositoryRecord),
        );
    }

    /**
     * @param list<Violation> $measuredViolations
     * @param ?array{subject: ?MetricSubject, location: ?array{0: RelativePath, 1: int}} $repositoryRecord
     */
    private static function statusFor(
        string $symbolKey,
        ?Baseline $baseline,
        array $measuredViolations,
        ?array $repositoryRecord,
    ): BoundaryExplanationStatus {
        if ($repositoryRecord !== null || array_any(
            $measuredViolations,
            static fn(Violation $violation): bool => $violation->subject->toCanonical() === $symbolKey,
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
     * @param list<Violation> $measuredViolations
     *
     * @return list<BaselineIdentity>
     */
    private static function relevantIdentities(
        string $symbolKey,
        ?ViolationChannel $channelFilter,
        ?Baseline $baseline,
        array $measuredViolations,
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

        foreach ($measuredViolations as $violation) {
            if ($violation->subject->toCanonical() !== $symbolKey) {
                continue;
            }

            $identity = BaselineIdentity::forViolation($violation);

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
     * @param list<Violation> $measuredViolations
     * @param array<string, list<ThresholdOverride>> $thresholdOverridesByFile
     * @param array<string, int|float> $configuredThresholds
     * @param ?array{subject: ?MetricSubject, location: ?array{0: RelativePath, 1: int}} $repositoryRecord
     */
    private static function explainIdentity(
        BaselineIdentity $identity,
        ?Baseline $baseline,
        array $measuredViolations,
        array $thresholdOverridesByFile,
        array $configuredThresholds,
        ?array $repositoryRecord,
    ): EffectiveBoundary {
        $baselineSource = self::baselineSourceFor($identity, $baseline, $measuredViolations);
        $configuredThreshold = $configuredThresholds[$identity->channel->toKey()] ?? null;

        $subject = self::subjectForIdentity($identity, $measuredViolations, $repositoryRecord);
        $annotation = $subject !== null
            ? self::annotationFor($identity->channel, $thresholdOverridesByFile, $subject)
            : null;

        return new EffectiveBoundary($identity, $baselineSource, $configuredThreshold, $annotation);
    }

    /**
     * @param list<Violation> $measuredViolations
     */
    private static function baselineSourceFor(
        BaselineIdentity $identity,
        ?Baseline $baseline,
        array $measuredViolations,
    ): ?EffectiveBoundaryBaselineSource {
        $entry = $baseline?->findByIdentity($identity);

        if ($entry === null) {
            return null;
        }

        $group = [];
        foreach ($measuredViolations as $violation) {
            if (BaselineIdentity::forViolation($violation)->key() === $identity->key()) {
                $group[] = $violation;
            }
        }

        $currentMagnitudes = null;

        if ($entry->magnitudes !== null) {
            $currentMagnitudes = [];

            foreach ($group as $violation) {
                if ($violation->metricValue === null) {
                    continue;
                }

                try {
                    $currentMagnitudes[] = BaselineEntry::normalizeMagnitude($violation->metricValue);
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
        foreach (SymbolType::cases() as $type) {
            $projectedSources[] = $repository->all($type);
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
    private static function annotationFor(
        ViolationChannel $channel,
        array $thresholdOverridesByFile,
        MetricSubject $subject,
    ): ?ThresholdOverride {
        $matches = [];
        foreach ($thresholdOverridesByFile as $overrides) {
            $matches = [...$matches, ...array_values(array_filter(
                $overrides,
                static fn(ThresholdOverride $override): bool => $override->subject->toCanonical() === $subject->toCanonical()
                    && $override->matches($channel->ruleName),
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
     * @param list<Violation> $measuredViolations
     * @param ?array{subject: ?MetricSubject, location: ?array{0: RelativePath, 1: int}} $repositoryRecord
     */
    private static function subjectForIdentity(
        BaselineIdentity $identity,
        array $measuredViolations,
        ?array $repositoryRecord,
    ): ?MetricSubject {
        foreach ($measuredViolations as $violation) {
            if ($violation->subject->toCanonical() === $identity->subjectKey) {
                return $violation->subject;
            }
        }

        return $repositoryRecord['subject'] ?? null;
    }
}
