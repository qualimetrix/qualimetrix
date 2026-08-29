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
 * **A registered rule here is a producer, not a class.** {@see allRules()}
 * enumerates every name a finding can be published under, which is a larger
 * set than the rule classes the container instantiates: the computed-metric
 * family runs in one class and publishes under seven producer names.
 * Execution is still per instance — a producer without a class has nothing to
 * run — so only {@see execute()} is keyed by instance.
 */
interface RuleExecutionInterface
{
    /**
     * Executes all active rules and returns what happened.
     */
    public function execute(AnalysisContext $context): RuleExecutionResult;

    /**
     * Every registered producer, each carrying whether the resolved selection
     * leaves it enabled.
     *
     * One answer, not three: the enabled subset and the count were separate
     * operations that nothing outside tests ever asked for, and three
     * enumerations of "every registered rule" are three chances to disagree.
     * A caller that wants either filters or counts this list.
     *
     * @return list<RuleMetadata>
     */
    public function allRules(): array;
}
