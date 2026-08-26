<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Finding\Contract\Rule;

use Qualimetrix\Analysis\Finding\Contract\Finding;
use Qualimetrix\Analysis\Finding\Rule\RuleInterface;
use Qualimetrix\Core\Symbol\SymbolLevel;

/**
 * Rule that operates on multiple levels of code hierarchy.
 *
 * Hierarchical rules can analyze code at callable, class, and/or namespace levels,
 * with different thresholds and logic for each level.
 */
interface HierarchicalRuleInterface extends RuleInterface
{
    /**
     * Returns levels at which this rule operates.
     *
     * @return list<SymbolLevel>
     */
    public function getSupportedLevels(): array;

    /**
     * Analyzes code at a specific level.
     *
     * @return list<Finding>
     */
    public function analyzeLevel(SymbolLevel $level, AnalysisContext $context): array;
}
