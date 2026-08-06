# 0016. Subject Cohesion As The Directory Layout Rule

**Date:** 2026-08-04
**Status:** Accepted

## Context

[ADR 0010](0010-architecture-vertical-slice.md) introduced the first vertical
slice and [ADR 0012](0012-hybrid-architectural-direction.md) generalised it into
a hybrid direction: substantial domain features get their own directory, thin
metric/rule features stay layered, cross-cutting infrastructure stays layered,
adapters always live in `Infrastructure/`.

Both ADRs answered *what goes where* with a checklist — "cross-layer-consuming
rule AND independent-lifecycle adapter", plus an "analogous complexity" escape
hatch that 0012 itself calls "intentionally judgmental". Neither stated the
**underlying criterion** the checklist is a proxy for. Two consequences
followed:

- Every borderline feature re-litigated the decision by counting criteria rather
  than by applying a principle. The Baseline v7 planning round is the most
  recent instance: the checklist scored one criterion met, one half-met, and the
  escape hatch closed, which decided nothing.
- The checklist gives no way to notice when an *existing* directory violates the
  rule, because it only fires when a new feature is introduced.

This ADR states the criterion. It does not reverse 0010 or 0012 — it explains
what they were approximating, and makes the same decisions derivable.

## Decision

### The rule

**A directory is a subject, not a role.** Its name should answer "what is this
about?" with a noun phrase that does not name a technical role, a base class, or
an interface.

Cohesion by *subject* and cohesion by *role* are different properties that share
a word. `src/Reporting/` is cohesive by subject — every file is about turning
results into output. `src/Rules/` is cohesive only by role: cyclomatic
complexity and SQL-injection detection share an interface and nothing else.

### Three tests

1. **Naming.** Complete "this directory is about ___". If the only honest
   completion is "the classes implementing X" or "all the Y", it is a role
   bucket, not a subject.

2. **Co-change.** A change to one subject should touch one directory, plus its
   adapters. If a routine change routinely spans N directories, the subject is
   scattered. The converse also holds: if a directory's files never change
   together, it is not a subject. This test is empirical and checkable against
   git history.

3. **Duplication.** Imagine the tree fully decomposed by subject. Which
   directories would have to be *copied into every subject*? Those are the
   legitimate cross-cutting directories — primitives, output formatting,
   dependency injection, orchestration — and for them the role genuinely is the
   subject. Anything that would instead move wholesale into exactly one subject
   is a role bucket and should be dissolved.

Test 3 is the discriminator the earlier ADRs lacked. It explains without
special-casing why `Core/`, `Reporting/`, `Infrastructure/`, and `Analysis/`
stay layered while `Architecture/` did not.

### Contracts shared by several subjects

A contract produced or consumed by many subjects belongs in the shared
primitives directory — and the justification must be **subject-based** ("this is
a cross-cutting primitive"), not **constraint-based** ("nothing else may be
depended upon").

When the dependency constraint and the subject point at the same location, the
layout is right. When they disagree — when a type lands somewhere only because
the layer rules forbid every honest alternative — **the layout is wrong, not the
constraint.** Treat the disagreement as a signal to reconsider the decomposition.

Worked example (Baseline v7): the debt-observation contract is constructed by
every rule and consumed by both Baseline and Reporting. The constraint
(`rules: [core]`) permits only `Core`. The subject test agrees independently:
"measured debt of a symbol" is a cross-cutting primitive of the same kind as
`SymbolPath` and `Violation`. Both point at `Core/Observation`, so the placement
is sound — and Baseline needs no vertical slice, because once the contract is in
Core what remains is exactly the existing `src/Baseline/` content.

> **Note:** the ratchet-baseline plan's v10 revision retired the
> debt-observation contract itself (`DebtObservation`, `AxisObservation`,
> `ObservationKind`, `ContractReference`, `OccurrenceKey`) as dead code — only
> `WorseDirection` survived, still under `Core/Observation`. The example
> above still illustrates the subject-vs-constraint reasoning that governed
> the placement decision; it is not a claim about what `Core/Observation`
> currently contains.

### Adapters

Adapters — CLI commands, HTTP endpoints, message handlers, shell hooks — belong
to the delivery mechanism, not to the subject they serve. They live in
`Infrastructure/` regardless. This restates the adapter-exclusion principle of
ADR 0010 and is unchanged.

Corollary: "this feature has many adapters" is **not** an argument for a vertical
slice, because the adapters do not move into it.

### Recursion and enforcement

The rule applies recursively: a subject that grows is split into sub-subjects,
and each is subject to the same three tests.

Boundaries at **every** level are enforced mechanically, via the layer topology
in `qmx.yaml`, never by convention alone. A sub-subject whose edges are
unchecked is a subject only by intention, and intentions erode.

Internal freedom is a **temporary grant**, not a property of slices. It is
appropriate while a subject is being migrated or built out, because pinning
boundaries that are still moving creates churn for no benefit. Every grant must
name the condition that closes it.

This resolves an inconsistency in the current tree. `analysis` has its internals
expanded into enforced sub-layers; `architecture` is a single layer, because ADR
0010 granted it internal freedom during the pilot migration. That migration has
landed, so the grant has expired and Architecture's internals are to be expanded
into enforced sub-layers. Verified when this ADR was written, its internal
dependencies already form a clean DAG — `Domain` depends on nothing,
`Configuration → Domain`, `Processing → Domain`, `Rules → Domain + Processing` —
so enforcement pins an existing shape rather than forcing a refactor. ADR 0010
Part 5 is superseded on this point.

### Anti-patterns

- `Helpers/`, `Utils/`, `Common/`, `Shared/` — the absence of a subject.
- A role directory into which every feature deposits one file.
- The mirror image: one subject smeared across several role directories.

## Consequences

- Borderline layout questions are decided by applying the tests, not by counting
  checklist items. ADR 0010's two criteria remain valid as a fast path for
  rule-bearing features; when they disagree with the tests here, these tests win.
- `src/Rules/` and `src/Metrics/` fail test 1 and are known, accepted
  violations. ADR 0012 declined to migrate them on cost grounds, and that
  decision stands — but it is now recorded as a deliberate exception to a stated
  rule rather than as an unexamined default. Should either directory be split by
  subject in the future, this ADR is the justification.
- Test 2 is measurable. This project analyses code for a living; a co-change
  metric over git history is a plausible future feature, and it would make the
  rule enforceable rather than advisory.
- The rule is portable and is carried in the author's cross-project agent
  instructions; this ADR is its reference statement.
