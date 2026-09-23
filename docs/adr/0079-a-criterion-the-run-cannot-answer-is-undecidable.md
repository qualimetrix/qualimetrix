# 0079. A Criterion the Run Cannot Answer Is Undecidable, Not a Non-Match

**Date:** 2026-09-23
**Status:** Accepted

## Context

Layer membership ([ADR 0006](0006-architecture-rules-declaration-order.md),
[ADR 0014](0014-deptrac-retirement.md)) is decided by five criterion kinds:
`patterns`, `suffix`, `attributes`, `implements` and `extends`. Three of them —
`attributes`, `implements`, `extends` — are answered from facts the run
collected: the attribute set of a declaration and the transitive closure of its
`extends`/`implements` edges. `patterns` and `suffix` read the name alone and
need nothing collected.

A collected fact can be missing, and the missing case was spelled as the
negative one. Two shapes were measured on a fixture stand:

- a criterion naming an **ancestor whose intermediate link was not analysed**.
  The closure walk stops at the unanalysed link, the ancestor set comes back
  without the named type, and the criterion answers a confident "no" about a
  class that does extend it.
- a **subject the run never analysed at all** — the far end of a dependency
  edge that points outside `paths:`. It carries no attributes, no interfaces
  and no parents, so *every* graph-backed criterion answered "no" about it.

The second shape was in no review finding and in no brief; it was found by
reproducing the first. The case that is *not* affected decides where the cure
belongs: a criterion naming the class's own **direct** parent still matches even
when that parent is vendor code, because the edge is recorded from the analysed
child. Only a link further up the chain, or a subject with no analysed
declaration of its own, is missing.

A wrong "no" here is not a cosmetic defect. A class that belongs to a layer and
is told it does not gets judged by another layer's allow-list, or by none at
all, and the report says nothing about the doubt: the reader cannot tell "no
layer claims this class" from "no layer could be asked".

Template expansion made the same question twice. `TupleExtractor` carried its
own copy of the non-pattern criterion predicate, so observation — which decides
whether a concrete layer exists at all — and runtime matching could, and did,
disagree while each looked right on its own.

## Decision

**A criterion has three outcomes, and the third one reaches a reader.**
`CriterionOutcome` is `Matches`, `DoesNotMatch` or `Undecidable`, and
`CriteriaEvaluation::outcome()` is the single place where a `MatchMode` combines
per-kind outcomes into one verdict. The combination is Kleene:

- `match: any` is Kleene OR. One firing kind decides regardless of what the
  undecidable ones would have said; only a run that decided *every* declared
  kind may answer `DoesNotMatch`.
- `match: all` is Kleene AND, mirrored. One kind that definitively did not fire
  decides; every declared kind must fire before the spec may answer `Matches`.

Positive membership, the `exclude:` clause and template observation all ask that
one method, so a mode rule cannot hold on one side of the slice and not the
other. `LayerCriteriaMatcher::evaluate()` and `LayerDefinition::excludeOutcome()`
are the shared entry points; the duplicate predicate in `TupleExtractor` is gone.

Four sub-decisions carry the weight, and each had a cheaper alternative.

**An undecidable `exclude:` removes membership.** When the positive criteria
caught a class and the clause that would remove it cannot be answered, whether
this layer owns the class is unknown — not settled in the layer's favour. The
result is `MembershipResult::undecided()`, a non-member the run never actually
established, rather than a match. Treating the unanswerable exclude as "did not
fire" would publish an ownership claim the run has no basis for, and every
allow-list judgement downstream would inherit it.

**An undecidable layer declared before a matching one does not withdraw the
match.** A strictly three-valued reading of declaration order would make the
assignment unknown. It was rejected: withdrawing the match leaves the class in
no layer, no allow-list judges its edges, and real violations stop being
reported. That trades a wrong answer for a missing one, which is the worse of
the two. The match stands and the doubt is published beside it — `LayerRegistry`
exposes `undecidedLayers()` as a third exit of the same cached walk, and
`architecture.coverage-gap` names it.

**Template observation falls toward existence.** A class whose non-pattern
criterion or whose substituted `exclude:` clause cannot be decided still
contributes its tuple, so the concrete layer is created. Refusing the tuple
would delete the layer, runtime would never evaluate any class against it, and
the doubt would come out as a plain non-match with nothing left to report.
`architecture.unreachable-layer` may name the layer that results — noisy rather
than silent is the failure direction chosen here.

**A template declaring a non-pattern criterion under `match: any` is refused at
configuration time.** Only `patterns` carry capture variables, so `suffix`,
`attributes`, `implements` and `extends` are copied into every expanded layer
verbatim. Under `match: all` that is harmless: the criterion narrows each
instance inside the scope its own substituted pattern already fixes. Under
`match: any` it is OR-ed with that pattern, so every instance carries the same
global net — one clause reading "or anything named `*Repository`" makes every
sibling instance claim every repository in the codebase, and the first instance
in expansion order (binding-value alphabetical, not anything the author wrote)
wins it.

The alternative was to scope such a criterion to the instance's own pattern.
It was rejected by arithmetic, not by taste: `gate ∧ (gate ∨ suffix) = gate`,
so the criterion becomes inert. That trades a wrong answer for one that does
nothing while still looking like it does something. `match: all` expresses the
narrowing an author almost certainly meant, and a static layer expresses the
global net if that is really what was wanted.

## Consequences

- `architecture.coverage-gap` distinguishes two gaps that used to look
  identical: a symbol in nobody's layer because the run answered every
  criterion — a hole the author closes by writing a layer — and a symbol in
  nobody's layer because its inheritance chain left the analysed set, which no
  edit to the configuration can close. The message says which, so its text
  changed for the second case.
- Membership is now a function of what the run analysed. Widening `paths:`
  can turn an undecidable layer into a decided one, in either direction.
  Vendor ancestry reached through a *direct* parent is unchanged, which is why
  no existing configuration that names direct parents moves.
- Four configurations that were accepted now refuse: a template with a
  non-pattern criterion under `match: any`, `relations:` written without a
  value, and `coverage-gap: warn|error` declared with no layers. Refusing at
  load time is the project's standing preference over a silent no-op
  ([ADR 0061](0061-configuration-miss-and-refusal-semantics.md)).
- `MembershipResult` grows two non-membership variants — `excluded()` and
  `undecided()` — beside the plain non-match. Every consumer reading the
  `matched` flag sees exactly the two variants it always saw; the distinction
  exists only so `architecture.unmatched-exclude` and
  `architecture.coverage-gap` can answer their own questions.
- Template observation is deliberately narrower than runtime matching in one
  shape: a non-capturing pattern is an AND-filter during observation, while on
  the expanded layer both pattern spellings sit in the single `patterns` kind
  and are OR-ed. That asymmetry is recorded rather than removed, because
  closing it would widen which concrete layers exist.
- `Qualimetrix\Analysis\Policy\Architecture\Layer\AnalysedDeclarations` carries
  the "did this run read this FQN's own declaration?" question. Its `unknown()`
  state — a caller that never supplied the set — answers yes to everything, so
  a registry built without it decides exactly what it decided before. Folding
  that state into an empty set would make every criterion undecidable for every
  such caller.
