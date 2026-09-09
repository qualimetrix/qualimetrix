# 50. The Configuration Refusal Carrier: Placement and Contract

**Date:** 2026-09-09
**Status:** Accepted

## Context

Before this round, a configuration refusal was five unrelated exception
classes — `ConfigLoadException`, `ArchitectureConfigurationException`,
`ArchitecturePreparationException`, `BaselineLoadException`,
`ComputedMetricConfigurationException` — thrown by four different owners
(`Analysis.Configuration`, `Analysis.Policy.Architecture`,
`Analysis.Policy.Baseline`, `Analysis.Evidence.ComputedMetrics`) and caught by
a growing, hand-maintained `catch (A|B|C|D)` clause in
`src/Infrastructure/Console/Command/CheckCommand.php` and its siblings. Each
class carried different fields, so `src/Infrastructure/Console/ConfigurationFailure.php`
existed only to re-frame whichever one had been caught into one printable
sentence — the same routing-by-taxonomy the round set out to remove.

The round needed one carrier type all four owners could throw and every
console command could catch without branching on which owner threw it, plus
enough structure (the refused key's position, the document or input it came
from, its source) for a machine-readable envelope to report the same refusal
that a human-readable message describes in prose.

## Decision

**The carrier lives in `Analysis.Configuration`, not `Core`.** Its fields —
key position, the spelling written, the spellings accepted at that position,
the configuration source (`defaults`, `composer.json`, a file's base name,
`preset:<names>`, `cli`) — are the vocabulary `Analysis.Configuration`
already owns: `ConfigurationLayer::$source` is the same five-source
enumeration the carrier's `ConfigurationOrigin` draws from. The argument for
`Core` — "many owners throw it" — is the one CLAUDE.md §1 and ADR 0022
reject by name: many imports do not make a type neutral. Moving it to `Core`
would mean `Core` adopting the concept "configuration source", and every new
configuration layer would then be a `Core` change. `Core` has no dependency
cycle argument for owning it: `Analysis.Configuration` imports nothing from
`Analysis.Policy`, `Analysis.Finding`, `Analysis.Evidence`, `Infrastructure`
or `Reporting`, so the four other throwing owners can depend inward on it
without a cycle.

**One carrier type, not a marker interface over the five retired classes.**
A marker interface would have been the smaller diff, but it leaves every
catching command branching on concrete type — the same taxonomy routing
moved from the command layer into an interface. `ConfigurationRefusal` is
instead one `final class` with three named static factories —
`at()` (a position inside a document: an unknown key, a malformed value),
`aboutDocument()` (the document itself: unreadable, unparsed, wrong envelope
version), and `aboutInput()` (a value with no position in any document: a CLI
argument, a selector, a path) — so a catching command reads one type and asks
it for `origin()`, `position()` (`null` for the last two forms) and
`summary()`, never for its concrete throwing class.

**The contract directory is `Contract/Refusal/`, not `Contract/Exception/`.**
`Contract/Exception/` names a role ("exceptions live here"), not a subject;
`Contract/Refusal/` names what the four types it holds — `ConfigurationRefusal`,
`ConfigurationOrigin`, `ConfigurationSource`, `RefusedPosition` — are jointly
about. `Contract/Exception/` is deleted along with `ConfigLoadException`, the
one class it held.

**`summary()` carries no framing.** It is one sentence, with no `<error>`
markup, no "Configuration error:" prefix and no exit code baked in.
Presentation — the prefix, the markup, the stream, the machine-readable
envelope — belongs to `Infrastructure.Console`'s presenter, which frames
every refusal identically regardless of which of the four owners threw it.
A carrier that pre-framed its own message would make that presenter's
uniformity accidental instead of enforced.

## Consequences

- `Analysis.Policy.Architecture`, `Analysis.Policy.Baseline` and
  `Analysis.Evidence.ComputedMetrics` gain a new coarse dependency edge onto
  `Analysis.Configuration` for the carrier's contract types. This is a new
  edge each of those owners did not have before (verified against
  `docs/internal/generated/modular-architecture/production-cross-owner-imports.tsv`
  before this round), not a widening of an existing one.
- The five retired exception classes are deleted, not deprecated: the
  project's backward-compatibility policy (`AGENTS.md`, "Backward
  Compatibility Policy") treats a cleaner internal contract as worth more
  than a compatibility shim nobody outside the project depends on.
- A future second "refusal with no configuration source" concern — an input
  refused for a reason that names neither a key nor a configuration source —
  is a distinct subject with its own owner, not a reason to move this
  carrier to `Core`. The condition for revisiting this decision is the
  arrival of that concern, not a rising import count on this one.
