<?php

declare(strict_types=1);

namespace Qualimetrix\Core\Suppression;

/**
 * Represents a @qmx-threshold annotation from a docblock.
 *
 * Allows per-symbol threshold overrides for rules. Two syntaxes:
 * - Shorthand: @qmx-threshold complexity.cyclomatic 15 (sets both warning and error)
 * - Explicit: @qmx-threshold complexity.cyclomatic warning=15 error=25
 * - Partial: @qmx-threshold complexity.cyclomatic warning=15 (override warning only)
 */
final readonly class ThresholdOverride
{
    /**
     * @param string $rulePattern Rule name or prefix (supports RuleMatcher)
     * @param int|float|null $warning Warning threshold override (null = keep default)
     * @param int|float|null $error Error threshold override (null = keep default)
     * @param int $line Docblock line (for diagnostics)
     * @param int|null $endLine Symbol end line (scope)
     */
    public function __construct(
        public string $rulePattern,
        public int|float|null $warning,
        public int|float|null $error,
        public int $line,
        public ?int $endLine = null,
    ) {}

    /**
     * Checks if this override matches the given rule name.
     *
     * Supports:
     * - Wildcard '*' to override all rules
     * - Prefix matching: 'complexity' matches 'complexity.cyclomatic'
     * - Exact matching: 'complexity.cyclomatic' matches 'complexity.cyclomatic'
     */
    public function matches(string $ruleName): bool
    {
        if ($this->rulePattern === '*') {
            return true;
        }

        return \Qualimetrix\Core\Rule\RuleMatcher::matches($this->rulePattern, $ruleName);
    }
}
