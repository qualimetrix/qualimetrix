<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Run\Contract\Pipeline;

use Qualimetrix\Analysis\Policy\Inline\Contract\Directive\DirectiveSweepScope;
use Qualimetrix\Analysis\Run\Contract\Configuration\RunConfiguration;
use Qualimetrix\Analysis\Run\Contract\Discovery\FileDiscoveryInterface;

/**
 * The second question Run answers about one prepared run: what each inline
 * directive the run carried actually did.
 *
 * Separate from {@see AnalysisPipelineInterface} rather than a second operation
 * on it, exactly as {@see DependencyGraphAnalyzerInterface} is: the consumers of
 * that contract analyse and do not audit, and a contract nobody in that set
 * calls is surface for its own sake.
 *
 * **What a verdict is relative to.** The analysed scope, and nothing wider. A
 * directive retuning a metric computed over the analysed subgraph — coupling is
 * the standing case — is live over one tree and dead over a subdirectory of it,
 * and neither answer is wrong. There is no "was the whole project analysed"
 * flag to publish, because a resolved {@see RunConfiguration} no longer knows
 * what it was resolved from; the report states the coverage it measured under
 * and the caller judges. An **incomplete** run — files that failed to parse —
 * is a different thing, and `DirectiveAuditReport::$coverage` answers it
 * exactly as it does for an analysis.
 *
 * **What the audit does not measure.** A directive's influence on the parsing
 * of itself. The validator that reports malformed and unaddressable directives
 * reads the run's configuration rather than the counterfactual context, so its
 * own diagnostics are identical on every pass of the sweep; the verdicts here
 * are about a directive's effect on measurement.
 */
interface DirectiveAuditInterface
{
    /**
     * @param ?FileDiscoveryInterface $discovery the discovery the caller resolved, so the audited
     *                                           file set is the one an analysis of the same
     *                                           configuration would have measured
     * @param DirectiveSweepScope $sweep how much of the rule layer each counterfactual runs. The
     *                                   default executes only the rule a directive addresses, which
     *                                   is what the answer is about; {@see DirectiveSweepScope::Full}
     *                                   executes them all and exists so that the two can be compared
     *                                   on a real tree. Verdicts are the same object either way — a
     *                                   difference between them is a finding about shared state
     *                                   between rules
     */
    public function auditDirectives(
        RunConfiguration $configuration,
        ?FileDiscoveryInterface $discovery = null,
        DirectiveSweepScope $sweep = DirectiveSweepScope::Narrow,
    ): DirectiveAuditReport;
}
