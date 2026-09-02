<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Policy\Inline\Contract\Directive;

use Qualimetrix\Analysis\Finding\Contract\Rule\AnalysisContext;
use Qualimetrix\Analysis\Finding\Contract\RuleExecutionInterface;
use Qualimetrix\Analysis\Finding\Contract\RuleExecutionResult;

/**
 * The prepared run a threshold audit re-executes rules against.
 *
 * All three fields come from one `analyze()`-shaped run and belong together:
 * the context carries the metrics, the graph and the namespace tree that
 * re-execution reuses instead of collecting again, the executor is the one
 * that produced the baseline, and the baseline result is what every
 * counterfactual is compared against. Passing the result rather than asking
 * for it again is what keeps the sweep at one execution per directive plus the
 * two identity passes.
 *
 * The sweep scope is a field because it belongs to the question rather than to
 * the auditor: the same audit answers it narrowly for a run and fully for the
 * control that licenses the narrowing, and an auditor holding the choice would
 * have to be rebuilt to be asked the other way.
 *
 * The authored directives are not a field: they are already in
 * {@see AnalysisContext::$thresholdOverrides}, keyed by file, in exactly the
 * expansion the rules read. A second copy would be a second chance to disagree
 * with what the run actually applied.
 */
final readonly class ThresholdDirectiveAuditInput
{
    public function __construct(
        public AnalysisContext $baseline,
        public RuleExecutionInterface $executor,
        public RuleExecutionResult $baselineResult,
        public DirectiveSweepScope $sweep = DirectiveSweepScope::Narrow,
    ) {}
}
