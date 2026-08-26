# 0034. The Level Is a Coordinate of a Symbol

**Date:** 2026-08-26
**Status:** Accepted
**Refines:** [ADR 0022](0022-capability-oriented-modular-monolith.md) on its
supporting boundary "`Core` contains only neutral primitives with no natural
capability owner. Import count alone is not evidence of neutrality." The
boundary stands; this ADR records how it was applied to a type whose fan-in
grew, and why the answer is not the one fan-in alone would give.

## Context

`SymbolLevel` — `callable`, `class`, `file`, `namespace`, `project` — and the
single projection from a declaration kind onto a level lived in
`Analysis\Evidence\Measurement\Contract`, because a level names a step of the
aggregation tree and that tree is Measurement's model.

Three earlier steps of the rule-vocabulary work moved what a level *is*:

- ADR 0024 took the level out of channel names. A level is now written beside
  the name (`coupling.cbo:namespace`) in inline directives, in rule selectors
  and in namespace-channel exclusion keys.
- The rule layer's own `RuleLevel` was deleted; a channel declares the levels it
  reports at (`ChannelDeclaration::$levels`) using this one vocabulary.
- `MetricRepositoryInterface::all()` takes a level rather than a declaration
  kind, so every caller that used to ask `SymbolType` for a bucket asks this
  enum instead.

The result was a vocabulary read by Finding, Policy\Inline, Reporting,
Infrastructure and twelve evidence capabilities, and owned by one of them. Two
ratchet entries on `Measurement\Contract` and `Measurement` were the visible
symptom of one subject split across two owners.

Measurement's README argued *against* moving it, quoting ADR 0022's boundary
directly. That argument was right about the boundary and wrong about the
premise: it treated fan-in as the reason to move, and fan-in is exactly what
ADR 0022 refuses as evidence.

## Decision

`SymbolLevel` and `SymbolLevelProjection` live in `Core\Symbol`, beside
`SymbolType`, `SymbolPath`, `SymbolInfo` and `MetricSubject`.

The question that settles it is not "how many importers?" but **"is the level a
property of the symbol, or a property of the finding's address?"** — and the
answer is read off the code, not chosen by taste:

- **Finding does not store a level.** `Finding` has no level field;
  `Finding::level()` computes it — `SymbolLevelProjection::ofDeclaration($this
  ->subject->toSymbolPath()->getType())`. There is no second place where a
  level is written, which is why emission cannot disagree with what is
  published. Contrast `ChannelName`, an identity Finding *invents* and
  therefore owns.
- **Measurement owns the traversal, not the vocabulary.** `all()`, the
  aggregators and the indexes walk the tree; the names of its steps are read
  there like everywhere else.
- **`SymbolType` and `SymbolLevel` are one subject cut in two.** "What kind of
  declaration is this?" and "what level does it measure at?" are different
  questions about the same thing — how this product classifies a symbol — and
  the projection between them was living apart from one of its own operands.

So the type has no natural *capability* owner, which is the condition ADR 0022
states for `Core`. It is not neutral because many things import it; it is
neutral because both readers that could own it — the one that walks the tree
and the one that addresses findings — only read it.

`Core\Symbol` keeps its namespace-wide `coupling.cbo` and `coupling.class-rank`
exclusions, and both point thresholds on `SymbolLevel` are kept with it. Such
a threshold no longer decides the published report or the exit code; it decides
whether the hub is reported at all, hence whether it appears under
`--show-suppressed` and in the suppression count. Whether to narrow the
exclusion to point thresholds and make this namespace measurable again is an
open owner question, with its cost measured in
`docs/internal/plans/rule-vocabulary/PLAN.md`.

## Consequences

- Three owners stop depending on `Analysis\Evidence\Measurement` altogether —
  CircularDependency, Duplication and Policy\Architecture — because the level
  was their only reason to. The generated qmx projection loses those three
  allow edges.
- One ratchet entry retires (`coupling.distance` on `ns:…Evidence\Measurement`)
  and one tightens (`coupling.cbo` on `ns:…Measurement\Contract`, 50 → 38).
  The move removed twelve inbound edges from that namespace, not all of them:
  the level vocabulary was one contributor to its coupling, not the only one.
- The published PHP surface changes: code importing
  `Qualimetrix\Analysis\Evidence\Measurement\Contract\SymbolLevel` or its
  projection imports `Qualimetrix\Core\Symbol\…` instead. Nothing observable
  through the CLI, the eleven output formats, configuration or a baseline
  entry changes — proven by the finding-equivalence gate running GREEN under
  empty rename maps.
- The precedent to reuse: a type is placed by which module *writes* the fact,
  not by which modules read it. A reader that computes a value from something
  it was handed is not its owner.
