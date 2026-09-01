<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Run\Pipeline;

use Qualimetrix\Analysis\Policy\Inline\Contract\Directive\DirectiveVerdict;
use Qualimetrix\Analysis\Run\Contract\Pipeline\AnalysisCoverage;

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
 * size of the universe the verdicts were judged against, which is the produced
 * set and not the published one.
 *
 * The **rule selection** the run resolved is the other half of that context and
 * is deliberately absent: `RuleSelection` is Finding's internal type, so
 * carrying it here would be an unapproved exact grant, and the caller that
 * needs to print it — a command — resolved those selectors itself.
 *
 * Internal to Run until the command that reads it lands: a contract with no
 * consumer is a declaration the manifest checker refuses, and rightly.
 */
final readonly class DirectiveAuditReport
{
    /** @param list<DirectiveVerdict> $verdicts */
    public function __construct(
        public array $verdicts,
        public AnalysisCoverage $coverage,
        public int $producedFindings,
    ) {}
}
