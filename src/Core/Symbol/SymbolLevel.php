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
 * @qmx-threshold coupling.class-rank warning=0.045 error=0.045 -- Same intentional contract-hub
 *                role as MetricBag. This channel is excluded by namespace for `Core\Symbol`,
 *                so the threshold decides only whether the hub is reported at all — whether it
 *                appears under `--show-suppressed` and in the suppression count — not the
 *                published report or the exit code. Project-size scaling maps 0.045 to 0.0153
 *                against the observed raw ClassRank 0.0133, a margin wider than one step for
 *                the same reason as MetricBag's. Warning and error are deliberately equal:
 *                there is no band in which growing fan-in on the level vocabulary is a warning
 *                rather than the expected shape.
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
