<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Finding\Contract\Filter;

/**
 * The stages a finding passes through between analysis and reporting, and
 * the only vocabulary in which their order is expressed.
 *
 * The order they must run in is a *behavioural* contract, not an
 * implementation detail: `@qmx-ignore` and the exclusions run before the
 * baseline so that one set is measured, captured and compared, and git scope runs last so that report narrowing
 * cannot change what was accepted or what went stale under ADR 0017's
 * pipeline order. A test that
 * pins that order needs something to name the stages with; inferring the
 * order from counts cannot distinguish "the baseline ran fourth" from "the
 * baseline happened to remove nothing".
 *
 * **Why a closed enum, and where it lives.** Closed, because the pipeline is
 * one fixed sequence rather than an extension point — an open string would
 * let two stages claim one name and would make the order assertion
 * stringly-typed. It lives beside the finding
 * contract because the stage vocabulary must be reachable from `Baseline`,
 * which is permitted to depend on it; naming a stage is not depending on the
 * component that implements it, and no case here implies an edge onto
 * `Infrastructure`. The docblock said `Core` until Ш6: the enum has not been
 * there since the capability layout landed, and the reason it gave outlived
 * the address it named.
 *
 * **The price, stated so it is not rediscovered later as a defect.**
 * `GitScope` is named here and implemented in `Infrastructure`, so this enum
 * and its implementations co-change across a component boundary — the second
 * cohesion test of ADR 0016, failed knowingly. Any new stage, wherever it
 * lives, is declared here; until it is, it has no place in the order and no
 * answer to {@see definesMeasuredSet()}. That is what a checkable order
 * costs: an open stage list could only be asserted against itself, which is
 * exactly what ADR 0017 needs the order not to be. Nothing here is a dependency
 * — a case is a name, not a class reference — so the price is paid in edits,
 * not in coupling.
 */
enum FindingFilterStage: string
{
    /** `@qmx-ignore` tags read from the analysed source. */
    case Suppression = 'suppression';

    /** `suppress_paths` from configuration and from `check`'s own flags. */
    case PathExclusion = 'path-exclusion';

    /** `suppress_namespaces`; `architecture.*` findings are exempt by design. */
    case NamespaceExclusion = 'namespace-exclusion';

    /** The accepted-level ceiling: it suppresses, promotes, or does neither. */
    case Baseline = 'baseline';

    /** `--report=git:*` narrowing — presentation only, and therefore last. */
    case GitScope = 'git-scope';

    /**
     * Whether this stage is part of what produces the measured set (ADR 0017):
     * the findings a baseline captures, compares against and calls stale.
     *
     * The set is the input to the baseline stage — everything the
     * suppression and exclusion stages leave standing, and everything git
     * scope has not yet narrowed away. Asking the enum rather than counting
     * stages keeps that boundary in the same place as the order it belongs
     * to: a stage inserted later declares which side of the line it is on
     * instead of silently shifting the set by its position.
     */
    public function definesMeasuredSet(): bool
    {
        return match ($this) {
            self::Suppression, self::PathExclusion, self::NamespaceExclusion => true,
            self::Baseline, self::GitScope => false,
        };
    }
}
