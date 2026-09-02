<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Analysis\Policy\Inline\Support;

use Qualimetrix\Analysis\Finding\Contract\Rule\AnalysisContext;
use Qualimetrix\Analysis\Finding\Contract\RuleExclusionStats;
use Qualimetrix\Analysis\Finding\Contract\RuleExecutionInterface;
use Qualimetrix\Analysis\Finding\Contract\RuleExecutionResult;

/**
 * Several rules, each honestly narrowing itself, run together.
 *
 * {@see ScriptedThresholdRuleExecution} models one rule and already refuses to
 * run when `$restrictToProducer` names someone else — the claim under test is
 * whether a *tree of several rules* is judged the same way whether the sweep
 * asks one producer at a time or all of them at once, and a single-rule
 * fixture cannot pose that question. This one poses it by delegating to each
 * named sub-executor and trusting its own narrowing, exactly as
 * {@see \Qualimetrix\Analysis\Finding\RuleExecution} delegates to whichever of
 * its rule instances the selection leaves active.
 */
final class MultiRuleThresholdRuleExecution implements RuleExecutionInterface
{
    /** @param array<string, ScriptedThresholdRuleExecution> $byRule rule name => the rule that answers for it */
    public function __construct(private readonly array $byRule) {}

    public function execute(AnalysisContext $context, ?string $restrictToProducer = null): RuleExecutionResult
    {
        $produced = [];
        $published = [];

        foreach ($this->byRule as $rule) {
            $result = $rule->execute($context, $restrictToProducer);
            $produced = [...$produced, ...$result->produced];
            $published = [...$published, ...$result->published];
        }

        return new RuleExecutionResult($produced, $published, new RuleExclusionStats());
    }

    public function allRules(): array
    {
        return [];
    }
}
