<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Evidence\Measurement\Contract;

/**
 * Represents the hierarchical level of a code symbol in the aggregation tree.
 *
 * Hierarchy (from leaf to root):
 *   Callable → Class → File → Namespace → Project
 *
 * @qmx-threshold coupling.cbo 78 -- The project's one level vocabulary, with zero outbound dependencies: every edge counted here is inbound, and the only way to lower it is to spell the level in more than one enum again, which is the defect this hub replaced. Raw CBO 77 gets one-edge headroom.
 * @qmx-threshold coupling.class-rank warning=0.043 error=0.043 -- Same intentional contract-hub role as MetricBag. A point threshold is scaled by project size before comparison, so it is not read against the raw value directly: this project's default point 0.02 is reported as an effective 0.0069, i.e. a factor of about 0.345, which puts 0.043 at roughly 0.0148 against the observed raw ClassRank 0.0144502. Warning and error are deliberately equal: there is no band in which growing fan-in on the level vocabulary is a warning rather than the expected shape.
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
