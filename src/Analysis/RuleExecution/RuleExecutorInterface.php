<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\RuleExecution;

use Qualimetrix\Core\Rule\AnalysisContext;
use Qualimetrix\Core\Rule\RuleInterface;
use Qualimetrix\Core\Violation\Violation;

/**
 * Executes analysis rules with runtime filtering.
 *
 * This interface decouples rule execution from the Analyzer,
 * allowing rules to be filtered at runtime based on configuration
 * (disabled_rules, only_rules) without affecting DI container setup.
 */
interface RuleExecutorInterface
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
     * @return list<RuleInterface>
     */
    public function getActiveRules(): array;

    /**
     * Returns count of all registered rules (before filtering).
     */
    public function getTotalRulesCount(): int;
}
