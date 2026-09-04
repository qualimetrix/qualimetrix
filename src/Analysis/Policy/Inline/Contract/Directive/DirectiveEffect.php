<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Policy\Inline\Contract\Directive;

/**
 * What an authored inline directive did in one run.
 *
 * Three of the four are answers; `Unmeasured` is the absence of one, and
 * keeping it distinct from `Inert` is the whole reason this is an enum rather
 * than a boolean. A directive addressing a rule that never ran did not fail to
 * do anything — nobody asked it.
 */
enum DirectiveEffect: string
{
    /**
     * It changed what the rules produced: a finding was silenced, or a verdict
     * moved.
     *
     * **Produced, not published — and the report is built from the second.**
     * Between the two stand the per-rule exclusion ledger, channel selection,
     * the ratchet and the git scope, each of which can drop a finding this
     * directive silenced first. Measured on a fixture: under
     * `rules.code-smell.boolean-argument.exclude_paths` covering the annotated
     * file, removing the directive changes the report by not one byte, and the
     * verdict in that same run is this one. It is the honest answer to "what
     * did this annotation do", which is the question the audit is asked; it is
     * not an answer to "will the report change if I delete it".
     *
     * A separate verdict for "effective, and publishes nothing" is a subject of
     * its own size — the audit would have to be handed the published set beside
     * the produced one — and is deliberately not part of this enum.
     */
    case Effective = 'effective';

    /**
     * It applied and changed nothing but the boundary the finding names: the
     * measured value had already passed the boundary the directive raised, so
     * the finding fired anyway. Produced by the threshold half only.
     *
     * The name describes the common case rather than every one. A directive
     * that *tightens* a boundary and still leaves the finding standing
     * produces the same shape of difference, and the rule layer has no notion
     * of which direction is stricter — `coupling.instability` is worse when
     * higher, `cohesion.tcc` when lower — so the two cannot be told apart
     * here. What the verdict states exactly is: applied, and nothing moved
     * except the boundary it printed.
     */
    case Overrun = 'overrun';

    /** It addressed something real and did nothing at all. */
    case Inert = 'inert';

    /** No answer is available; {@see DirectiveUnmeasurableReason} says why. */
    case Unmeasured = 'unmeasured';
}
