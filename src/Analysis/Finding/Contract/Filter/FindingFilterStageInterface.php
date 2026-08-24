<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Finding\Contract\Filter;

use Qualimetrix\Analysis\Finding\Contract\Finding;

/**
 * One step of the finding pipeline: a list of findings in, a list of
 * findings out.
 *
 * **Why this exists next to {@see FindingFilterInterface} instead of
 * replacing it.** Most stages really are per-finding predicates, and
 * `shouldInclude(): bool` states that plainly; nothing is gained by making
 * `PathExclusionFilter` reason about lists. But a predicate cannot express
 * *rewriting* a finding it keeps, and the baseline ceiling has to: a group
 * measured against an entry it exceeds is reported at Error carrying the
 * level it was accepted at (ADR 0017), and
 * {@see Finding} is `final readonly` with no `with*` helper. Nor can a
 * predicate see a *group*: the ceiling's decision is about every finding
 * sharing one identity at once, which no per-finding callback can observe.
 *
 * A predicate reaches the pipeline through {@see PredicateFilterStage}, so
 * the pipeline runs one kind of thing in one loop and the ordered stage list
 * of {@see FindingFilterStage} is a single readable sequence.
 *
 * **Why the pipeline downcasts to reach the baseline's stale entries, and
 * why that is not an oversight.** Besides filtering, the ceiling reports the
 * entries whose identity never appeared (ADR 0017); that report is a
 * `list<BaselineEntry>`, a type owned by the `Baseline` component. This
 * interface and {@see FindingFilterStageResult} live in `Core`, which
 * `qmx.yaml` forbids from depending on `Baseline`, so the list cannot travel
 * through either of them. The two ways to avoid the downcast are both worse
 * than it: widening the result into an untyped diagnostics bag in `Core`
 * loses exactly the type that makes the report checkable, and moving the
 * stage contract out of `Core` puts it where `Baseline` cannot reach it. A
 * caller that wants the stale entries therefore asks the ceiling for them by
 * its own type; a caller that only wants filtering never learns it exists.
 */
interface FindingFilterStageInterface
{
    /**
     * Which stage this is — the identity a pipeline-order assertion reads.
     */
    public function stage(): FindingFilterStage;

    /**
     * @param list<Finding> $findings
     */
    public function apply(array $findings): FindingFilterStageResult;
}
