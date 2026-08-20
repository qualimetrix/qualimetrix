# 0026. Assigned Declaration Ordinal and the Named Limits of Key Stability

**Date:** 2026-08-20

**Status:** Accepted

**Supersedes:** [ADR 0021](0021-declaration-scoped-callable-identity-and-dependency-projections.md)
in one part — the `startFilePos` component of `DeclarationPath` and the sentence
"Adding a sibling declaration cannot alter an existing declaration key". Every
other decision in ADR 0021 stands.

**Related:** [ADR 0017](0017-baseline-ceiling.md) (the ceiling whose entries are
keyed by this identity)

**Plan:** [Durable declaration identity and entry-value compaction](../internal/plans/baseline-compaction/PLAN-IDENTITY.md)

## Context

ADR 0021 defined a declaration key as the logical `SymbolPath`, the
project-relative file, `startFilePos`, and an ordinal used only to break a
same-position collision. It then claimed the property that made the key worth
storing: *adding a sibling declaration cannot alter an existing declaration
key*.

**The property did not hold, and the reason was the byte offset itself.** A key
containing `startFilePos` depends on every character above the declaration, not
only on declarations: inserting a blank line, reflowing a docblock, or
lengthening a name in a sibling above rewrites the key of everything below it in
the file. Adding a sibling is one instance of that larger dependency, so the
claimed property was false for every named declaration, not merely at the
edges. The cost was paid by the baseline: an accepted entry whose declaration
sat below any edit lost its identity, and the same finding came back as a stale
entry plus a fresh, unaccepted one. A ratchet is only as durable as the identity
it is keyed by.

**The property also carried exemptions that were never named.** Unnamed
declarations — closures and anonymous classes — have no identity other than
their position in a sequence of their own kind. Their minted names say so
outright: `{closure#N}`, and, before this change, `{anonymous@<byte offset>}`.
No shape of key can make such a declaration survive the insertion of another
unnamed declaration of the same kind above it, because there is nothing else to
recognise it by. ADR 0021 stated the property with no exemptions at all, which
is precisely how a property stops holding without anyone noticing.

## Decision

### 1. Position is an input to identity, not its value

`DeclarationPath` stores an **assigned ordinal**: the rank of the declaration
among the declarations sharing its `DeclarationKey` in the same file — that is,
how many declarations of the same key were declared above it. The key becomes
`declaration:{logical}@{file}`, plus `#{n}` when `n` is non-zero.

`FileDeclarationIndex` is the single owner of that assigned ordinal: one index
per file per traversal path, filled by a dedicated registrar visitor that the
traversal owner installs unconditionally. Producers ask for an ordinal; none
computes one. The registrar's independence from the collector set, the enabled
rules, and the worker mode is what makes agreement structural rather than
disciplinary — before this change every producer counted for itself, and the
replicas agreed only as long as each was maintained correctly.

One number stays outside the index, deliberately: the `N` in `{closure#N}` is
part of the *name* an anonymous callable is given, minted by the lexical scope
that walks the file, not a rank attached to a finished key. The index numbers
keys; the lexical scope mints names.

`startFilePos` remains on the in-run collection payloads (`CallableWithMetrics`,
`ClassWithMetrics`) as a join key, and appears in no stored identity.

### 2. The stability property is restated with its exemptions named

A stored key has three inputs — the file, the logical name, and the ordinal — so
it moves only when one of those moves. Both kinds of rank are *counts*, so a rank
moves whenever the set of declarations counted ahead of it changes: a sibling
added, removed, or moved past it. Exactly three cases exist:

> - **A closure.** `{closure#N}` is the file's N-th anonymous callable in
>   **traversal order**. Any other closure or arrow function anywhere in the file
>   moves it, including one in an unrelated class. Traversal order is source
>   order almost everywhere, but not quite: `new class (…) { … }` is visited body
>   first, so a closure in that body is numbered ahead of a textually earlier
>   closure in the constructor arguments.
> - **An anonymous class.** `{anonymous#N}` is the file's N-th unnamed class-like
>   declaration, moved by any other unnamed class-like declaration in the file.
>   It reaches stored keys only through its members — the methods and property
>   hooks declared inside it, whose logical path carries the minted name; the
>   class itself holds no stored key. A closure or arrow function inside such a
>   class does not inherit the instability, because its own name is file-scoped,
>   and a code-smell or security finding inside it is attributed to the file
>   subject rather than to a declaration.
> - **A named declaration sharing its logical identity with another in the same
>   file.** Its rank counts only declarations of that same identity, and — unlike
>   the two above — strictly by file position.

