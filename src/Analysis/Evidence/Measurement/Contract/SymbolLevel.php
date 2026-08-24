<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Evidence\Measurement\Contract;

/**
 * Represents the hierarchical level of a code symbol in the aggregation tree.
 *
 * Hierarchy (from leaf to root):
 *   Callable → Class → File → Namespace → Project
 *
 * @qmx-threshold coupling.cbo 80 -- The project's one level vocabulary, with zero outbound dependencies: every edge counted here is inbound, and the only way to lower it is to spell the level in more than one enum again, which is the defect this hub replaced. Raw CBO 79 gets one-edge headroom. It was 77 until the two configuration validators became declaring producers of their own: a producer that declares a channel must name the levels it reports at, so each one costs this hub exactly one inbound edge. That is the price of a single level vocabulary, measured rather than estimated, and paying it here is the point.
 * @qmx-threshold coupling.class-rank warning=0.045 error=0.045 -- Same intentional contract-hub role as MetricBag. A point threshold is scaled by project size before comparison, so it is not read against the raw value directly: this project's default point 0.02 is reported as an effective 0.0069, i.e. a factor of about 0.344, which puts 0.045 at roughly 0.0155 against the observed raw ClassRank 0.014958 -- up from 0.0144502 after ADR 0031 (rule-vocabulary plan Ш4c) redistributed the whole-project dependency graph PageRank runs over; ClassRank is a relative measure, so a new frequently-imported type (`ChannelShape`) shifting in shifts every other hub's share too, without this file gaining an edge of its own. Warning and error are deliberately equal: there is no band in which growing fan-in on the level vocabulary is a warning rather than the expected shape.
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
