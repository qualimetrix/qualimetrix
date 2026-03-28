<?php

declare(strict_types=1);

namespace Qualimetrix\Reporting\Debt;

use Qualimetrix\Core\Violation\Violation;

/**
 * Registry of estimated remediation times (in minutes) per rule.
 *
 * Base times represent the average effort for a typical violation. When a violation
 * carries metricValue and threshold, the time is scaled by how far the metric
 * exceeds the threshold: base * max(1, ln(metricValue / threshold)).
 *
 * For inverted metrics (lower value = worse, e.g. maintainability index),
 * the ratio is flipped: threshold / metricValue.
 */
final class RemediationTimeRegistry
{
    private const int DEFAULT_MINUTES = 15;

    /** @var array<string, int> */
    private const array MINUTES_BY_RULE = [
        // Complexity
        'complexity.cyclomatic' => 30,
        'complexity.cognitive' => 30,
        'complexity.npath' => 30,
        'complexity.wmc' => 30,

        // Coupling
        'coupling.cbo' => 45,
        'coupling.class-rank' => 30,
        'coupling.instability' => 30,
        'coupling.distance' => 30,

        // Design
        'design.inheritance' => 30,
        'design.noc' => 20,
        'design.type-coverage' => 15,
        'design.lcom' => 45,

        // Size
        'size.class-count' => 30,
        'size.method-count' => 20,
        'size.property-count' => 15,

        // Maintainability
        'maintainability.index' => 60,

        // Code smell
        'code-smell.constructor-overinjection' => 60,
        'design.data-class' => 30,
        'design.god-class' => 120,
        'code-smell.boolean-argument' => 10,
        'code-smell.debug-code' => 5,
        'code-smell.empty-catch' => 10,
        'code-smell.eval' => 15,
        'code-smell.exit' => 10,
        'code-smell.goto' => 15,
        'code-smell.superglobals' => 15,
        'code-smell.error-suppression' => 10,
        'code-smell.count-in-loop' => 10,
        'code-smell.long-parameter-list' => 20,
        'code-smell.unreachable-code' => 10,

        // Security
        'security.hardcoded-credentials' => 30,
        'security.sql-injection' => 60,
        'security.xss' => 45,
        'security.command-injection' => 60,
        'security.sensitive-parameter' => 10,

        // Architecture
        'architecture.circular-dependency' => 120,
    ];

    /**
     * Rules where lower metric values indicate worse code (inverted scales).
     *
     * For these rules, the overshoot ratio is threshold/metricValue instead of
     * metricValue/threshold.
     *
     * @var list<string>
     */
    private const array INVERTED_RULES = [
        'maintainability.index',
        'design.type-coverage',
    ];

    /**
     * Returns the base remediation time in minutes for the given rule (without scaling).
     */
    public function getBaseMinutes(string $ruleName): int
    {
        return self::MINUTES_BY_RULE[$ruleName] ?? self::DEFAULT_MINUTES;
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

        if ($violation->metricValue === null || $violation->threshold === null) {
            return $base;
        }

        $metricValue = (float) $violation->metricValue;
        $threshold = (float) $violation->threshold;

        if ($threshold <= 0.0 || $metricValue <= 0.0 || is_nan($metricValue) || is_nan($threshold)
            || is_infinite($metricValue) || is_infinite($threshold)) {
            return $base;
        }

        $isInverted = \in_array($violation->ruleName, self::INVERTED_RULES, true)
            || ($violation->ruleName === 'computed.health' && $this->isInvertedComputedMetric($metricValue, $threshold));

        $ratio = $isInverted
            ? $threshold / $metricValue
            : $metricValue / $threshold;

        // ratio <= 1 means not exceeding threshold (edge case) — use base
        if ($ratio <= 1.0) {
            return $base;
        }

        $scaled = (int) round($base * max(1.0, log($ratio)));

        return max(1, $scaled);
    }

    /**
     * Detects inverted computed metrics by checking if the metric value is below the threshold.
     *
     * For inverted health metrics (higher = better), the violation fires when
     * metricValue < threshold, so metricValue < threshold indicates inversion.
     */
    private function isInvertedComputedMetric(float $metricValue, float $threshold): bool
    {
        return $metricValue < $threshold;
    }
}
