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
 * **Why the `@qmx-threshold` match is not reimplemented here.**
 * {@see AnalysisContext::getThresholdOverride()} already picks the
 * smallest-scope override for a rule, file and line — the same rule
 * ADR 0017 asks `explain` to reuse rather than restate. It is Core, so this
 * package may call it; the only friction is that `AnalysisContext` also
 * demands a {@see MetricRepositoryInterface}, which the override lookup
 * never touches — {@see self::nullMetrics()} is that unused argument, not a
 * second metrics source.
 *
 * **Why the symbol's location does not come from its findings alone.** That
 * lookup needs a file and a line, and taking them from the symbol's current
 * violations answers only for symbols that are currently violating something.
 * The example ADR 0017 gives — "`qmx.yaml` says 10; annotation raises it to 40" —
 * is the opposite case: the annotation is usually *why* the rule no longer
 * fires, so exactly when it is most worth printing there is no finding left
 * to read a location off. {@see $symbolLocations} is the second source, and
 * the run's own metric repository is where every symbol it measured records
 * its declaration site.
 */
final readonly class BoundaryExplanationService
{
    /**
     * @param list<Violation> $measuredViolations the measured set (ADR 0017) this run produced —
     *                                            both the "currently compared" magnitudes of
     *                                            ADR 0017 and the symbol's file/line for matching
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
     * @param ?MetricRepositoryInterface $symbolLocations the run's measured symbols — read only
     *                                                    for the declaration site of a symbol
     *                                                    that reports no violation, which is
     *                                                    the case an `@qmx-threshold` usually
     *                                                    causes. `null` limits the annotation
     *                                                    lookup to symbols that are currently
     *                                                    violating something
     */
    public function explain(
        string $symbolKey,
        ?ViolationChannel $channelFilter,
        ?Baseline $baseline,
        array $measuredViolations,
        array $thresholdOverridesByFile,
        array $configuredThresholds,
        ?MetricRepositoryInterface $symbolLocations = null,
    ): BoundaryExplanation {
        $identities = self::relevantIdentities($symbolKey, $channelFilter, $baseline, $measuredViolations);
        $location = self::locationForSymbol($symbolKey, $measuredViolations, $symbolLocations);

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
     * The declaration site of the symbol, from its findings if it has any and
     * from the run's measured symbols otherwise.
     *
     * This is what {@see AnalysisContext::getThresholdOverride()} expects for
     * its `$file`/`$line` arguments: rules pass the symbol's own declaration
     * line (e.g. `$methodInfo->line`), not a violation's precise offending
     * line, so both sources answer the same question — a violation carries
     * the value the rule was given, and {@see MetricRepositoryInterface}
     * carries the value the collector recorded, which is where the rule got
     * it from.
     *
     * **The second source is not a fallback for tidiness; it is the case the
     * feature exists for.** An `@qmx-threshold` that raised a limit is
     * normally the reason the rule stopped firing, so the symbol most likely
     * to carry one is precisely the symbol with no violation to read a
     * location off. Consulting only the findings made `explain` silent about
     * the annotation in exactly the example ADR 0017 gives for it.
     *
     * `null` — and so no annotation reported — when neither source knows
     * where the symbol is declared: a symbol nothing measured, or an
     * aggregate (`ns:`, `project:`, and any `file:`-level metric recorded
     * without a line) that has no declaration line to scope an annotation by.
     *
     * @param list<Violation> $measuredViolations
     *
     * @return ?array{RelativePath, int}
     */
    private static function locationForSymbol(
        string $symbolKey,
        array $measuredViolations,
        ?MetricRepositoryInterface $symbolLocations,
    ): ?array {
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

        return $symbolLocations === null ? null : self::declarationSite($symbolKey, $symbolLocations);
    }

    /**
     * Where the run recorded this symbol's declaration, or `null` when it
     * recorded no usable site for it.
     *
     * Every symbol type is searched rather than the one the key's prefix
     * names: the prefix is parsed nowhere else in this package, and a scan
     * that compares whole canonical keys cannot mis-parse one. The cost is a
     * pass over the run's symbols for a single-symbol command.
     *
     * @return ?array{RelativePath, int}
     */
    private static function declarationSite(string $symbolKey, MetricRepositoryInterface $symbolLocations): ?array
    {
        foreach (SymbolType::cases() as $type) {
            foreach ($symbolLocations->all($type) as $info) {
                if ($info->symbolPath->toCanonical() !== $symbolKey) {
                    continue;
                }

                if ($info->file !== null && $info->line !== null) {
                    return [$info->file, $info->line];
                }
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
