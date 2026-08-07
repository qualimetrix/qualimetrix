<?php

declare(strict_types=1);

namespace Qualimetrix\Core\Violation\Filter;

use Qualimetrix\Core\Violation\Violation;

/**
 * One step of the violation pipeline: a list of findings in, a list of
 * findings out.
 *
 * **Why this exists next to {@see ViolationFilterInterface} instead of
 * replacing it.** Most stages really are per-violation predicates, and
 * `shouldInclude(): bool` states that plainly; nothing is gained by making
 * `PathExclusionFilter` reason about lists. But a predicate cannot express
 * *rewriting* a finding it keeps, and the baseline ceiling has to: a group
 * measured against an entry it exceeds is reported at Error carrying the
 * level it was accepted at (§5.6, §8 of the baseline-ceiling plan), and
 * {@see Violation} is `final readonly` with no `with*` helper. Nor can a
 * predicate see a *group*: the ceiling's decision is about every finding
 * sharing one identity at once, which no per-violation callback can observe.
 *
 * A predicate reaches the pipeline through {@see PredicateFilterStage}, so
 * the pipeline runs one kind of thing in one loop and the ordered stage list
 * of {@see ViolationFilterStage} is a single readable sequence.
 *
 * **Why the pipeline downcasts to reach the baseline's stale entries, and
 * why that is not an oversight.** Besides filtering, the ceiling reports the
 * entries whose identity never appeared (§5.7); that report is a
 * `list<BaselineEntry>`, a type owned by the `Baseline` component. This
 * interface and {@see ViolationFilterStageResult} live in `Core`, which
 * `qmx.yaml` forbids from depending on `Baseline`, so the list cannot travel
 * through either of them. The two ways to avoid the downcast are both worse
 * than it: widening the result into an untyped diagnostics bag in `Core`
 * loses exactly the type that makes the report checkable, and moving the
 * stage contract out of `Core` puts it where `Baseline` cannot reach it. A
 * caller that wants the stale entries therefore asks the ceiling for them by
 * its own type; a caller that only wants filtering never learns it exists.
 */
interface ViolationFilterStageInterface
{
    /**
     * Which stage this is — the identity a pipeline-order assertion reads.
     */
    public function stage(): ViolationFilterStage;

    /**
     * @param list<Violation> $violations
     */
    public function apply(array $violations): ViolationFilterStageResult;
}
