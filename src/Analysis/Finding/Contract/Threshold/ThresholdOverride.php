<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Finding\Contract\Threshold;

use Qualimetrix\Analysis\Finding\Contract\Control\ControlScope;
use Qualimetrix\Core\Symbol\MetricSubject;

/**
 * Represents a `@qmx-threshold` annotation from a docblock.
 *
 * Allows per-symbol threshold overrides for rules. Two syntaxes:
 * - Shorthand: `@qmx-threshold complexity.cyclomatic 15` (sets both warning and error)
 * - Explicit: `@qmx-threshold complexity.cyclomatic warning=15 error=25`
 * - Partial: `@qmx-threshold complexity.cyclomatic warning=15` (override warning only)
 */
final readonly class ThresholdOverride
{
    /**
     * @param string $rulePattern Exact rule name — see {@see matches()}
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
        public MetricSubject $subject,
        public ControlScope $controlScope,
        public ?int $endLine = null,
    ) {}

    /**
     * Checks whether this override addresses the given rule name.
     *
     * A threshold override addresses **one rule, by its exact name**. It takes
     * no group form at all, neither `X.*` nor the old bare prefix and lone
     * `*`: a threshold belongs to a single options object, so "reset the
     * threshold of everything under here" was never a directive that could
     * mean one thing. A per-symbol override of several rules is written as
     * several annotations.
     *
     * Note the asymmetry with `@qmx-ignore`, which addresses a *channel* and
     * may narrow it to one level: `@qmx-threshold coupling.cbo` is the rule,
     * `@qmx-ignore coupling.cbo:class` is the channel at the class level, and
     * neither spelling has to guess which was meant. A threshold takes no
     * level either (ADR 0024 §2) — a per-level boundary is a nested
     * configuration key, and the pair form is captured and refused here so it
     * cannot silently retune the whole rule.
     */
    public function matches(string $ruleName): bool
    {
        return $this->rulePattern === $ruleName;
    }
}
