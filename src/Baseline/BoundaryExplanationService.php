<?php

declare(strict_types=1);

namespace Qualimetrix\Baseline;

use InvalidArgumentException;
use Qualimetrix\Core\Metric\MetricBag;
use Qualimetrix\Core\Metric\MetricRepositoryInterface;
use Qualimetrix\Core\Path\RelativePath;
use Qualimetrix\Core\Rule\AnalysisContext;
use Qualimetrix\Core\Suppression\ThresholdOverride;
use Qualimetrix\Core\Symbol\SymbolPath;
use Qualimetrix\Core\Symbol\SymbolType;
use Qualimetrix\Core\Violation\AcceptedLevel;
use Qualimetrix\Core\Violation\Violation;
use Qualimetrix\Core\Violation\ViolationChannel;

/**
 * Builds a {@see BoundaryExplanation} for `bin/qmx baseline:explain` (§7 of
 * the baseline-ceiling plan): the effective boundary for a symbol, and where
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
 * **Why the `@qmx-threshold` match is not reimplemented here.**
 * {@see AnalysisContext::getThresholdOverride()} already picks the
 * smallest-scope override for a rule, file and line — the same rule
 * §7 asks `explain` to reuse rather than restate. It is Core, so this
 * package may call it; the only friction is that `AnalysisContext` also
 * demands a {@see MetricRepositoryInterface}, which the override lookup
 * never touches — {@see self::nullMetrics()} is that unused argument, not a
 * second metrics source.
 */
final readonly class BoundaryExplanationService
{
    /**
     * @param list<Violation> $measuredViolations the measured set (§5.5) this run produced —
     *                                            both the "currently compared" magnitudes of
     *                                            §13.5 and the symbol's file/line for matching
     *                                            `@qmx-threshold` annotations come from here
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
     */
    public function explain(
        string $symbolKey,
        ?ViolationChannel $channelFilter,
        ?Baseline $baseline,
        array $measuredViolations,
        array $thresholdOverridesByFile,
        array $configuredThresholds,
    ): BoundaryExplanation {
        $identities = self::relevantIdentities($symbolKey, $channelFilter, $baseline, $measuredViolations);
        $location = self::locationForSymbol($symbolKey, $measuredViolations);

        $boundaries = [];
        foreach ($identities as $identity) {
            $boundaries[] = self::explainIdentity(
                $identity,
                $baseline,
                $measuredViolations,
                $thresholdOverridesByFile,
                $configuredThresholds,
                $location,
            );
        }

        return new BoundaryExplanation($symbolKey, $boundaries);
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

                if ($identity->symbolKey !== $symbolKey) {
                    continue;
                }

                if ($channelFilter !== null && !$identity->channel->equals($channelFilter)) {
                    continue;
                }

                $byKey[$identity->key()] = $identity;
            }
        }

        foreach ($measuredViolations as $violation) {
            if ($violation->symbolPath->toCanonical() !== $symbolKey) {
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
     * @param ?array{RelativePath, int} $location
     */
    private static function explainIdentity(
        BaselineIdentity $identity,
        ?Baseline $baseline,
        array $measuredViolations,
        array $thresholdOverridesByFile,
        array $configuredThresholds,
        ?array $location,
    ): EffectiveBoundary {
        $baselineSource = self::baselineSourceFor($identity, $baseline, $measuredViolations);
        $configuredThreshold = $configuredThresholds[$identity->channel->toKey()] ?? null;

        $annotation = $location !== null
            ? self::findAnnotationOverride($identity->channel, $thresholdOverridesByFile, $location[0], $location[1])
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
     * The declaration line of the symbol, taken from any currently-measured
     * violation reported against it. This is what
     * {@see AnalysisContext::getThresholdOverride()} expects for the `$line`
     * argument: rules pass the symbol's own declaration line (e.g.
     * `$methodInfo->line`), not a violation's precise offending line, so any
     * violation on this symbol carries the right value regardless of which
     * channel fired it. `null` when nothing currently reports this symbol —
     * the annotation source is then reported absent, since there is no
     * location left to scope it by.
     *
     * @param list<Violation> $measuredViolations
     *
     * @return ?array{RelativePath, int}
     */
    private static function locationForSymbol(string $symbolKey, array $measuredViolations): ?array
    {
        foreach ($measuredViolations as $violation) {
            if ($violation->symbolPath->toCanonical() !== $symbolKey) {
                continue;
            }

            $file = $violation->location->file;
            $line = $violation->location->line;

            if ($file !== null && $line !== null) {
                return [$file, $line];
            }
        }

        return null;
    }

    /**
     * @param array<string, list<ThresholdOverride>> $thresholdOverridesByFile
     */
    private static function findAnnotationOverride(
        ViolationChannel $channel,
        array $thresholdOverridesByFile,
        RelativePath $file,
        int $line,
    ): ?ThresholdOverride {
        $context = new AnalysisContext(
            metrics: self::nullMetrics(),
            thresholdOverrides: $thresholdOverridesByFile,
        );

        return $context->getThresholdOverride($channel->ruleName, $file, $line);
    }

    /**
     * A {@see MetricRepositoryInterface} that answers nothing — the argument
     * {@see AnalysisContext} requires but {@see AnalysisContext::getThresholdOverride()}
     * never reads. Building one here is cheaper and safer than duplicating
     * that method's smallest-scope-wins matching a second time.
     */
    private static function nullMetrics(): MetricRepositoryInterface
    {
        return new class implements MetricRepositoryInterface {
            public function get(SymbolPath $symbol): MetricBag
            {
                return new MetricBag();
            }

            public function all(SymbolType $type): iterable
            {
                return [];
            }

            public function has(SymbolPath $symbol): bool
            {
                return false;
            }

            public function add(SymbolPath $symbol, MetricBag $metrics, ?RelativePath $file, ?int $line): void {}

            public function addScalar(SymbolPath $symbol, string $key, int|float $value): void {}

            public function getNamespaces(): array
            {
                return [];
            }

            public function forNamespace(string $namespace): array
            {
                return [];
            }
        };
    }
}
