<?php

declare(strict_types=1);

namespace Qualimetrix\Core\Rule;

use Qualimetrix\Core\Violation\Violation;

/**
 * Rule that operates on multiple levels of code hierarchy.
 *
 * Hierarchical rules can analyze code at method, class, and/or namespace levels,
 * with different thresholds and logic for each level.
 */
interface HierarchicalRuleInterface extends RuleInterface
{
    /**
     * Returns levels at which this rule operates.
     *
     * @return list<RuleLevel>
     */
    public function getSupportedLevels(): array;

    /**
     * Analyzes code at a specific level.
     *
     * @return list<Violation>
     */
    public function analyzeLevel(RuleLevel $level, AnalysisContext $context): array;
}
