<?php

declare(strict_types=1);

namespace Qualimetrix\Core\Metric;

/**
 * Represents the hierarchical level of a code symbol in the aggregation tree.
 *
 * Hierarchy (from leaf to root):
 *   Method → Class → File → Namespace → Project
 */
enum SymbolLevel: string
{
    /** Method or function level (leaf level for metrics like CCN) */
    case Method = 'method';

    /** Class, interface, trait, or enum level */
    case Class_ = 'class';

    /** File level (for file-scoped metrics like LOC) */
    case File = 'file';

    /** Namespace level (aggregation target) */
    case Namespace_ = 'namespace';

    /** Project level (root of aggregation tree) */
    case Project = 'project';
}
