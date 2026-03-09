<?php

declare(strict_types=1);

namespace AiMessDetector\Reporting\Debt;

/**
 * Registry of estimated remediation times (in minutes) per rule.
 *
 * Times represent the average effort to fix a single violation of each rule.
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
     * Returns the estimated remediation time in minutes for the given rule.
     */
    public function getMinutes(string $ruleName): int
    {
        return self::MINUTES_BY_RULE[$ruleName] ?? self::DEFAULT_MINUTES;
    }
}
