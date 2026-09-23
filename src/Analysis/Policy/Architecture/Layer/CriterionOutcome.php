<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Policy\Architecture\Layer;

/**
 * What a criterion says about a class: it holds, it does not hold, or the run
 * never collected the facts needed to say either.
 *
 * The third case used to be spelled as the second. `extends` and `implements`
 * are answered from the transitive closure {@see ClassContextFactory} walks
 * over the run's declaration edges, and that walk stops wherever the next link
 * was not analysed — a vendor class, or anything outside `paths:`. An empty
 * parent set then reads exactly like "this class has no parents", so a chain
 * whose middle link left the analysed set produced a confident non-match. The
 * same holds for a subject the run never analysed at all: a dependency-edge
 * end outside the analysed set carries no attributes, interfaces or parents,
 * and every graph-backed criterion answered "no" about it.
 *
 * Note which case is NOT affected, because it decides where the cure goes: a
 * criterion naming the class's own DIRECT parent still matches even when that
 * parent is vendor code, since the edge is recorded from the analysed child.
 * Only a link further up the chain is missing.
 *
 * {@see MatchMode} combines per-kind outcomes three-valued (Kleene): under
 * {@see MatchMode::Any} one {@see Matches} decides, and {@see DoesNotMatch}
 * requires every declared kind to be decided; under {@see MatchMode::All} one
 * {@see DoesNotMatch} decides, and {@see Matches} requires every declared kind
 * to be decided. {@see Undecidable} is what is left over, and it must reach a
 * reader rather than collapse back into {@see DoesNotMatch} — see
 * {@see \Qualimetrix\Analysis\Policy\Architecture\LayerViolation\DeclaredLayerReachability::coverage()},
 * where it is named in `architecture.coverage-gap`.
 */
enum CriterionOutcome
{
    case Matches;
    case DoesNotMatch;
    case Undecidable;
}
