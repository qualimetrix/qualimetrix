<?php

declare(strict_types=1);

namespace Qualimetrix\Core\Coverage;

use Qualimetrix\Core\Rule\AnalysisContext;

/**
 * Opt-in contract for rules that skip work the centre believes it covered.
 *
 * Coverage is central: a scope counts as evaluated when it was discovered,
 * parsed, not excluded from evaluation, and its rule was enabled and
 * completed. That reasoning is correct for the large majority of rules and
 * wrong for a handful that apply their own applicability test, disable an
 * individual level, or depend on an input that may be incomplete.
 *
 * Those rules — and only those — implement this interface. The list is
 * sparse by contract: a rule that reports a per-symbol map has misunderstood
 * it, and would also defeat the point of computing coverage centrally.
 *
 * Implementing this interface is not part of {@see \Qualimetrix\Core\Rule\RuleInterface};
 * the vast majority of rules deviate from central coverage in no way at all,
 * and forcing an empty method onto every one of them would make the empty
 * answer indistinguishable from an unconsidered one.
 */
interface CoverageDeviationReporterInterface
{
    /**
     * Scopes this rule did not evaluate, despite the centre counting them.
     *
     * Stateless, like {@see \Qualimetrix\Core\Rule\RuleInterface::analyze()}:
     * everything needed comes from the context.
     *
     * @return list<ScopeCoverage> Every element must be a non-evaluated
     *                             scope. An evaluated one carries no
     *                             information — the centre already assumed it.
     */
    public function coverageDeviations(AnalysisContext $context): array;
}
