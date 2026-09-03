<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Run\Contract\Pipeline;

use Qualimetrix\Analysis\Policy\Inline\Contract\Directive\DirectiveSweepScope;
use Qualimetrix\Analysis\Policy\Inline\Contract\Directive\DirectiveVerdict;

/**
 * What every inline directive of one run did, plus what the answer is relative
 * to.
 *
 * Both halves of the subject arrive in one list: a reader asking "does this
 * annotation still earn its place" does not care which of the two tags they
 * wrote, and {@see DirectiveVerdict::$form} says which one anyway.
 *
 * The coverage is not decoration. A verdict is a statement about the run that
 * produced it — a threshold retuning a metric computed over the analysed
 * subgraph can be live over one tree and dead over a subdirectory of it — so
 * the scope it was measured under travels with it. `$producedFindings` is the
 * size of the universe the verdicts were judged against: what the rules
 * produced, and not what a report would have published. It stops one short of
 * everything a run assembles: the channel put together after rule execution is
 * outside it, because no directive may address that channel and so no verdict
 * is judged against it. A count including it would name a universe the
 * verdicts were not measured in.
 *
 * The **sweep scope** is context of the same kind and is carried for the same
 * reason: it says how each verdict was measured. The two scopes answer the same
 * question and a difference between them is a defect rather than a preference,
 * so this is not an invitation to compare reports — it is what lets a report
 * state which measurement produced it.
 *
 * The **rule selection** the run resolved is the other half of that context and
 * is deliberately absent: `RuleSelection` is Finding's internal type, so
 * carrying it here would be an unapproved exact grant, and the caller that
 * needs to print it — a command — resolved those selectors itself and prints
 * them from its own copy.
 */
final readonly class DirectiveAuditReport
{
    /** @param list<DirectiveVerdict> $verdicts */
    public function __construct(
        public array $verdicts,
        public AnalysisCoverage $coverage,
        public int $producedFindings,
        public DirectiveSweepScope $sweep = DirectiveSweepScope::Narrow,
    ) {}
}
