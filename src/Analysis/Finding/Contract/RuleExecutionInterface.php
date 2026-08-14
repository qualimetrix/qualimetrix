<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Finding\Contract;

use Qualimetrix\Analysis\Finding\Contract\Rule\AnalysisContext;

/**
 * Executes analysis rules with runtime filtering.
 *
 * This interface decouples rule execution from the Analyzer,
 * allowing rules to be filtered at runtime based on configuration
 * (disabled_rules, only_rules) without affecting DI container setup.
 */
interface RuleExecutionInterface
{
    /**
     * Executes all active rules and returns violations.
     *
     * @return list<Violation>
     */
    public function execute(AnalysisContext $context): array;

    /**
     * Returns list of active (not disabled) rules.
     *
     * @return list<RuleMetadata>
     */
    public function activeRules(RuleSelection $selection): array;

    /**
     * Returns all registered rules (before filtering by disabled/only rules).
     *
     * @return list<RuleMetadata>
     */
    public function allRules(): array;

    /**
     * Returns count of all registered rules (before filtering).
     */
    public function totalRuleCount(): int;

    /**
     * Returns per-rule `exclude_namespaces`, `exclude_namespace_channels`, and
     * `exclude_paths` suppression stats from the most recent {@see execute()} call.
     *
     * Empty stats before the first call.
     */
    public function exclusionStats(): RuleExclusionStats;
}
