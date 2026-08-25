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
 *
 * **A registered rule here is a producer, not a class.** The three registry
 * answers below enumerate every name a finding can be published under, which
 * is a larger set than the rule classes the container instantiates: the
 * computed-metric family runs in one class and publishes under seven producer
 * names. Execution is still per instance — a producer without a class has
 * nothing to run — so only {@see execute()} is keyed by instance.
 */
interface RuleExecutionInterface
{
    /**
     * Executes all active rules and returns findings.
     *
     * @return list<Finding>
     */
    public function execute(AnalysisContext $context): array;

    /**
     * The producers `$selection` leaves enabled.
     *
     * @return list<RuleMetadata>
     */
    public function activeRules(RuleSelection $selection): array;

    /**
     * Every registered producer, each carrying whether the resolved selection
     * leaves it enabled — the same enumeration {@see activeRules()} filters.
     *
     * @return list<RuleMetadata>
     */
    public function allRules(): array;

    /**
     * How many producers {@see allRules()} enumerates.
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
