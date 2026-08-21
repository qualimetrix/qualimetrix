<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Finding\Contract\Rule;

/**
 * How a rule is grouped for **display** — `qmx rules --group` and report
 * headings — and nothing else.
 *
 * A category is deliberately not addressable: no directive and no selector
 * matches on it. It used to double as a group matcher, deriving membership
 * from the first dot-separated segment of a rule name, which quietly made the
 * name space's spelling a behavioural contract. Behaviour that used to be
 * derived here is now declared where it belongs — a channel that path and
 * namespace exclusions cannot touch says so itself, see
 * {@see \Qualimetrix\Analysis\Finding\Contract\Filter\ChannelFileScope}.
 *
 * The residual consequence is harmless: a category value happening to equal
 * the first segment of a rule name (and `computed.health` disagreeing with
 * `Maintainability`) is now a correlation nothing reads.
 */
enum RuleCategory: string
{
    case Complexity = 'complexity';
    case Size = 'size';
    case Design = 'design';
    case Cohesion = 'cohesion';
    case Maintainability = 'maintainability';
    case Coupling = 'coupling';
    case Architecture = 'architecture';
    case CodeSmell = 'code-smell';
    case Security = 'security';
    case Duplication = 'duplication';

    /** Source annotations themselves — what a `@qmx-*` directive says about the configuration. */
    case Annotation = 'annotation';
}
