# 0071. An Anonymous Class's Declaration Edge Is Flagged, Not Retyped or Dropped

**Date:** 2026-09-20
**Status:** Accepted

## Context

An anonymous class has no declaration identity of its own — nothing a graph
consumer can address as "the anonymous class's parent" or "the anonymous
class's ancestry." When `DependencyVisitor` walks `new class extends L1 {}`,
`ClassLikeHandler::handleClass()` still records an `extends` edge, and the
only declaration it can attach that edge to is the enclosing named class,
because that is what `$currentContext` holds at the point the header is
visited.

Four channels produce the same misattribution: the anonymous class's
`extends`, its `implements`, an attribute on its header, and a `use T;` in its
body. Declaration readers took each of these edges at face value:
`DitGlobalCollector` counted the anonymous class's parent as the enclosing
class's own parent, `NocCollector` counted the enclosing class as a child of
that parent, and `ClassContextFactory` matched the enclosing class into a
layer under `extends:`, `implements:` or `attributes:` — including
transitively, since layer membership walks `extendsMap` as a BFS closure.
Measured on isolated fixtures: `Host` reported `design.dit = 2` for a parent
it does not have; `L1` reported `design.noc = 1` for a child it does not have;
`debug:layer-assignment` matched `Host` into a layer declared
`extends: ['L1']`. The defect is live in this repository:
`LoggerFactory` reported `design.dit = 1` while extending nothing itself — the
depth belonged to `new class ($loggers) extends AbstractLogger {}` nested
inside it.

The same edge is not a defect for every reader. `new class extends L1 {}`
produces no `New_` edge: `InstantiationHandler` only fires when `New_->class`
is a `Name` node, and here it is a `Class_` node. The `extends` edge recorded
against the enclosing class is therefore the **only** evidence in the whole
graph that the enclosing class depends on `L1` at all — `coupling.ce`/`cbo`,
`L1`'s `coupling.ca`, ClassRank, cycle detection, the architecture
**violation** check (as opposed to membership), and `graph:export` all need it
to keep meaning what they mean today.

## Decision

Add one defaulted, readonly field to `Dependency`:
`describesNestedAnonymousClass` (default `false`). `DependencyContext` carries
matching ambient state, set immediately before a call that will produce one of
the four edges above and cleared right after, so exactly one production
construction site (`DependencyContext::addDependency()`) ever sets it. The
flag is `true` only for an anonymous class's own header edges
(`extends`/`implements`/attribute) and for a `trait_use` from its body; every
other edge, including usages from inside the same anonymous body (`new`,
static calls, type hints), stays `false`.

Readers split along the line the defect already drew: **declaration readers**
(`DitGlobalCollector`, `NocCollector`, `ClassContextFactory`'s
`extendsMap`/`implementsMap`/`attributesMap`) skip a flagged edge.
**Dependency readers** — coupling, ClassRank, cycle detection, the
architecture violation check, `DependencyGraphBuilder`, `JsonGraphExporter`,
`relations:` filtering — read the edge exactly as before. Nothing about the
edge's `DependencyType`, its `description()`, its `isStrongCoupling()`
classification, or its `relations:` vocabulary membership changes.

### Rejected: drop the edge

The edge is the sole witness that the enclosing class depends on the
anonymous class's parent/interface/attribute target at all (see Context).
Dropping it would silently zero `coupling.ce`/`cbo` for every class with such
a nested anonymous class, blind the architecture rule to a real violation, and
remove the edge from cycle detection — trading a membership bug for a
coupling bug.

### Rejected: retype the edge to `New_`

An anonymous class *is* being instantiated at that point, so relabelling its
header edges as `New_` looked like the direct fix. Rejected on four grounds,
each confirmed against code, not assumed:

