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
