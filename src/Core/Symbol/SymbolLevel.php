<?php

declare(strict_types=1);

namespace Qualimetrix\Core\Symbol;

/**
 * Represents the hierarchical level of a code symbol in the aggregation tree.
 *
 * Hierarchy (from leaf to root):
 *   Callable → Class → File → Namespace → Project
 *
 * The projection onto SymbolType is deliberately elsewhere: SymbolLevelProjection
 * owns it, which is what keeps this enum's outbound dependency count at zero.
 * Every edge counted against it is inbound, and the only way to lower that count
 * is to spell the level in more than one enum again — the defect this one hub
 * replaced.
 *
 * @qmx-threshold coupling.cbo 105 -- Raw CBO 104 with one edge of headroom. This channel is excluded by namespace for `Core\Symbol`, so this threshold does not decide the published report or the exit code; it decides whether the hub is reported at all, i.e. whether it appears in `--show-suppressed` and in the suppression count. Keeping it there means a step that concentrates more of the vocabulary here has to move this number and say why.
 * @qmx-threshold coupling.class-rank warning=0.045 error=0.045 -- Same intentional contract-hub role as MetricBag, and the same suppressed-report reach as the CBO threshold above. A point threshold is scaled by project size before comparison: this project's default point 0.02 is reported as an effective 0.0069, which puts 0.045 at roughly 0.0155 against the observed raw ClassRank 0.014. Warning and error are deliberately equal — there is no band in which growing fan-in on the level vocabulary is a warning rather than the expected shape.
 */
enum SymbolLevel: string
{
    /** Callable level (leaf level for metrics such as CCN) */
    case Callable = 'callable';

    /** Class, interface, trait, or enum level */
    case Class_ = 'class';

    /** File level (for file-scoped metrics like LOC) */
    case File = 'file';

    /** Namespace level (aggregation target) */
    case Namespace_ = 'namespace';

    /** Project level (root of aggregation tree) */
    case Project = 'project';
}