- **It deletes the builtin-parent edge.**
  `DependencyGraphBuilder::retainGraphDependencies()` keeps an edge to a PHP
  builtin class only when its type is `Extends`; every other type is filtered
  against `isPhpBuiltinClass()`. `new class extends \stdClass {}` would lose
  its edge entirely, not just its declaration reading — measured today at
  `coupling.ce = 1` on exactly that fixture.
- **It publishes a false description for `implements`.**
  `DependencyType::New_->description()` returns `'instantiates'`. An
  `implements` target is not instantiated; the anonymous class is.
- **It weakens coupling that is still structural.**
  `DependencyType::isStrongCoupling()` returns `true` for `Extends`,
  `Implements`, and `TraitUse`, and `false` for `New_`. An anonymous class's
  `extends`/`implements`/`trait_use` is exactly as strong a coupling as a
  named class's — retyping would understate it.
- **It silently narrows `relations: [inheritance]`.** The `inheritance` alias
  expands to `extends`, `implements`, `trait_use`. `new class extends Base {}`
  is subclassing, and deptrac — the tool `architecture.layer-violation`
  replaces (ADR 0014) — attributes it to the enclosing class as such. A
  `relations:` policy meant to whitelist inheritance-only dependencies would
  stop seeing it, with no CHANGELOG line to explain why a previously-caught
  edge disappeared. That silent narrowing, on a `Breaking` axis bought for no
  stated benefit, was the deciding point against this option.

### Rejected: a new `DependencyType` enum case (or cases)

Fixes `extends` and `implements` cleanly, but attributes already have their
own type and `trait_use` arrives through a different code path
(`dispatchInCurrentContext()` rather than `ClassLikeHandler`), so this option
would still need a second mechanism for those two channels. It buys a partial
cure at the price of splitting the `inheritance` alias's meaning and touching
every `match` expression over `DependencyType`, for no benefit the flag does
not already provide uniformly across all four channels.

### Open sub-question, decided by the owner: keep the flag internal

Whether `describesNestedAnonymousClass` should be visible outside the
extraction/declaration-reader boundary — in `LayerViolationFinding` or
`JsonGraphExporter` — was left open pending owner review. Decided: **the flag
stays internal.** `graph:export` publishes an edge's existence and
`DependencyType`, and both are correct and unchanged by this decision; adding
"does this edge count toward layer membership" to that export would be a new
published field serving exactly one hypothetical consumer, not a correction of
something the export gets wrong today.

## Mechanism

The flag only needed to reach the four channels that mislabel a *declaration*.
It is tempting to credit `consumeAnonymousClass()` with keeping the anonymous
class's *body* attributed to the enclosing class as well, but that is not what
happens, and this record exists partly to stop that claim from resurfacing:

- `DependencyVisitor::enterNamedClassLike()` guards on `$node->name !== null`
  and returns `false` for an anonymous class, so it never replaces
  `$currentContext` with a context for the anonymous class.
- `consumeAnonymousClass()` fires next, but only for the `Class_` node itself
  — the header. It brackets one call to `ClassLikeHandler::handle()` with
  `startDescribingNestedAnonymousClass()`/`stopDescribingNestedAnonymousClass()`
  and increments `$anonymousClassDepth`; it does not touch `$currentContext`.
- Every other node inside the anonymous class's body — a `use T;`, a `new`, a
  method call — is neither a namespace/import node nor a named class-like
  node nor the anonymous `Class_` node itself, so it falls through to
  `dispatchInCurrentContext()`, which dispatches against whatever
  `$currentContext` currently is. Because nothing above ever changed it, that
  is still the enclosing named class's context.
- `leaveNode()` only clears `$currentContext` for a `ClassLike` whose
  `$node->name !== null`; for an anonymous one it just decrements
  `$anonymousClassDepth`.

