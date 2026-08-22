<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Evidence\Prioritization\Debt;

use LogicException;
use Qualimetrix\Analysis\Finding\Contract\ChannelDeclarationRegistryInterface;
use Qualimetrix\Analysis\Finding\Contract\Rule\RuleRemediationMinutesReader;
use Qualimetrix\Analysis\Finding\Contract\Violation;

/**
 * Registry of estimated remediation times (in minutes) per rule.
 *
 * Base times represent the average effort for a typical violation. When a violation
 * carries metricValue and threshold, the time is scaled by how far the metric
 * exceeds the threshold: base * max(1, ln(metricValue / threshold)).
 *
 * The base time itself is not this class's fact: every registered rule
 * declares its own via a `REMEDIATION_MINUTES` constant, read reflectively
 * by {@see RuleRemediationMinutesReader} at container-build time and handed
 * in as `$minutesByRule` — the same idiom, and the same compiler pass
 * ({@see \Qualimetrix\Infrastructure\DependencyInjection\CompilerPass\ChannelDeclarationCompilerPass}),
 * that already assembles `$docsPageByRule` for
 * {@see \Qualimetrix\Analysis\Finding\ChannelPresentationView}. A private
 * table here, kept in sync by hand across every capability that adds a rule,
 * is exactly the copy this whole plan exists to remove — co-change history
 * showed every rule-adding commit editing this table too, and it had already
 * drifted (`code-smell.god-class` / `code-smell.data-class`, renamed on the
 * rule but not here, until this class stopped keeping the fact at all).
 *
 * The direction that makes a channel's overshoot ratio go the right way is
 * read from {@see ChannelDeclarationRegistryInterface::declarationFor()} —
 * the same registry {@see \Qualimetrix\Reporting\FindingProjection\FindingProjector}
 * consumes — rather than kept as a private copy here, via
 * {@see \Qualimetrix\Analysis\Finding\Contract\ChannelDeclaration::isLowerWorse()}.
 * A `magnitude` channel whose smaller number is worse (e.g. maintainability
 * index) has its ratio flipped to threshold / metricValue; a `computed.*` /
 * `health.*` channel resolves the same way, since the registry already
 * derives its direction from
 * {@see \Qualimetrix\Analysis\Evidence\ComputedMetrics\Contract\Definition\ComputedMetricDefinition::$inverted}.
 *
 * The policy is fail-closed: a channel whose declaration carries no
 * direction — no declaration at all, or an `occurrence` shape, which is
 * forbidden a direction by {@see \Qualimetrix\Analysis\Finding\Contract\ChannelDeclaration} —
 * is not scaled and takes the flat base time. `coupling.class-rank` is the
 * one registered channel this currently affects: it is declared `occurrence`
 * because its reported rank is a project-wide normalised PageRank whose
 * threshold is rescaled per class count, so a stored rank is not comparable
 * to a later run's units — see `ClassRankRule::channelDeclarations()`.
 * Scaling it by a direction it cannot supply would have no justification,
 * so it no longer is.
 */
final class RemediationTimeRegistry
{
    /**
     * @param array<string, int> $minutesByRule every registered rule name => its declared
     *                                          `REMEDIATION_MINUTES`, injected by
     *                                          {@see \Qualimetrix\Infrastructure\DependencyInjection\CompilerPass\ChannelDeclarationCompilerPass}
     */
    public function __construct(
        private ChannelDeclarationRegistryInterface $declarations,
        private array $minutesByRule = [],
    ) {}

    /**
     * Returns the base remediation time in minutes for the given rule (without scaling).
     *
     * @throws LogicException when `$ruleName` names no registered rule — the injected
     *                        map is built from every rule the container knows about, so a
     *                        miss means a violation carries a rule name no rule declared,
     *                        not a legitimately unknown one to fall back for.
     */
    public function getBaseMinutes(string $ruleName): int
    {
        return $this->minutesByRule[$ruleName] ?? throw new LogicException(\sprintf(
            'No remediation minutes declared for rule "%s". Every registered rule must declare a'
            . ' REMEDIATION_MINUTES constant, read via %s.',
            $ruleName,
            RuleRemediationMinutesReader::class,
        ));
    }

    /**
     * Returns the estimated remediation time in minutes for a specific violation.
     *
     * When the violation carries metricValue and threshold, the base time is scaled
     * by the natural log of the overshoot ratio: base * max(1, ln(value / threshold)).
     * This means minor overshoots get ~base time, while extreme violations get much more.
     *
     * Falls back to the flat base time when metricValue or threshold is missing.
     */
    public function getMinutesForViolation(Violation $violation): int
    {
        $base = $this->getBaseMinutes($violation->ruleName);
        $ratio = $this->overshootRatio($violation);

        // Below/at 1.0 means not exceeding threshold (edge case) — use base;
        // null covers every reason scaling does not apply (missing metric
        // data, an unusable magnitude, or a channel the fail-closed policy
        // above declines to scale).
        if ($ratio === null || $ratio <= 1.0) {
            return $base;
        }

        $scaled = (int) round($base * max(1.0, log($ratio)));

        return max(1, $scaled);
    }

    /**
     * How far the violation's metric overshoots its threshold, already
     * flipped into "bigger means worse" terms — or `null` when there is no
     * usable overshoot to scale by.
     *
     * Split out of {@see getMinutesForViolation()} because the two guard
     * chains (missing/unusable metric data, then the fail-closed direction
     * lookup) multiply the containing method's NPath when inlined; as a
     * separate method each chain's branches only add.
     */
    private function overshootRatio(Violation $violation): ?float
    {
        if ($violation->metricValue === null || $violation->threshold === null) {
            return null;
        }

        $metricValue = (float) $violation->metricValue;
        $threshold = (float) $violation->threshold;

        if (!self::isUsableMagnitude($metricValue) || !self::isUsableMagnitude($threshold)) {
            return null;
        }

        $isLowerWorse = $this->declarations->declarationFor($violation->channel())?->isLowerWorse();

        // Fail-closed: a channel whose declaration carries no direction (no
        // declaration at all, or an `occurrence` shape) is not scaled.
        if ($isLowerWorse === null) {
            return null;
        }

        return $isLowerWorse
            ? $threshold / $metricValue
            : $metricValue / $threshold;
    }

    /**
     * Whether a metric or threshold value can meaningfully sit on either
     * side of an overshoot ratio: positive, finite, and not NaN.
     */
    private static function isUsableMagnitude(float $value): bool
    {
        return $value > 0.0 && !is_nan($value) && !is_infinite($value);
    }
}