Everything else is stable: a blank line, a reformat, a renamed or newly added
sibling of a different identity are all invisible to identity now.

The first two cases are unnamed declarations, whose identity is ordinal by
nature — their minted name *is* a position in a sequence, and there is nothing
else to recognise them by. The reach of that ordinality is stated exactly,
because "an anonymous class is unstable" both overstates and understates it: the
class holds no key, while a method with a perfectly good name of its own does,
and inherits the class's instability through it.

**Renumbering does something worse than making an entry stale, and it is written
down here rather than left to be discovered.** A vacated rank is taken by its
neighbour immediately, so an accepted ceiling keyed `#N` does not go stale when
the declaration it was written for becomes `#N+1` — it silently rebinds to
whatever holds `#N` now, and no diagnostic fires at all. A byte offset made this
practically impossible, because a vacated offset was never reused; a rank makes
it systematic. This is the cost side of the trade, and it falls on exactly the
three cases above.

The exemptions are written into the decision rather than left to the reader,
because an unnamed exemption is exactly the mechanism by which the previous
formulation failed silently. A property whose limits are named can be checked;
one whose limits are implied cannot.

### 3. Two permanent guards, on every file

The residual failure mode is a producer that supplies a number instead of asking
the index. It has two manifestations, visible at different points, so there are
two assertions — both live on every analysed file, not on a fixture:

- **Collision — two declarations, one number.** `FileProcessor` merges callable
  and class records by canonical key; the file positions carried alongside those
  records must agree under one key, and a disagreement is a `LogicException`.
  `DerivedCollectorRunner` carries the same check, because derived metrics are
  computed on the merged record and would otherwise reach a formula as one
  declaration.
- **Splitting — one declaration, two numbers.** `DeclarationControlBindings`
  already groups elements by position and requires the identities at one
  position to agree; the ordinal is now part of the compared identity.

What the guards do not cover is stated rather than implied: wire-subject
components (code-smell, security) do not pass through `FileProcessor`, the
`graph:export` traversal does not either, and a producer that resolves a
different namespace builds a different identity rather than a different number —
a pre-existing divergence this change neither introduces nor repairs.

### 4. The remaining cost is recorded, not hidden

Removing the ordinality of unnamed declarations — and with it the silent
rebinding described in item 2 — would require a third change of key shape and
another full ratchet regeneration. This ADR does not schedule that work; it
records the price so the next decision is taken with it in view.

## Consequences

- **Baseline files move from version 12 to version 13, with no converter.** A
  version 12 key stores a position from which the declaration it meant cannot be
  recovered, so no converter can exist. The only route for an existing baseline
  is regeneration with `bin/qmx baseline:generate <baseline> <paths...> --force`
  and a reviewed diff of what was accepted.
- **A regenerated ratchet cannot be diffed line by line against its
  predecessor.** Key shape changed, so the check that regeneration weakened
  nothing is a comparison of the multiset of (normalised identity, accepted
  magnitude) pairs, not of file text.
- **`architecture.layer-violation` occurrence keys reset irreversibly**, since
  `LayerViolationFinding` puts a declaration canonical key into its evidence.
  The channel is named exactly rather than as `architecture.*`, which under this
  project's own selector grammar would also claim
  `architecture.circular-dependency`. This was checked against all eight
  occurrence producers one by one: the other seven build evidence from logical
  `SymbolPath`s or from content. In
  particular `circular-dependency` derives its key from the canonical logical
  paths of the cycle members, which this change does not touch, and keeps its
  occurrence keys.
- **GitLab Code Quality and SARIF fingerprints reset** — the second reset in
  this release, the first having come with the entry-value compaction.
  Previously seen GitLab findings show once as new, and closed or dismissed
  GitHub code scanning alerts reappear as open.
- **The name minted for an anonymous class changed** from
  `{anonymous@<byte offset>}` to `{anonymous#<rank>}`, and that name reaches
  violation subject keys.
- Numbering is one more thing a traversal owner must install. In exchange, the
  question "which declaration is this?" has exactly one answer per file instead
  of one per producer.