So the body's attribution to the enclosing class is a consequence of
`enterNamedClassLike()` never firing and `leaveNode()` never clearing the
context for a nameless node — not of anything `consumeAnonymousClass()` does.
`consumeAnonymousClass()`'s sole job is routing the anonymous class's own
*header* into `ClassLikeHandler` with the flag raised for that one call; the
depth counter it raises exists so `dispatchInCurrentContext()` can tell a
body-level `use T;` (flagged) apart from a body-level `new`/static
call/type hint (a usage of the enclosing class, left unflagged) — and so a
`use T;` in an anonymous class nested two levels deep is still flagged,
because the counter tracks depth rather than immediacy.

## Consequences

`design.dit`, `design.noc`, and their namespace/project aggregates
(`.avg`/`.max`/`.p95`) no longer count an anonymous class's declaration
against the class that encloses it. Layer membership under `extends:`,
`implements:`, and `attributes:` — including the transitive `extends:` case —
stops matching the enclosing class on that basis. In this repository,
`LoggerFactory design.dit` moves 1 → 0 and three `dit.avg` aggregate values
move with it; no `qmx.yaml` layer here declares a graph-based criterion, so no
layer assignment shifts.

Coupling (`ce`/`cbo`/`ca`), ClassRank, cycle detection, the architecture
*violation* check, `relations:` filtering, and `graph:export` are unaffected
— the dependency is still recorded and still read exactly as before; only its
misreading as a declaration fact about the enclosing class is corrected. This
is why the change is `Fixed`, not `Breaking`: no published contract, the
`relations:` vocabulary, or baseline edge identity moved.

`Dependency` crosses the worker boundary in parallel mode.
`FileProcessingResultWireFormatTest` now pins the five-argument shape so a
parallel run and a `--workers=0` run agree on the flag, not just on the four
fields that existed before it.

**Known residue, not addressed here.** The `attributes:` membership criterion
already does not distinguish where an attribute is written. `FunctionLikeHandler`
and `PropertyHandler` record an `Attribute` edge with the *enclosing class* as
source whenever a method, parameter, or property carries one — so a class
whose only `#[Mark]` sits on a method is matched by a layer declared
`attributes: ['Mark']`, the same shape of defect this ADR fixes for anonymous
classes, but wider: it reaches every named class with an attributed member,
not only classes with a nested anonymous class. This decision does not touch
it; it is reported here as a defect the same fork could plausibly cure next,
not folded into scope now.

The gap also opens *inside* the very shape this decision cures, not only
outside it. `new #[Mark] class {}` is fixed — the flag now covers the
anonymous class's own header — but `new class { #[Mark] public function f(): void {} }`
is not: the attribute sits on the anonymous class's *member*, so it reaches
`FunctionLikeHandler`/`PropertyHandler` and is dispatched through
`dispatchInCurrentContext()` like any other body-level edge, never bracketed
by `startDescribingNestedAnonymousClass()`. Both forms attribute `#[Mark]` to
the same enclosing named class; only the header form is corrected here. The
asymmetry is not a gap this ADR introduces — it is the wider `attributes:`
defect above, observed at the point where it happens to overlap the shape
this ADR does fix.

## Operational commitment: retire the gate's declared rows after merge

P4 of the implementation plan declared, rather than retired, the finding-gate
rows this change moves — a declared row is only valid against **one**
reference, and while this change is unmerged that reference is still the
commit it starts from (`ad878c13`). Measured against the tracked files at the
time of this decision: 13 data rows in `finding-gate/declared-delta.tsv`,
each paired with one file under `finding-gate/declared-delta/*.diff` (13
files), and 8 data rows in `finding-gate/declared-field-moves.tsv`.

Once any newer commit already carries this change, those rows describe a diff
that no longer exists between "before" and "after" — they go stale and start
producing `delta-stale`, exactly the shape of tail `#92` left behind and
`#112` had to clear. Retiring them is therefore a named follow-up for the
commit immediately after this change merges, not a step inside this change
itself (declaring and retiring against the same reference in the same PR
would be self-contradictory — the very next run against that reference would
see an undeclared diff). The plan document that stated this commitment is
deleted once the campaign closes; this paragraph is its durable home.
