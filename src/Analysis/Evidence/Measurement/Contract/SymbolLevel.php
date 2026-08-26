<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Evidence\Measurement\Contract;

/**
 * Represents the hierarchical level of a code symbol in the aggregation tree.
 *
 * Hierarchy (from leaf to root):
 *   Callable → Class → File → Namespace → Project
 *
 * @qmx-threshold coupling.cbo 105 -- The project's one level vocabulary, with zero outbound dependencies: every edge counted here is inbound, and the only way to lower it is to spell the level in more than one enum again, which is the defect this hub replaced. Raw CBO 104 gets one-edge headroom. It was 87 until the level became the key of the aggregation query (rule-vocabulary plan Ш5e1): `MetricRepositoryInterface::all()` takes a level rather than a declaration kind, so every caller that used to ask `Core\Symbol\SymbolType` for a bucket now asks this enum, and the computed-metric definition, the threshold-key registry and the HTML tree read their level words off it instead of spelling them. Eighteen edges moved off SymbolType and onto this hub in one step; none of them is new coupling, each is coupling that used to point at the declaration kind for a question that was never about declaration kinds. It was 84 until the round-11 repair of Ш5c gave the vocabulary two more readers, and both are the repair's own point: the key validator of the namespace-channel option now names the one level that option can ask about instead of accepting any, and the computed-metric override reader replaced a fourth, independent hand-written level dictionary with this one. It was 79 until Ш5c took the level out of channel names: a level is a coordinate beside the name now, so the selector grammar, the finding itself, rule selection, the namespace-channel exclusions and the two suppression types read this vocabulary instead of parsing a name suffix. It was 77 before that, when the two configuration validators became declaring producers of their own: a producer that declares a channel must name the levels it reports at, so each one costs this hub exactly one inbound edge. That is the price of a single level vocabulary, measured rather than estimated, and paying it here is the point. The projection onto SymbolType is deliberately not here — SymbolLevelProjection owns it, which is what keeps this enum's outbound count at zero.
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
