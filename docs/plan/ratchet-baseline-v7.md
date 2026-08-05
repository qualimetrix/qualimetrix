# Ratchet Baseline v7 Plan

**Status:** revision 8.1 — the §17 inversion applied, and review round 8 folded in
**Date:** 2026-08-05

> **8.0 replaces inference with measurement.** Up to 7.9 a repair was inferred
> from the *absence* of a finding, guarded by a growing list of mechanisms that
> could also explain that absence. 8.0 re-reads the axes an entry captured,
> ahead of thresholds, gates, cutoffs and filters, and decides from the value.
> §17 records the decision and is kept as its rationale; §0.10 records what the
> rewrite changed and §0.11 what reviewing it changed. Round 8 found one
> CRITICAL and thirteen HIGH in the rewrite; all are folded in.
**Target release:** TBD
**Review status:** Eight rounds complete. Round 8 examined the 8.0 rewrite and
its corrections are 8.1 (§0.11); round 9 is narrow and is the next action
(§12 step 4). The channel inventory (§0.7) still grounds the trait model, and
P0 closed at 7.7 — see §11 P0.

## How To Execute This Plan From A Clean Session

This document is self-contained. Everything decided during planning is recorded
here or in a linked artefact; nothing load-bearing lives only in a chat
transcript.

**Read in this order before touching code:**

1. `AGENTS.md` — working rules. Two sections matter especially here: *Backward
   Compatibility Policy* (breaking changes are cheap and expected; the hard
   requirement is history — `Breaking` entries in `CHANGELOG.md`, an ADR for
   non-obvious rationale, migration steps written from the consumer's side) and
   *Decision framework for new features*.
2. This plan, in full. §5 carries the architectural decisions, §11 the work
   packages and file ownership, §13 the test plan.
3. [ADR 0016](../adr/0016-subject-cohesion.md) — the directory-layout rule that
   settles §16 and governs where new types go.
4. `src/Baseline/README.md` and `docs/ARCHITECTURE.md` for current state.

**Prerequisites outside this plan.** Two pre-existing defects were found during
planning and are tracked separately. Neither blocks P1a, P1b, P2, P3, or P4:

- Unstable cycle identity in `CircularDependencyRule` (§2.7). **Graph-kind
  ratcheting must not ship before this lands** — verify its state before P5.
- Expanding the Architecture slice into enforced internal sub-layers (ADR 0016).
  Unrelated to this feature; it only touches `qmx.yaml`, so coordinate if a
  package of this plan is editing that file at the same time (P1a owns it).

**Decision state.** Settled: the allowance rule (§5.1), the split between the
rule at capture and the reader at comparison (§5.2), contract placement in Core
(§5.3), silencing as a reporting attribute (§5.6), the file schema (§6), the
status model (§7.1), the lifecycle commands (§8), exit behaviour (§9.3), layered
layout (§16). Open: nothing that blocks P1a′. Two judgement calls are
deliberately left to implementation: the naming of the migration
disposition-plan schema fields (P3 specifies), and the per-channel projection of
the configuration fingerprint (§5.7, opt-in, revisit once real usage exists).

**Where execution stands.** P0, P1a and the two external prerequisites have
landed. The next package is **P1a′** (§11), re-cut at 8.0 around the reader and
carrying five Core contracts after round 8. P1b, P1c and P3 are blocked on it.
Nothing has been implemented against the inverted design yet, which is why
§17.3 calls this the cheapest moment to switch — and why round 8's CRITICAL cost
nothing but a paragraph.

**Review state.** Seven rounds examined the document up to 7.9; each of the last
three found HIGH findings in its predecessor's corrections, and all three are
summarised at the end of §0.9, the first four in §0.2–§0.6. Round 1 examined 7.0
with three reviewers; round 2 examined 7.1 with two; rounds 3 and 4 were
deliberately narrow, covering only §5.1, §7.1, and §8 — the sections that had
changed substantially and that this plan's history showed to be its defect sink.
§0.7 then replaced the plan's universal claims about the rule set with an actual
enumeration of it. **Round 8 has not run.** The 8.0 rewrite deletes one section,
inverts two decisions and derives several consequences that no reviewer has
seen; §17.6 item 4 makes reviewing it a precondition of writing code, on the
evidence that every previous round found HIGH findings in the previous round's
corrections.

**P0 is frozen at 7.7.** The remaining open item was the CLI naming question
§8 deferred to P0; it is now settled in `docs/internal/CLI_CONVENTIONS.md` and
every command name in §8 is final. No section of this plan may change without a
new revision number and an entry in §0.

**This document is temporary.** Once the feature has landed, delete
`docs/plan/` — both this revision and the superseded `ratchet-baseline-v6.md`.
The rationale that must outlive it belongs in the ADR produced by P6; anything
still only in this file when P6 is written has not been recorded properly yet.

**A note on this plan's own history.** Revision 7.0 fixed v6's architectural
problems and introduced three blockers of its own; 7.1 fixed those and left a
tautological `resolutionReason` that made `cleanup` a no-op for the feature's
main use case. That is the expected failure mode here: the design is a chain of
consequences from one premise (§5.1), and it is easy to take one step further
than the premise licenses. When changing anything in §5, re-derive the
consequences rather than patching locally — and prefer a test that asserts a
state is *reachable* over one that asserts it behaves correctly, since the
errors of this kind have all been unreachable states that read as correct.

The 8.0 rewrite adds the second half of that lesson, and it is the more useful
one. Reviews five, six and seven each found HIGH findings of one shape: **a rule
keyed on a proxy for the property it cared about** — the inventory's trigger
column standing in for "a predicate over measured axes", a boundary standing in
for "everything the measurement depended on", the word "sparse" standing in for
an identity. A proxy always agrees with its property on the examples that
motivated it. §17 is what happened when the proxies were finally replaced by
reading the property itself, and the same test applies to anything added here
next: *if this rule is keyed on something other than the fact it cares about,
enumerate the set and find where the two diverge.*

## 0. Revision History And Governing Decision

### 0.1 The governing change (7.0)

v6 made the ratchet absolute: a captured value was the ceiling forever, even
after the team deliberately raised the threshold. There was no way to express
"we have decided 50 is acceptable" — `update` only tightens,
`rebase-contracts` covers only rule-contract changes, `accept` was rejected by
design, and the only remaining path was `generate --force`, which silently
re-accepts *all* debt.

v7 defines the allowance as the **more permissive of the captured value and the
rule's violation-onset boundary**, per axis, in the axis's worse-direction
(`max` for higher-is-worse, `min` for lower-is-worse). Raising a threshold in
`qmx.yaml` is the legitimate, reviewable way to accept more debt; the ratchet
guards everything above the newly declared line. There is no second acceptance
surface.

Because `allowance >= onset boundary`, a ratchet regression is always also a
current violation. Observations therefore only need to exist where a violation
exists, which removed v6's O(symbols × rules) retention, its stored predicate
DSL, its `RuleInterface` return-type break, and its per-rule coverage maps.

### 0.2 What round 1 changed (7.1)

Three reviewers examined 7.0 against the code. Their findings, and the
corrections they forced, are folded in below. The load-bearing ones:

| Finding                                                                                                                                                                                                                                     | Correction                                                                                                                  |
| ------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- | --------------------------------------------------------------------------------------------------------------------------- |
| `currentEffectiveBoundary` was ambiguous; rules pick their threshold by the severity tier the current value landed in, so the allowance widened as the value grew — the ratchet blinded itself. Independently found by all three reviewers. | §5.1: the boundary is the **violation-onset** boundary, never the tier-matched one.                                         |
| Removing v6's independent failure path was a step wider than the premise: `fail_on` defaults to `error`, so a regression inside the warning tier printed and exited 0.                                                                      | §9.3: `regressed` fails the build regardless of the finding's severity, with an explicit opt-out.                           |
| `qmx.yaml` grants Baseline and Reporting `[core]` only, so a comparator in `src/Baseline` cannot consume a coverage DTO living in `src/Analysis`. `composer check` would fail at integration.                                               | §5.5, §11: the coverage **contract** and the status enum live in Core, owned by P1a; Analysis owns only the implementation. |
| `exclude_paths` / `exclude_namespaces` / per-rule exclusions are post-`analyze()` filters, not evaluation-exclusions. Combined with a global refuse-to-write, every lifecycle command would refuse on this project's own config.            | §5.6: taxonomy corrected against the code; §5.10: the write guard is per-entry, not global.                                 |
| `occurrence_key` was declared part of identity but had no field in the file schema — it could not survive a load/save cycle.                                                                                                                | §6: field added, with a deterministic sort rule.                                                                            |
| `resolved` conflated "the code was fixed" with "the policy moved". Cleanup would delete captured values that still represent real debt.                                                                                                     | §6, §7.1: the captured onset boundary is stored per axis (one number, not a predicate), and `resolved` carries a reason.    |
| An axis can be genuinely absent — `TccLccCollector` skips classes with fewer than two public methods, and `GodClassRuleTest::itAdjustsEvaluableCountWhenTccIsMissing` pins that behaviour. 7.0 required every axis to be numeric.           | §5.3, §6.2: an entry may carry a null axis; a *contract* shape change remains `incompatible`.                               |
| No way to obtain a rule's current contract when it emits no violation, so a forgotten version bump read as `resolved` instead of `incompatible`.                                                                                            | §5.7: an independent contract registry, populated from rules at boot.                                                       |
| P1 owned 44 rule files plus 74 collectors and blocked everything else; several files it needed belonged elsewhere.                                                                                                                          | §11: split into P1a/P1b/P1c with corrected ownership.                                                                       |

Corrections to 7.0's own claims, for the record: `DataClassRule` is **not** a
dynamic compound rule (its criteria are fixed), so §5.9 applies to `GodClassRule`
alone; and there is no changed-files-only *analysis* mode — `--report=git:*` is
a presentation filter, so 7.0's partial-scope branch described a mode that does
not exist.

### 0.3 What round 2 changed (7.2)

Two reviewers examined 7.1 against the code. Every finding below was verified
directly before being accepted.

| Finding                                                                                                                                                                                                                                                                                                                                              | Correction                                                                                                                                                      |
| ---------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- | --------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| `resolutionReason: fixed` compared the *captured* value against the *captured* onset — tautologically false, since an entry exists only because that comparison held. `fixed` was unreachable and `cleanup` could never remove anything, including genuinely fixed code.                                                                             | §7.1: the test compares **boundaries**, not values — the current onset against the captured one. Decidable without a current observation.                       |
| `CircularDependencyOptions::getSeverity()` returns `null` above `maxCycleSize`, so a cycle growing past the cutoff stops being reported. Debt growing made the finding vanish, resolving the entry *because* it got worse — a counterexample to §5.1's invariant from a rule shape nobody had considered: an upper cutoff rather than a lower onset. | §5.1: magnitude cutoffs are classified as configuration-silencing; such entries never resolve on absence alone. §14.7.                                          |
| `GodClassOptions::withOverride()` maps an inline `@qmx-threshold` onto `minCriteria`, not onto an axis, so "the onset reflects inline overrides" was undefined for compound rules.                                                                                                                                                                   | §5.1: compound rules have no per-axis onset; their axes carry `null` and an override changes only when the rule fires.                                          |
| §5.6's mechanism list was missing six suppression paths, including `@qmx-ignore-next-line`, `rules.<name>.enabled: false`, and rule applicability filters.                                                                                                                                                                                           | §5.6: list extended and declared **normative** — an unclassifiable mechanism is a finding against this plan, and P2 must enumerate exhaustively.                |
| `disabled_rules` mapped to `unobserved` in §5.6 and to `orphaned` in §7.1/§7.4. Two packages would have implemented two behaviours and each passed its own tests.                                                                                                                                                                                    | §7.4: the line is drawn at the build — disabled by config is `unobserved`, absent from the build is `orphaned`.                                                 |
| The `suppressed` outcome had no status and no counter, so per-status counts could not sum to the number of entries.                                                                                                                                                                                                                                  | §5.6, §9.1: `suppressed` is a bucket, with a summing invariant asserted in §13.                                                                                 |
| An unmatched v5 entry has no v7 representation — a v5 record is a rule name plus an opaque hash, and the hash algorithm is not even stored. §8 implied a disposition existed.                                                                                                                                                                        | §8: the only dispositions are drop or abort, chosen explicitly; §14.6.                                                                                          |
| `src/Configuration/**` was unowned while §9.3 promised a configuration key; `AbstractRule.php` had two owners; the worker serialisation path, which the new collector output shape crosses, was unowned; several tests certain to break belonged to nobody.                                                                                          | §11: ownership corrected, P4 split into P4a/P4b/P4c, each package now owns the tests its changes break.                                                         |
| P1b and P1c were declared parallel, but the occurrence-key carrier they exchange was undefined — both sides would pass their own DoD while the seam was broken, and the failure would surface as the already-accepted limitation §14.2.                                                                                                              | §11: the carrier is defined in P1a; P1c's DoD asserts a rule reading a collector-produced key, plus identity equality between `--workers=0` and a parallel run. |
| §10 described a metric cache that does not exist; only an AST cache does. A DoD was set against it.                                                                                                                                                                                                                                                  | §10: corrected to the actual cache, with the missing version component noted as a separate, non-blocking improvement.                                           |

Two reviewer claims were **rejected** after verification: that `ViolationHasher`'s
`xxh3`/`sha256` fallback is a portability risk (on PHP `^8.4` `xxh3` is always
present, so the fallback is dead code — the defect is the feature-detection
pattern, fixed by pinning the algorithm in §6.1, and its severity is low), and
that resolution should be provable without coverage.

### 0.4 What round 3 changed (7.3)

Round 3 was deliberately narrow — only §5.1, §7.1, and §8 — and aimed at this
plan's recurring defect: a state that reads as correct but cannot occur. The
findings below were derived and verified during the round; the external
reviewer's pass over the same sections is still outstanding.

| Finding                                                                                                                                                                                                                                                                                                                                                                                                   | Correction                                                                                                                                                                                                                                        |
| --------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- | ------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| `withinWidenedPolicy` reports "growth accepted by a widened policy", but such growth stops violating and produces no observation, so the attribute is silent for its own use case. 7.3 removed it as strictly unreachable; round 3 disproved that with an inclusive-comparison counterexample at the boundary — it fires in the band `[onset, onset+epsilon]` and nowhere else.                           | §7.1: attribute removed, with the corrected reasoning — it fires only on a boundary artefact, never for the case it was designed to report; the reachability of each status is stated per regime; §14.4 records the invisibility as a limitation. |
| One rule can carry many contracts. `ComputedMetricRule` declares a single rule name but sets `violationCode` to the user-defined metric's name, and each `ComputedMetricDefinition` has its own thresholds and its own `inverted` flag. Onset and direction are properties of the (rule, violation code) pair.                                                                                            | §5.1: stated explicitly, matching the granularity §5.5 already requires for coverage.                                                                                                                                                             |
| The contract registry was to be populated "from the rule set at boot". Computed-metric contracts are not static rule metadata — they arrive from user YAML via `ComputedMetricDefinitionHolder`, so a registry built by reflection over rule classes would be blind to every user-defined metric, and a forgotten version bump on one would produce the silent `resolved` the registry exists to prevent. | §5.7: population happens after the configuration pipeline runs.                                                                                                                                                                                   |

### 0.5 What round 3 changed (7.4)

Round 3 was a narrow reachability audit of §5.1, §7.1, and §8. It produced one
CRITICAL and seven HIGH findings, more than round 2 — but every one landed in
the same place, and that pattern mattered more than any individual finding.

**The structural change.** §5.1 had been stating a single onset rule and adding
a carve-out whenever a reviewer found a rule that did not fit. Three rounds
produced four carve-outs, with no reason to believe the fourth was the last: the
rule set is genuinely heterogeneous — inclusive and exclusive comparisons,
inverted axes, conjunctive and count-based compounds, magnitude bands,
per-symbol thresholds under one violation code, and one rule carrying a contract
per user-defined metric. 7.4 replaces the open deduction with a **closed
enumeration of nine shapes**, each specifying how the onset is obtained, what
the axes are, and what resolution requires. Totality is enforced by a
registry-driven test rather than asserted, so the next undiscovered shape fails
the build instead of waiting for a reviewer.

| Finding                                                                                                                                                                                                                                                                                                                                                       | Correction                                                                                                                                                                                     |
| ------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- | ---------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| `DataClassRule` fires on a conjunction (`WOC >= t` **and** `WMC <= t`), so WOC worsening while WMC crosses its bound silences the rule — the governing invariant fails for a *fixed* compound, not only a dynamic one. Round 2 had correctly established that DataClass is not dynamically evaluable, and 7.2 wrongly inferred that §5.9 did not apply to it. | §5.9 now covers every compound predicate, conjunctive or count-based. The lesson — a true correction can license a false inference — is recorded here rather than in a commit message.         |
| The onset was glossed as "the warning tier". `ComputedMetricRule` tests `errorThreshold` first and nothing validates tier ordering, so `warning: 20, error: 10` starts violating at 10.                                                                                                                                                                       | §5.1: the onset is the most permissive *configured* boundary computed across tiers, and the gloss is removed.                                                                                  |
| The onset can depend on the symbol, not just on (rule, code): `LongParameterListRule` selects VO or ordinary thresholds from the symbol's own metric under one violation code. A registry keyed by (rule, code) cannot supply it.                                                                                                                             | §5.1: rules expose an **onset provider** queried with the symbol's metrics. Metrics exist for every symbol regardless of violations, so this also gives `resolutionReason` its missing source. |
| §7.1 claimed that in the widened-onset regime only `regressed` and `resolved` are reachable. Most Options classes compare with `>=`, so the boundary value both violates and sits inside the allowance — `matched` is reachable there.                                                                                                                        | §7.1: reachability restated per regime and per declared comparison.                                                                                                                            |
| A compound entry's `resolutionReason` could not be computed — S5 has no per-axis onset — yet `cleanup` acts on it, risking deletion of a still-indebted entry.                                                                                                                                                                                                | §5.9: compound entries resolve as `policy` and are never auto-removed.                                                                                                                         |
| The S6 cutoff carve-out promised resolution on "positive evidence" without saying what evidence, while §5.6 forbade mutating silenced entries.                                                                                                                                                                                                                | §7.4: S6 resolves on symbol-inventory evidence, which does not depend on the rule.                                                                                                             |
| `update`'s debt-neutral re-pointing was literally unsatisfiable — a `new` finding has no captured axes — and ignored occurrence count, so a rename from one occurrence to five could be re-accepted.                                                                                                                                                          | §8: the comparison is old-captured against new-current, count included, ambiguity refused.                                                                                                     |
| An `incompatible` entry whose rule emits no violation had no targeted exit; only `generate --force` would clear it, re-accepting all unrelated debt.                                                                                                                                                                                                          | §8: `rebase-contracts` handles it, rewriting the contract and dropping the axes with an explicit report of the precision lost.                                                                 |
| Statuses were not mutually exclusive — an entry can be excluded, contract-changed, and rule-removed at once.                                                                                                                                                                                                                                                  | §7.1: a stated precedence order, exercised by a test.                                                                                                                                          |

The v5 migration protocol was reviewed in depth for the first time and found
sound as written, given that unmatched entries have only the two dispositions
recorded in §14.6.

### 0.6 What round 4 changed (7.5)

Round 4 tested 7.4's central claim — that nine enumerated rule shapes covered
every rule exactly once — with two independent reviewers. The claim failed, and
the failure was structural rather than a matter of missing entries.

**Why the enumeration could not work.** A channel's behaviour is a combination
of independent properties: where the onset comes from, whether comparison is
inclusive, which direction is worse, what predicate fires, whether a band hides
large findings, what magnitude is carried, what identity is tracked. The space
is a **product**, so any partition of it meets members that straddle two
classes. Concretely: `ClassRankRule` and `CodeDuplicationRule` mapped to no
shape; `CircularDependencyRule` is a graph identity *and* a banded magnitude;
`UnusedPrivateRule` is a presence *and* an occurrence; one shape had no members
at all. 7.5 replaces the taxonomy with per-channel trait declarations, total by
construction because there is nothing to fail to map onto.

**The deeper unit error.** The registry, the coverage key, and the shape
declarations were all keyed by rule *class*. `LayerViolationRule` emits five
channels, four under rule *names* no class declares — `architecture.coverage`,
`architecture.unreachable-layer`, `architecture.potential-shadow`,
`architecture.empty-template`. Under 7.4's precedence their entries would be
permanently `orphaned` while the same run re-emitted the findings as `new`, an
oscillation repeating forever. The unit is now the channel throughout.

| Finding                                                                                                                                                                                     | Correction                                                                                                                           |
| ------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- | ------------------------------------------------------------------------------------------------------------------------------------ |
| Enumeration neither total nor disjoint; one empty shape.                                                                                                                                    | §5.1: orthogonal traits per channel.                                                                                                 |
| Violations emitted under rule names no class owns.                                                                                                                                          | §5.1, §5.5, §7.4: channel is the unit of declaration, coverage, and the `orphaned` test.                                             |
| `ClassRankRule` scales thresholds by project class count, so the onset depends on the run, not on configuration or the symbol.                                                              | §5.1: the onset provider takes run-level context; run-conditioned is a declared value.                                               |
| §5.1 forbade resolving a compound channel by absence while §5.9 required resolving it as `policy` — a direct contradiction inside one section.                                              | §5.1: reconciled to §5.9's rule, since under §5.7 the configured policy decides what counts as debt.                                 |
| The cutoff channel's "inventory evidence" could not see the normal repair: deleting one dependency edge fixes a cycle while leaving every class in place, so the entry could never resolve. | §7.4: resolution uses pre-cutoff evidence — `AnalysisContext::$cycles` is populated before the cutoff is applied in `getSeverity()`. |
| 7.4's `rebase-contracts` exit wrote an entry with no axes, which §6.2 forbids and P3's loader would reject.                                                                                 | §8: such an entry is removed and reported, not rewritten.                                                                            |
| Re-pointing could absorb a genuinely new finding elsewhere as a rename, and ignored the occurrence key.                                                                                     | §8: the occurrence key must match and the original symbol must be absent from the inventory.                                         |
| `ComputedMetricRule` compares strictly, contradicting the claim that the common shapes fire at `>=`; the count of inclusive Options classes was also wrong.                                 | §5.1, §7.1: comparison is a declared trait, asserted by test rather than counted in prose.                                           |

Smaller items folded in without separate discussion: the onset provider must
reproduce `LongParameterListRule`'s asymmetry, where the VO branch bypasses
`getEffectiveOptions()` so inline overrides reach ordinary methods only;
`CodeDuplicationRule`'s effective floor is its detector's `min_lines` rather
than a configured tier, and its identity is a token hash, so magnitude
ratcheting is vacuous for that channel and it declares magnitude accordingly;
and the built-in `health.*` dimensions travel the same path as user-defined
metrics, so nothing may special-case "user-defined".

### 0.7 Validation against the rule inventory (7.6)

Four review rounds shared one root cause: this plan made universal claims about
the rule set without enumerating it, so each round discovered one more rule that
did not fit and the fix-and-review cycle could not converge. The enumeration was
finally done — [`channel-trait-inventory.md`](channel-trait-inventory.md), 41
concrete rule classes, 52 channels, every trait cell filled from source.

**The trait model survives.** No eighth dimension was needed and no channel
failed to map. Three corrections came out of it:

| Inventory finding                                                                                                                                                          | Correction                                                                                                                                    |
| -------------------------------------------------------------------------------------------------------------------------------------------------------------------------- | --------------------------------------------------------------------------------------------------------------------------------------------- |
| `CircularDependencyOptions::getSeverity()` mixes strict `>` for its cutoff with inclusive `<=` for its error tier, so comparison inclusivity is not one value per channel. | §5.1: comparison is declared **per boundary**.                                                                                                |
| `design.data-class` also has a cutoff — its `WMC <= threshold` conjunct silences the finding as WMC rises. The band dimension has two members, not one.                    | §5.1: recorded. This also refutes the idea that fixing `maxCycleSize` alone would let the dimension be dropped.                               |
| `vector` magnitude has zero members today: `Violation::metricValue` is `int\|float\|null`, so no channel emits several axes.                                               | §5.1: kept, with an explicit note that it is the value compound channels take once P1b adds their raw axes — it must not be pruned as unused. |

Two dimension values have exactly one member each — `symbol-conditioned`
(`code-smell.long-parameter-list`) and `run-conditioned` (`coupling.class-rank`).
Both are real and neither can be folded away.

The inventory also surfaced defects that are **out of scope here** and are
tracked separately: `LayerViolationRule`'s docblocks variously claim three, four,
and five diagnostic channels while the code emits five; several CodeSmell
`Options` classes implement a `getSeverity()` their rule never calls; and
`HardcodedCredentialsOptions`/`SensitiveParameterOptions` carry a `> 0` guard
that is unreachable-false at its only call site. None of them changes this plan.

### 0.8 What the P1a implementation review changed (7.8)

P1a was the first package to produce code, and three independent reviewers
examined it. The findings split cleanly in two, and the split is the useful
part: nine were defects **in the code**, fixed inside P1a; three were gaps **in
this plan**, which the code could only expose.

The code defects are recorded in the commit history, not here. Two are worth
carrying because they are instances of failure modes this plan keeps producing:

- A `permitsEntryMutation()` helper implemented the rule "false for every status
  that proves nothing about the code" while §5.6 says silenced entries are never
  mutated *even though the data exists*. The same class already encoded §5.6
  correctly in its precedence ordering — two rules in one type, and the frozen
  one lost. Worse, a unit test pinned the wrong value, so whoever fixed it later
  would have inherited a red test they did not own.
- A commutativity assertion over a data provider containing no mixed-type pair:
  the check passed both before and after the defect it existed to catch. This is
  the same shape as the tautological `resolutionReason` test of round 2.

The three plan gaps are specified at 7.9 (§0.9) and carved into package P1a′
(§11), which also picks up a fourth found while specifying them. All four have
the same character: a contract that later packages need, that §11's own rule
assigns to P1a ("cross-package data contracts are owned by P1a and by nobody
else"), and that P1a's Definition of Done did not enumerate — so it was
delivered complete against its DoD and still left a seam.

| Gap                                                                              | Why it cannot wait for its consumer                                                                                                                                                                                                                                                                                                                    |
| -------------------------------------------------------------------------------- | ------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------ |
| No Core contract for the **declared-channel / current-contract registry** (§5.7) | P1b declares channels, P3 queries them, and `baseline: [core]` means the query contract must be in Core. The consumer stub substitutes a raw array, which is the proof it is missing. Without it, the one scenario the registry exists for — a forgotten version bump on a rule emitting no violation — reads as `resolved` instead of `incompatible`. |
| No carrier for **"evaluated but silenced by configuration"** (§5.6 category 2)   | §7.1's precedence step 4 requires deciding `suppressed`. Such scopes are truthfully `Evaluated`, so the coverage contract answers correctly and still cannot express silencing; the exclusion configuration reaches no `[core]`-only layer.                                                                                                            |
| Coverage addresses channel and symbol, never an **occurrence**                   | §7.4 resolves a banded channel only on pre-cutoff evidence. Without occurrence-level addressing there is nowhere to put it, and the natural implementation draws exactly the inference §7.4 forbids: evaluated plus absent equals fixed.                                                                                                               |

**A placement rule, settled here rather than per package.** Vocabulary types
(`ComparisonStatus`, `ResolutionReason`, `WorseDirection`) belong in Core: three
independent subjects name them — Baseline computes, Reporting renders under
`reporting: [core]`, and §9.3's exit policy consults. Lifecycle *policy* — may an
ordinary command mutate this entry, may `cleanup` remove it — does not: it would
move wholesale into Baseline, so by ADR 0016's duplication test it is not a
cross-cutting primitive. **P3 owns that policy, and its Definition of Done must
assert it against §5.6's table**, since removing it from Core also removed the
only place it was pinned.

### 0.9 What closing P1a's seams changed (7.9)

7.9 specifies the three gaps §0.8 recorded, adds a fourth of the same shape,
and carves all four into package P1a′ (§11). Two of the four changed shape once
they were grounded in the code rather than in the plan's own prose, and both
changes came from a mechanical enumeration done *before* review rather than from
review finding them.

| What was assumed at 7.8                                                                          | What the enumeration showed                                                                                                                                                                                                                                                                  | Correction                                                                                                                                                                                                         |
| ------------------------------------------------------------------------------------------------ | -------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- | ------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------ |
| §7.4's pre-cutoff evidence "exists" in `AnalysisContext::$cycles`, so comparison can consult it. | It exists, but `AnalysisContext` is built for `RuleExecutor::execute()` and is not carried on `AnalysisResult`. Nothing after rule execution can read it. A comparator planning to look would find nothing.                                                                                  | §7.4: the evidence is a **snapshot recorded while rules run**, with a rule-side reporter and a run-side answer.                                                                                                    |
| The silencing carrier answers yes or no.                                                         | Two of the six mechanisms are not decidable for an entry that produced no finding: an occurrence whitelist keys on the candidate finding's own text, and `@qmx-ignore` keys on a line an entry does not carry. A boolean would have to guess, and the cheap-looking guess deletes real debt. | §5.6: two-valued after the second review round below — the whitelist half is decidable from the metric entries the run keeps; only a line-addressed author tag stays conservative, and §14.8 records that residue. |
| The registry stores all seven inventory dimensions per channel.                                  | Identity is not total — `architecture.coverage` emits one project-level aggregate per run and answers none of its three values. Magnitude describes today's `metricValue`, which §2.3 disqualifies, and already contradicts §5.9 for `design.god-class`.                                     | §5.7: the contract carries kind, axes, and four traits; magnitude and identity are deliberately not stored.                                                                                                        |
| The kind could be derived from (identity, magnitude) rather than declared.                       | Checked against all 52 inventory rows: no branch covers `architecture.coverage`, and `duplication.code-duplication` — whose line count drives its severity — would map onto the same value as eighteen channels carrying no magnitude at all.                                                | §5.7: the kind is stored.                                                                                                                                                                                          |

Also settled here, since 7.8 left it explicitly unrecorded: **§7.1's step 4 is
decided inside comparison, by query**, not by a filter stage outside Baseline.
A filter cannot classify an entry whose scope produced no finding, and per-rule
exclusions drop violations before the filter pipeline ever sees them.

The lesson P1a paid for is now a rule in §11: **a Definition of Done that lists
types is not a Definition of Done.** P1a′'s is stated as the questions its
consumers must be able to answer, each with the producer absent.

#### What the review of this revision changed

Two independent reviewers examined 7.9 before any code was written; a third
could not be driven to completion and its absence is recorded rather than
papered over. Every finding below was verified against the code or the inventory
before being accepted. They cluster, and the cluster is the point: **the first
draft of 7.9 wrote down refusals and forgot to check that the positive outcome
was still reachable** — the same defect shape as the tautological `fixed` test
of round 2 and the boundary-only `withinWidenedPolicy` of round 3, now
committed a third time by the section that exists to prevent it.

| Finding                                                                                                                                                                                                                                                                                                 | Correction                                                                                                                                                     |
| ------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- | -------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| The silencing verdict was defined per **mechanism**, so every entry of every `AbstractCodeSmellRule` channel answers `undecidable`, and `suppressed` outranks `resolved`. The feature's main outcome was unreachable, and a real regression would have been swallowed at step 4 before reaching step 5. | §5.6: decidability is a property of the **entry**; with a finding in hand `undecidable` cannot arise; without one it requires the mechanism to be present.     |
| `maxCycleSize` appeared in the silencing table as undecidable *and* in §7.4 as the case band evidence decides. A literal implementer returns `undecidable` first, and §7.4's `absent` branch — the entire pre-cutoff machinery — is dead on arrival.                                                    | §5.6: magnitude cutoffs are outside the silencing query, stated as a rule rather than implied by "handled separately".                                         |
| "`onsetSource: none` means `resolutionReason` can never be `fixed`" is backwards, and buries `cleanup` for roughly twenty of the fifty-two channels — every unconditional code-smell and security channel. With no boundary at all, nothing could have widened, so absence *is* proof of a fix.         | §5.7: the trait says how to obtain the reason, never that a reason is impossible.                                                                              |
| The band trait assumed a cutoff hides identities. `design.data-class` cuts off on the WMC axis it does not report, so it has no identity set; its entries would be permanently `unobserved`, contradicting §5.9. §14.7's "today only circular-dependency" was also false — the inventory counts two.    | §5.7, §7.4, §14.7: the band trait splits into cutoff-on-identity and cutoff-on-an-axis, and only the first asks for evidence.                                  |
| The onset provider was two-valued, so a **deleted symbol** — the commonest way for a finding to disappear — had to be reported as `policy`, and `cleanup` would never remove the least ambiguous fix there is.                                                                                          | §5.7: three-valued — and four after the round below added the non-numeric-boundary case; absence from a complete run resolves as `fixed` on coverage evidence. |
| The registry answered one question, but §7.4's `orphaned` line is drawn at the build while the registry is populated after configuration. Deleting a `computed_metrics` entry from `qmx.yaml` would make every one of its baseline entries prunable.                                                    | §5.7: two questions — declared by the build, and active in this run; `orphaned` reads only the first.                                                          |
| The declared comparison lived in the registry but not in the manifest, and is not among `ContractReference`'s bump triggers. Moving an operator from inclusive to exclusive widens the boundary without changing the number, so an entry reads as `fixed` and `cleanup` deletes standing debt.          | §6.1: the comparison joins the manifest and the bump triggers; the four traits explicitly do not.                                                              |
| The rule-side halves of three contracts belonged to no package. P1b would have passed its own DoD with an empty registry and no snapshot — §0.8's failure, one package later.                                                                                                                           | §11: P1b's DoD names all three, with checkable predicates, and owns the inventory.                                                                             |
| The band evidence reused the coverage vocabulary's shape while inverting its meaning (`present` forbids what `Evaluated` licenses), and nothing required the snapshot key to equal the observation key — two canonicalisations of one cycle never match, and the entry resolves *because* it grew.      | §7.4: its own named type, and one canonicalisation point with a test across both paths.                                                                        |
| `Core/Silencing/` was justified by the dependency edge — the argument ADR 0016 explicitly rejects — and split the subject `Core/Suppression/` already owns.                                                                                                                                             | §5.6: the contract joins `Core/Suppression/`, justified by subject.                                                                                            |
| Every DoD item asserted a refusal; none asserted that a positive outcome was reachable, and none crossed two contracts — so the two unreachability defects above would each have passed.                                                                                                                | §11: items 5 and 6 walk one entry through the whole §7.1 queue to `resolved`/`fixed`, and pin that step 4 does not swallow step 5.                             |

Smaller corrections folded in without discussion: the onset providers had no
stated directory; the union of Baseline's own author-tag answer with the
run-scoped one was undefined; §12 had two items numbered 4; and the
`ViolationChannel` move was described as four production references when it is
three.

A second, narrow round then examined those corrections alone — and found that
three of them had reproduced the very defect they were fixing. That result is
the strongest argument in this document for reviewing a plan before writing
code against it, and for reviewing the *corrections* rather than declaring
victory once findings are addressed.

| Finding                                                                                                                                                                                                                                                                                                                                                                          | Correction                                                                                                                                                                                                                               |
| -------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- | ---------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| The narrowing rule narrowed by "is such a mechanism configured", but allow-lists are configured **per rule**, so the predicate is true for every entry of the channel or none. `BooleanArgumentOptions` even ships a non-empty default, and this project's own `qmx.yaml` configures two such channels — so `resolved` stayed unreachable for whole channels, exactly as before. | §5.6: the verdict is **two-valued**. The whitelist is decidable — it filters metric entries, which survive on `AnalysisResult` with their `line` and `extra`, and the rule that holds the allow-list reports what it silenced, sparsely. |
| "A symbol absent from a complete run resolves as `fixed`" collided head-on with §5.9's "a compound entry never resolves as `fixed`". A deleted God class matched both, and `cleanup` acts only on `fixed`, so the two owning packages would have shipped opposite behaviours.                                                                                                    | §5.9: an explicit carve-out — the section's own reasoning (an axis can worsen while the predicate stops firing) cannot apply when the symbol is gone.                                                                                    |
| Splitting the registry into "declared by the build" and "active in this run" is unanswerable for `ComputedMetricRule`, which can only name its channels from configuration. Either the two questions collapse, or every user-defined metric is `orphaned` and prunable.                                                                                                          | §5.7: a declaration is **enumerated or open-ended**; `orphaned` means matched by neither. A removed definition leaves the open-ended declaration standing.                                                                               |
| `duplication.code-duplication` has an onset none of the four trait values described: `min_lines` / `min_tokens` are applied by the detector during Collection, so blocks below them are never found. Reading the tiers is forbidden by §5.1; reading the trigger as unconditional yields `fixed`, and raising `min_lines` would have erased every entry via `cleanup`.           | §5.7: a fifth onset source, `collection-gated`, with this channel's onset named explicitly and a test pinning `policy`.                                                                                                                  |
| Every DoD item still asserted one direction. An implementation that simply skips the silencing query whenever a finding exists passes all nine — and then fails the build on a regression inside `exclude_paths`, which §5.6 requires to be `suppressed`.                                                                                                                        | §11: item 2 gains the symmetric half, and items 2 and 5 are declared to be satisfied together.                                                                                                                                           |

A third round then examined the five design elements those corrections had
introduced — and again found four HIGH. The pattern across the three rounds is
now the finding that matters most, and it is recorded here as a rule rather than
as a tally: **every one of these defects was a rule keyed on a proxy for the
property it cared about.** §5.9 keyed on the inventory's trigger column instead
of "a predicate over measured axes", and swallowed the architecture channels.
The reason test keyed on the boundary instead of "everything the measurement
depended on", and read a configuration change as a fix. The silencing report was
scoped by the word "sparse" instead of by the observation's identity, and
degenerated to file granularity. A proxy always agrees with its property on the
examples that motivated it; the disagreements are elsewhere, and only
enumeration finds them.

| Finding                                                                                                                                                                                                                                                                                                                                      | Correction                                                                                                                  |
| -------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- | --------------------------------------------------------------------------------------------------------------------------- |
| §5.9 was keyed on the trigger column, which marks five channels conjunction or criteria-count. Three of them are predicates over *presence* with no axes at all, including `architecture.layer-violation` — whose normal repair is deleting one edge. Every such repair would have resolved as `policy` and stayed in the baseline for ever. | §5.9: keyed on "two or more measured axes", with all five channels enumerated and the three exclusions named.               |
| A removed computed-metric definition had no producer for `unobserved`: central coverage sees enabled *rules*, not the violation codes a rule emits. The entry reached `resolved`, the onset provider answered "no onset", and `cleanup` deleted it. Three supported operations reach that state.                                             | §5.7: the registry answers activity as well as declaration, and §7.1 step 2 reads it.                                       |
| The rule-side silencing report was called "sparse" and never scoped. The finest scope `AbstractCodeSmellRule` names without an occurrence key is the *file*, so one allowed parameter name would have silenced every entry of that channel in that file.                                                                                     | §5.6: the report is addressed by the observation's own identity, canonicalised in one place, asserted across both paths.    |
| The reason test compares boundaries, but configuration can move the *measured value* instead: `coupling.framework_namespaces` changes what CBO counts, `exclude_health` renormalises the weights of `health.overall`. Boundary unchanged, finding gone, verdict `fixed`, debt erased.                                                        | §5.7, §6.2: entries store a digest of the configuration their measurement depended on; a difference forces `policy`. §14.9. |
| `architecture.coverage` had no way to record its captured mode, so the new non-numeric answer degenerated to a constant `policy` and its entries would never be cleaned up. The same channel also has an undeclared magnitude — its unmatched-end count — so it was outside the ratchet entirely.                                            | §5.7, §6.2: the mode is stored as an opaque token and compared for permissiveness; the count is declared as an axis.        |
| A renamed class satisfies both `cleanup`'s deletion predicate and `update`'s re-pointing predicate, and nothing ordered them. `cleanup` first destroys the captured axes and leaves `generate --force` as the only exit.                                                                                                                     | §8: `cleanup` does not remove a re-pointing candidate; §14.3 records that configuration can rename symbols too.             |

Two further MEDIUM findings were folded in: the normative mechanism list was
missing the architecture `allow:` / `relations:` filter and
`BooleanArgumentRule`'s `flag_promoted_properties` gate, so the rule-side row is
now phrased as *any path by which a rule discards a measured entry* rather than
by naming an interface; and the registry's type row still described the
"declared / active" split that the prose had already replaced.

Three MEDIUM and five LOW findings from round two were folded in the same pass.
Two deserve a line because they were wrong *facts* in support of right
conclusions:
`Violation` carries no `extra` field and `Location::none()` exists, so "the
finding carries the text a whitelist would match" was false — the real reason a
finding proves non-silencing is that these mechanisms run before the finding set
is formed. And `CircularDependencyOptions::getSeverity()` does mix two
operators, but neither is an onset comparison, so it was the wrong citation for
putting the comparison in the manifest.

### 0.10 What the v8 inversion changed (8.0)

8.0 applies the decision recorded in §17: **a repair is proven by re-reading the
measurement, never inferred from the absence of a finding.** The decision, its
four sub-decisions and the six cautions attached to it are not restated here —
§17 stays in the document as the rationale, and this entry records only what the
rewrite did to the rest of it.

**The two rules that replace nine mechanism-specific ones.** §17.1 lists nine
ways a finding can vanish without the code improving, and every one of them was
a rule keyed on its own proxy. All nine are now closed by two statements and one
fingerprint:

| §17.1 mechanism                                           | What closes it at 8.0                                      |
| --------------------------------------------------------- | ---------------------------------------------------------- |
| widened threshold                                         | the captured onset decides repair; a moved boundary cannot |
| compound predicate stops firing                           | axes are read directly, so §5.9's whole subject dissolves  |
| magnitude cutoff hides growth                             | the reader reads ahead of the cutoff                       |
| eligibility gate on a measured value                      | the reader reads ahead of the gate                         |
| silenced by configuration                                 | the reader reads ahead of the filters                      |
| channel deactivated · scope not analysed · symbol renamed | nothing to read → `unobserved`; absence is never proof     |
| measurement inputs changed                                | the configuration fingerprint (§17.5 E) withholds `fixed`  |

An undiscovered tenth mechanism is closed by the same default, which is the
point of the inversion: a mechanism can only hide a *finding*, and findings no
longer decide anything except at capture time.

**What was deleted, and what it cost.** The deletions are the measure of the
change, so they are listed rather than left to be discovered by diff:

- **§5.9 in full** — compound channels needed a special rule only because a
  predicate that stops firing hid its axes. It no longer does. The section is
  kept as a stub so the ~14 cross-references in this document still resolve.
- **The boundary-comparison reason test** (§7.1) and with it `resolutionReason:
  policy` in its old meaning. Under measurement a widened threshold does not
  resolve an entry at all — it widens the allowance, and the entry stays.
- **The per-entry measurement digest and the opaque mode token** (7.9's §6.2),
  both collapsed into one global configuration fingerprint per §17.5 E.
- **Three of the four channel traits** (firing predicate, band, coverage scope).
  Each existed to tell the comparator which inference was safe for that channel;
  the reader answers per channel what they were declaring.
- **`suppressed` as a status.** Silencing can no longer delete an entry, so it
  stops preempting the outcome and becomes an attribute on it (§5.6).

**What survives untouched**, and is therefore not re-reviewed: everything landed
in `main` (§17.3), the file schema apart from the two fields above, the whole of
§8's lifecycle except the parts that named `policy`, §16's layout decision, and
the channel inventory, whose columns are still the enumeration P1b classifies
against.

**Consequences derived during the rewrite, which §17 did not state.** These are
the parts a reviewer should attack first, because they are the parts nobody has
checked:

| Derived                                                                                                                                                                                              | Where      |
| ---------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- | ---------- |
| A rule's own allow-list is the one policy the reader must **reproduce**, not read ahead of — otherwise the self-check fails on every run and every bucket-counted entry reports a phantom regression | §5.6       |
| `cleanup --prune-missing` is added. Refusing to infer repair from an absent symbol closed a data-loss path and opened a housekeeping one; the second is safe to close with an explicit flag          | §8         |
| `resolutionReason: policy` is renamed `unproven` and left with one producer — a changed configuration fingerprint                                                                                    | §7.1       |
| `resolved` is decided against the **captured** onset, not the current one — so a widened threshold yields `matched`, not `resolved`, and §17.5 B's example lands where B says it does                | §5.1, §7.1 |
| The onset query survives, narrowed to computing the allowance. It can no longer delete an entry, which is the whole reduction in its criticality                                                     | §5.1, §5.7 |
| A rule's own observation is a valid measurement whenever the finding fires, so the six reader-less channels still ratchet while firing and only ever fail to *resolve*                               | §5.2, §7.4 |
| The reader/observation disagreement of §17.5 F is a hard error, not a warning                                                                                                                        | §5.2       |
| `incompatible` moves ahead of `unobserved` in the precedence, because the contract is validated before any read is attempted                                                                         | §7.1       |
| 45 channels read the metric repository, one reads a recorded snapshot, six declare no reader — an arithmetic claim over the inventory, not an estimate                                               | §5.2       |

### 0.11 What review round 8 changed (8.1)

Three reviewers examined 8.0 before any code was written against it, on disjoint
slices: the core derivation, the mechanisms and the on-disk contract, and the
packages with their Definitions of Done. Thirty findings, about twenty distinct
after semantic dedup — **1 CRITICAL, 13 HIGH** — every one of them inside a
section 8.0 had rewritten.

Two findings were reached independently by two reviewers from different slices,
which is the strongest confirmation signal available here and the reason §17.1's
own decision was trusted:

| Found twice, independently                                                         | Reached via                                                                                                     |
| ---------------------------------------------------------------------------------- | --------------------------------------------------------------------------------------------------------------- |
| §7.1's outcome table is stated over axes, so an axis-less entry resolves vacuously | the reachability paragraph contradicting the table; and §6.2's axis-count sentence omitting occurrence entirely |
| The allow-list carve-out names a mechanism instead of a behaviour                  | `BooleanArgumentRule::shouldIncludeEntry()`; and this repository's own `qmx.yaml`                               |

**The CRITICAL, because it is the shape this plan keeps producing.** The outcome
table's `regressed` row read "any axis, **or the occurrence count**, is past its
allowance" while its other three rows named only axes. So "no axis is still
debt" was *vacuously true* for the twenty magnitude-free channels — every
unconditional code-smell and security channel — and every such entry read
`resolved`/`fixed` on its first run, with `cleanup` deleting the whole
code-smell and security half of a baseline while the debt stood. The author knew
the count was a separate dimension, since one row says so; the symmetric fix was
not carried across. Round 2 shipped a `fixed` test that was always false and
round 3 an attribute that was always false; this was the same defect inverted,
always true, and no refusal-only test suite detects either.

The correction is §7.1's **measured dimensions**: every predicate is stated over
axes, occurrence counts and identity presence alike, and §6.2 requires an entry
to carry at least one.

**Two corrections were regressions against 7.9, not new mistakes**, and both
came from the rewrite dropping a hard-won generalisation:

- The allow-list carve-out. 7.9 had already been corrected to say *any path by
  which a rule discards a measured entry*, precisely because
  `BooleanArgumentRule` filters twice and only one filter goes through the
  obvious interface. 8.0 reverted to naming the mechanism, which would have
  aborted every run on ordinary PHP 8 code.
- The fingerprint. 7.9 constrained the digest to measurement inputs; 8.0 dropped
  the constraint as unnecessary once thresholds became harmless, and with it the
  projection — leaving "the resolved configuration", which in this codebase
  carries absolute host paths, CLI flags and log records. A baseline generated
  locally would never verify in CI, `fixed` would be unreachable by
  construction, and users would be pushed to `generate --force`.

**What else changed**, in one line each:

| Finding                                                                                                                                                                                    | Correction                                                                                                                                                       |
| ------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------ | ---------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| Compound axes were declared onset-less, which made `resolved` unreachable for both compound channels — and the claim is false in the code                                                  | §5.9, §14.1: they carry per-axis onsets; the residue is `design.god-class` alone, and `design.data-class`'s two axes have **opposite** directions                |
| A reading below the captured onset while the rule still fires produced `resolved` beside a live violation, with no rule saying which wins                                                  | §7.1: an entry is still debt if any dimension is **or a finding fired**, so `resolved` implies no current finding                                                |
| Two of `unobserved`'s four causes were unguarded by the absence of a finding, so one parse failure downgraded real regressions to diagnostics                                              | §7.1: the measurement is established *before* the trust gate runs                                                                                                |
| `incompatible` moving ahead of `unobserved` sent a removed `computed_metrics` definition to a status `rebase-contracts` deletes                                                            | §7.1: a registry with nothing to say is not a disagreement                                                                                                       |
| The reader/observation self-check had no defined pairing for bucket-counted entries — aborting every run, or vacuous for twenty channels                                                   | §5.2: the compared quantity is stated per dimension; the hard error fires only where it is defined                                                               |
| The fingerprint covered configuration but not the analyser build, and §10 permits algorithm changes without a contract bump                                                                | §5.7, §14.9: the digest covers measurement *provenance*, build included; every upgrade suspends `fixed` until regeneration                                       |
| §5.5's deviation list still reported the eligibility gates §5.6 tells the reader to read past — and `excludeReadonly` defaults to `true`                                                   | §5.5, §5.6: a deviation is only what makes the measurement untrustworthy; applicability filters leave the table                                                  |
| An empty metric list and a repaired smell are the same observable                                                                                                                          | §5.2: a reader may answer zero only when the owning symbol is in this run's repository                                                                           |
| A `resolved` entry with an unread axis had no admissible reason                                                                                                                            | §7.1: `resolved` requires every dimension read                                                                                                                   |
| Inline `@qmx-threshold` overrides die with `AnalysisContext` and no package carried them                                                                                                   | §11: they join the run-facts carrier; P2 owns them                                                                                                               |
| §7.3 decided three kinds by identity absence while the headline claim said absence is never proof                                                                                          | §7.4: absence from an enumeration the run produced is a measurement; absence from discovery is not; a composite identity naming an absent symbol is `unobserved` |
| A `regressed` entry with no finding had no severity, and three formatters need one                                                                                                         | §7.1: the tier the current reading falls into                                                                                                                    |
| The fingerprint had no owner — P3's DoD required a value only P4a produces, two packages later                                                                                             | §11: a fifth Core contract in P1a′; the corollary is stated as a rule                                                                                            |
| One global fingerprint field cannot express the per-channel opt-in §17.5 E offers                                                                                                          | §6.1: the field is a map                                                                                                                                         |
| The CLI grammar block marked "final" omitted four flags its own prose requires                                                                                                             | §8: the block is the complete signature                                                                                                                          |
| No command could clear a repaired entry on a reader-less channel while its symbol existed                                                                                                  | §8: `cleanup --prune-unprovable`, restricted to channels with no reader                                                                                          |
| `withinWidenedPolicy` qualifying `resolved` asserts something false                                                                                                                        | §7.1: it qualifies the three non-resolved outcomes                                                                                                               |
| §14 claimed four closures and struck three; §11's preamble still assigned cross-package contracts to P1a; §13 promised a rename test no bullet delivered; the JSON example omitted `scope` | §14, §11, §13, §6 respectively                                                                                                                                   |

**The lesson, stated as a rule rather than a tally.** Every regression above was
introduced by *rewriting a section rather than editing it*: the generalisation
that a previous round had paid for was in the sentence being replaced, and the
replacement was derived from the design rather than from the sentence. When a
section carries a correction from an earlier round — and §0.2–§0.9 say which do
— the rewrite must carry the counterexample forward, not just the conclusion.

## 1. Executive Summary

Baseline v5 is an identity-only suppression snapshot: once a violation is
listed, it stays hidden however much worse it gets. v7 makes **ratcheting** the
default for newly generated baselines. A baselined finding stays hidden while
its measured debt is within the allowance, and is reported when it exceeds it.
Full suppression remains available through an explicit `mode: suppress` file.

The baseline is a record of **accepted measurements, re-measured every run** —
not a record of accepted findings compared against findings (§17.2). A rule is
authoritative when the debt is captured; afterwards the axes it captured are
re-read directly, ahead of thresholds, eligibility gates, band cutoffs and
exclusion filters. An entry is removed only when that reading proves the debt is
gone, never because no rule mentioned it.

"v8" throughout §17 and §0.10 names the **plan revision**, not a file version.
The on-disk format stays `version: 7`; v7 has never shipped, so there is nothing
to migrate between.

## 2. Preconditions and Current-State Facts

Each fact below was verified against the code during review; the citation is
the verification point, not an implementation instruction.

### 2.1 v5 stores identity, not magnitude

`BaselineEntry` holds `rule` and `hash`. The hash excludes line, message,
severity, threshold, and value, so a metric growing from 25 to 100 still
matches its entry.

### 2.2 Current deduplication loses multiplicity

Generation deduplicates by canonical symbol plus hash, collapsing repeated
findings that share an identity — repeated smells and security findings,
repeated dependency use-sites, and any rule whose identity is coarser than the
occurrence.

### 2.3 `Violation::metricValue` is not a universal debt contract

`GodClassRule` sets it to the *count of matched criteria*;
`CircularDependencyRule` sets it to the cycle size. Others expose a
display-rounded value, a binary sentinel, or one axis of a multi-axis decision.
A structured observation is required.

### 2.4 Rule thresholds are tier-dependent

Rules select `$threshold` by the severity tier the measurement fell into
(`ComplexityRule`, `ConstructorOverinjectionRule`, and 30 Options classes share
this shape). `Violation::threshold` is therefore **not** a stable boundary and
must not be used as one — see §5.1.

### 2.5 Exclusions are post-evaluation filters

`RuleExecutor` runs `analyze()` in full and then drops violations from excluded
namespaces and paths; `ViolationFilterPipeline` applies global exclusions after
all rules have run, in the order baseline → inline suppression → path →
namespace → git. Only the discovery-level `exclude` key prevents measurement.
Additionally, baseline generation today consumes the raw pre-filter violation
list, so exclusions do not currently affect what is captured.

This fact is load-bearing in both directions at 8.0. It is why §5.6's
categories exist at all — and it is also why a reader can measure inside a
silenced area: the metrics were computed, only the findings were dropped.

### 2.6 There is no changed-files-only analysis

`--report=git:*` is a filter step in `ViolationFilterPipeline`; discovery and
the pipeline have no changed-file mode. Analysis is always full.

### 2.7 Two pre-existing defects bound this work

- `CircularDependencyRule` derives a cycle's `symbolPath` from `$classes[0]`,
  taken from the Tarjan SCC stack with no canonical ordering, so cycle identity
  depends on traversal order. Fixed **outside this plan**; graph-kind ratcheting
  must not ship before it lands.
- `ViolationHasher` feature-detects `xxh3` with a `sha256` fallback. On PHP
  `^8.4` the fallback is dead code, so the practical risk is low, but a portable
  format must pin its algorithm — §6.1.

### 2.8 The measurement survives the analysis; the rule's working state does not

`AnalysisResult` carries `MetricRepositoryInterface $metrics`, and
`MetricRepositoryInterface` lives in `Core/Metric/`. So a comparator running
after analysis can re-read any metric, from a `[core]`-only layer, with no new
pipeline phase. This is the fact the whole of 8.0 rests on.

Two things it does **not** carry. `AnalysisContext` — which holds `$cycles`,
`$duplicateBlocks`, `$dependencyGraph` and `$thresholdOverrides` — is built for
`RuleExecutor::execute()` and is not carried on the result. Anything a rule
computes from those inputs is gone by comparison time unless something records
it while rules run (§7.4). And the repository holds *metrics*, not violations:
what a rule concluded from a metric is not stored anywhere.

Rules read that repository rather than the AST (`AGENTS.md`, Critical Rule 2),
which is why the reader can be defined for most channels at all — it reads the
same entries the rule read, minus the rule's policy. The exceptions are exactly
the channels that read `AnalysisContext` instead, enumerated in §5.2.

## 3. Goals

1. Ratcheting is the default for newly generated baselines.
2. Worsening is detected for scalar, vector, occurrence, presence, and graph
   findings.
3. An explicit full-suppression mode is preserved.
4. Comparison never depends on human-readable messages.
5. Unevaluated scope is conservative: it never resolves or mutates an entry.
   An entry is removed only on **positive proof** that the measurement it
   captured is repaired — never because nothing reported it.
6. Files stay deterministic, portable, and atomically written.
7. v5 migrates through an explicit, reviewable, two-phase process.
8. Output stays legible to a human and to an agent at 500+ entries.
9. A 1M-line codebase analyses within a `memory_limit` of 2G.

## 4. Non-Goals

1. Historical trend storage.
2. Automatic acceptance of new debt.
3. Inferring values by parsing violation messages.
4. Treating severity changes alone as code regressions.
5. Supporting v5 during normal `check` execution.
6. A runtime flag overriding the mode stored in a committed file.
7. Comparing two arbitrary Git revisions.
8. ~~Per-axis ratcheting *inside* a compound rule~~ — **withdrawn at 8.0.** This
   was a non-goal because a compound predicate that stops firing hides its axes,
   and 7.9 had no way to see past that. The reader does, so per-axis ratcheting
   inside a compound rule is now ordinary behaviour rather than an excluded
   ambition (§5.9, deleted; §14.1).
9. Introducing a changed-files-only analysis mode (§2.6).
10. Proving repair for the six channels that declare no reader (§14.10). The
    guarantee is deliberately partial, and stating the partiality as a non-goal
    is what stops an implementer inferring resolution for them from silence.

## 5. Architectural Decisions

### 5.1 The allowance rule

For a baselined identity and each axis:

```text
allowance(axis) = more-permissive-of(captured(axis), onsetBoundary(axis))
                  in the axis's worse-direction
```

`onsetBoundary` is the **violation-onset boundary**: the most permissive
boundary at which a violation is emitted at all, for this symbol, under the
configuration and run in force now.

**What the allowance decides, and what it does not (8.0).** The allowance
decides `regressed`, `improved` and `matched` — how much growth the team has
already agreed to tolerate. It does **not** decide `resolved`. Repair is decided
against the **captured** onset, the number stored in the entry (§7.1), and needs
no query of any kind.

That split is the safety property the inversion buys, and it is worth stating in
the same breath as the formula, because up to 7.9 one query decided both. A
wrong current onset now widens or narrows an allowance, so a regression may be
reported late or spuriously — visible, recoverable, and never silent. It can no
longer remove an entry. Every destructive failure this plan has recorded ran
through the path that no longer exists.

#### The unit of declaration is a channel, not a rule class

A **channel** is a `(ruleName, violationCode)` pair that can appear on an
emitted violation. Channels are not in bijection with rule classes, and every
previous revision of this section broke on that assumption:

- `LayerViolationRule` emits five channels, four of them under rule *names* no
  class declares — `architecture.coverage`, `architecture.unreachable-layer`,
  `architecture.potential-shadow`, `architecture.empty-template`. A registry
  keyed by rule class cannot see them; §7.1's precedence would mark their
  entries permanently `orphaned` while the same run re-emits the findings as
  `new`, every run, forever.
- `ComputedMetricRule` emits one channel per metric definition, built-in
  `health.*` and user-defined `computed.*` alike, each with its own thresholds
  and inversion.
- `LongParameterListRule` emits one channel whose thresholds depend on the
  symbol.

So the registry (§5.7), the coverage key (§5.5), and the trait declarations
below are all keyed by channel. Rules enumerate the channels they can emit; a
violation whose channel is not declared is a hard error, not a warning.

#### Traits are orthogonal, not a taxonomy

Revision 7.4 enumerated nine rule "shapes" and required each rule to map to
exactly one. Review found the set neither total (`ClassRankRule`,
`CodeDuplicationRule` fit nothing) nor disjoint (`CircularDependencyRule` is a
graph identity *and* a banded magnitude; `UnusedPrivateRule` is a presence *and*
an occurrence). The failure has a cause worth stating, because it is the reason
this section has now been rewritten twice: a channel's behaviour is a
**combination of independent properties**, so the space is a product, not a
partition, and any taxonomy of it will keep meeting members that straddle two
classes.

The dimensions below are **not** proposed from examples: they were validated
against a full inventory of the rule set, recorded in
[`channel-trait-inventory.md`](channel-trait-inventory.md) — 41 concrete rule
classes, 52 channels, every cell filled. That inventory is the artefact this
plan should have started from, and it remains the enumeration P1b classifies
against.

**At 8.0 the inventory is a fact table, not a declaration requirement.** Up to
7.9 every channel had to declare a value on every dimension, because the
comparator branched on them to decide which inference was safe for that channel.
It no longer infers, so it no longer branches: the registry (§5.7) stores only
the three dimensions a consumer still reads — onset source, comparison and
direction, all three feeding the allowance — plus the reader declaration of
§5.2, which is not one of these dimensions at all. Firing predicate, band,
magnitude and identity stay in the inventory as facts and are stored nowhere.

| Dimension        | Values                                                          | Notes                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                            |
| ---------------- | --------------------------------------------------------------- | -------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| Onset source     | none / configured tiers / symbol-conditioned / run-conditioned  | `ClassRankRule` is run-conditioned: its thresholds scale by project class count                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                  |
| Comparison       | inclusive / exclusive, **declared per axis**                    | Follows direction by an existing, documented convention: higher-is-worse compares inclusively (the threshold is the first *bad* value), lower-is-worse strictly (it is the first *acceptable* one — see the rationale comment in `MaintainabilityOptions::getSeverity()`). Declaring it is mechanical, not a judgement call. 7.9 said *per boundary*, citing `CircularDependencyOptions::getSeverity()` for mixing operators; §0.9 had already established that neither of its operators is an onset comparison, and 8.0's manifest stores exactly one `compare` per axis (§6.1) |
| Direction        | higher-is-worse / lower-is-worse                                | per axis                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                         |
| Firing predicate | single threshold / conjunction / criteria count / unconditional | `DataClassRule` conjunctive, `GodClassRule` criteria count                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                       |
| Band             | unbounded / cutoff                                              | a cutoff hides the finding as debt grows (`maxCycleSize`)                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                        |
| Magnitude        | none / scalar / vector / count                                  | `CodeDuplicationRule` carries a scalar *and* an occurrence identity                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                              |
| Identity         | symbol / occurrence / graph                                     | independent of magnitude                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                         |

The **onset provider** is the query behind the first dimension: given a channel,
a symbol, its metrics, and run-level context, it returns the current onset and
direction per axis, or reports that the channel has none. Run-level context is
required, not optional — without it `ClassRankRule` is unrepresentable. The
provider must reproduce the rule's *actual* behaviour rather than a symmetric
idealisation of it: `LongParameterListRule`'s VO branch bypasses
`getEffectiveOptions()`, so an inline `@qmx-threshold` applies to ordinary
methods only, and the provider must say so.

At 8.0 the provider answers **one consumer, the allowance**, and its four-valued
answer of 7.9 collapses accordingly: a channel with no onset, or one whose onset
is not a number (`architecture.coverage`'s `mode`), simply contributes nothing,
and the allowance is the captured value. Neither case needs recording in the
file any more, because neither decides anything about repair. The opaque mode
token 7.9 introduced for that purpose is dropped (§0.10).

Three onset sources are forbidden, each because a revision of this plan used it
and was wrong: `Violation::threshold` (it is the tier the measurement landed in,
so the allowance widens as the code worsens, §2.4); the field literally named
`warning` (`ComputedMetricRule` tests `errorThreshold` first and nothing
validates tier ordering, so `warning: 20, error: 10` starts violating at 10);
and any value keyed by channel alone (the onset can depend on the symbol or on
the run).

#### The invariant that 8.0 retires

Up to 7.9 this section claimed that **a regression is also a current
violation**, and then spent two trait values and the whole of §5.9 explaining
where the claim failed. It is now retired outright (§17.5 A).

The claim was a property of inferring from findings, and it was always the
weaker half of the design: it held for the channels where nothing could go
wrong, and broke for exactly the channels where debt could grow unseen — a
compound predicate that stops firing while an axis worsens, a cutoff that hides
a cycle as it grows. Under measurement those are ordinary regressions. Nothing
special-cases them, and §5.9, which existed to work around the first of them,
is deleted.

**The consequence is visible to users and must be reported, not just accepted**
(§17.7 I): an entry can be `regressed` while `qmx check` reports no violation on
that symbol at all. That is the design working — the growth is real and the rule
stopped looking — but it reads as a bug unless the output says why. §9.1's
regression line names the case, and P6 documents it.

§13 replaces the per-trait invariant tests with their inverse: for each of the
two shapes above, a fixture where the predicate stops firing while an axis
worsens, asserted to be `regressed`.

### 5.2 The rule captures, the reader compares

**At capture time the rule is authoritative.** It attaches a structured
observation to each `Violation` it emits. There is no separate observation
channel, no second traversal, and no change to `RuleInterface::analyze()`'s
return type. Rules remain stateless.

`Violation` gains one observation-carrying member. Because `Violation` already
carries a constructor-overinjection threshold override, the observation is a
single bundled value object, not a set of new scalar parameters.

**At comparison time a reader is authoritative.** A **channel reader** answers
one question: *what do this entry's axes measure now?* It is given the entry's
channel, its identity — symbol, and occurrence key where the channel has one —
and the run's own facts, and it returns a value per axis, or reports that it
cannot answer. It reads **ahead of** thresholds, severity tiers, eligibility
gates, band cutoffs and exclusion filters: the mechanisms that can make a
finding vanish while the debt stands. It does *not* read ahead of **any path by
which a rule discards a candidate occurrence before constructing a violation for
it** — those decide what exists rather than what is reported. §5.6 states that
boundary, names why it must be phrased as a behaviour rather than as an
interface, and explains why generalising past it breaks the design.

For 45 of the 52 channels a reader is a metric lookup, because that is what the
rule itself does (§2.8) — the reader reads the same entries minus the rule's
policy. Three answers exhaust the question:

| Answer               | When                                                                                                | Consequence                                          |
| -------------------- | --------------------------------------------------------------------------------------------------- | ---------------------------------------------------- |
| the values, per axis | the metrics for this identity exist in this run                                                     | §7.1 decides the outcome from them                   |
| `not answerable`     | the symbol, the metric or the recorded evidence is absent, or coverage for this scope is incomplete | `unobserved` — never a resolution                    |
| `no reader`          | the channel declared none                                                                           | `unobserved` when no finding fired; §7.4 reports why |

**An empty list is not a reading of zero.** `MetricBag::entries()` returns an
empty list for a key that was never measured and for a smell that was genuinely
removed — the same value for the two opposite facts, on the nineteen occurrence
channels where the reader's whole job is to tell them apart. So the reader may
answer zero **only when the owning symbol is present in this run's repository**
(`MetricRepositoryInterface::has()`), and must answer `not answerable`
otherwise. Without that sentence an implementer picks one of two readings:
"empty means repaired" resolves an entry whenever the file stopped being
analysed, and "empty means unknown" makes `resolved` unreachable for nineteen of
the forty-five readable channels. The same rule covers the scalar channels for
free.

`not answerable` covers the case that matters most and is easiest to get wrong:
**a symbol absent from a complete discovery is not proof of repair** (§17.4). A
rename and a delete-plus-create are indistinguishable from the outside, so the
absent symbol yields `unobserved` and `update`'s re-pointing (§8) is the only
path that moves such an entry. This is the single largest behavioural difference
from 7.9, which resolved that entry as `fixed` and let `cleanup` delete it.

#### Which channel has which reader — the whole inventory, by arithmetic

The claim "most channels are readable" is exactly the kind of unenumerated
generalisation this plan has been punished for four times, so it is enumerated.
Grouped by the inventory's own sections, and totalling 52:

| Group                                                                                                  | Channels | Reader                                                                     |
| ------------------------------------------------------------------------------------------------------ | -------- | -------------------------------------------------------------------------- |
| CodeSmell                                                                                              | 14       | `codeSmell.{type}` entries, or the count metric behind the tiered channels |
| Complexity, plus the `computed.health` mechanism                                                       | 7        | metric values                                                              |
| Coupling                                                                                               | 6        | metric values                                                              |
| Design and Maintainability (`data-class`, `god-class`, three `type-coverage`, `maintainability.index`) | 6        | metric values                                                              |
| Size and Structure                                                                                     | 7        | metric values                                                              |
| Security                                                                                               | 5        | `security.{type}` entries                                                  |
| **Metric-repository readers**                                                                          | **45**   |                                                                            |
| `architecture.circular-dependency`                                                                     | 1        | a snapshot recorded during rule execution (§7.4)                           |
| `duplication.code-duplication` and the five `LayerViolationRule` channels                              | 6        | **none declared** (§17.4)                                                  |

The `computed.health` row counts as one, exactly as the inventory counts it: it
is a *mechanism* rather than a fixed channel, standing for six built-in
`health.*` channels plus an open-ended number of user-defined `computed.*` ones.
One reader serves them all — the metric is looked up by the definition's own
name — which is why the arithmetic does not need to unfold it.

The six with no reader are the channels whose facts live on `AnalysisContext`
and die with it (§2.8): duplication blocks, the dependency graph and layer
assignment. `architecture.circular-dependency` is on the same footing and is
nevertheless supported, because §7.4 already required its pre-cutoff cycle set
to be recorded while rules run — which, under 8.0, *is* a reader. The same
mechanism would serve the other six; §14.10 records why they are deferred rather
than impossible.

**No reader is not a second-class entry.** Those six channels ratchet exactly
like the rest while their finding fires, because of the next rule.

#### A firing finding is its own measurement

When the rule reports the finding this run, its observation **is** the
measurement, and no reader is consulted for the outcome. The rule is the
authoritative party for the axes it computes, and asking two parties the same
question and preferring the junior one would be perverse.

This is what keeps the guarantee's partiality narrow. A reader-less channel
compares, regresses and improves normally; the only thing it cannot do is
*resolve*, because resolution is precisely the case where no finding exists to
speak for it.

#### The reader is checked against the rule on every run (§17.5 F)

Whenever a finding fires **and** its channel declares a reader, both answers are
in hand for the same axes of the same identity. They must agree. The check runs
at full scale on every analysis rather than in a fixture, because a fixture
exercises the shapes someone thought of.

**A disagreement is a hard error**, not a warning — the same choice the codebase
already makes for a missing `SectionNormalizationPolicy` and for an undeclared
channel (§5.1). A warning in this position is a warning nobody reads, and what
it is failing to report is two implementations of one measurement drifting apart
— the failure mode that ends in deleted debt. The error names the channel, the
identity and both values.

**The unit of comparison is stated per kind, or the check is a trap.** Round 8
found the pairing undefined for the largest group it applies to. For a
bucket-counted occurrence entry the rule emits one `Violation` per occurrence,
each carrying an observation with no axes and no key, while the entry's
measurement is a *count* of them; nothing pairs one observation with one count.
An implementation comparing per finding aborts every run that contains a
code-smell finding, and one comparing axes only is vacuously green for the
twenty magnitude-free channels — which is precisely the population the check
exists to protect. So:

| Entry's dimension | Compared quantity                                                                               |
| ----------------- | ----------------------------------------------------------------------------------------------- |
| axis              | the observation's value for that axis against the reading                                       |
| occurrence count  | the count of findings for that identity in this run, aggregated on both sides at the same point |
| identity presence | whether the identity appears among this run's findings                                          |

The hard error fires only where the pairing above is defined. P1b's Definition
of Done requires it to be proven on a seeded disagreement **for a bucket-counted
channel**, not only for a scalar one.

**The check is blind exactly where the risk is** (§17.7 G). It validates readers
against findings that still fire; the verdict that destroys data is the opposite
one, where the reader says a debt is gone and by construction no observation
exists to disagree. A channel whose findings have all been repaired stops being
exercised at the moment its reader starts removing entries. The run-time pairing
is therefore necessary and not sufficient, and P5 owes the complement: fixtures
where a finding is deliberately removed and the reader must return the cleared
value, one per reader shape, plus the dogfooding run against this repository's
own baseline.

#### "Policy-independent" means independent of the rule's policy (§17.7 H)

Not of the collector's. The reader reads ahead of everything the *rule* applies,
but for the metric-repository channels it reads metrics that are themselves
computed under configuration: `coupling.framework_namespaces` shapes `cbo_app`
during Collection, and `min_lines` / `min_tokens` decide which duplicate blocks
exist at all. No reader can see behind that, and an implementer must not claim an
independence the design does not have.

That residue is what the configuration fingerprint (§5.7) covers, and the two
must be read together: the reader makes the measurement policy-independent, the
fingerprint withholds `fixed` when the measurement's own inputs changed.

### 5.3 Core owns the observation and coverage contracts

Contracts live under `Core/Observation` — named for what they describe, measured
debt, rather than for the feature that consumes them. This keeps Core free of
feature naming and answers "why does a rule know about baselines": it does not.

```text
DebtObservation
  contract: ContractReference
  kind: ObservationKind
  axes: map<string, AxisObservation>
  occurrenceKey: string|null

ContractReference
  id: string
  version: positive integer

AxisObservation
  name: string
  rawValue: int|float|null     // null = metric genuinely unavailable for this symbol
  onsetBoundary: int|float|null
  worseDirection: Higher|Lower
  epsilon: non-negative float

ObservationKind
  Scalar | Vector | Occurrence | Presence | Graph
```

A null `rawValue` is a legal, first-class state, not a shape change: a class
with fewer than two public methods has no TCC at all
(`TccLccCollector`), and `GodClassRuleTest::itAdjustsEvaluableCountWhenTccIsMissing`
already pins that behaviour. §5.8 distinguishes a null axis value from a
contract shape change.

The **coverage contract** — its interface, its scope-result value objects, and
the comparison status enum — also live in Core, because `qmx.yaml` grants both
`baseline` and `reporting` a dependency on `[core]` alone. Placing the coverage
DTO in `Analysis` and consuming it from `Baseline` is an upward edge that
`composer check` rejects.

The **reader contract** (§5.2) lives in `Core/Channel/`, beside the registry and
the onset provider: what a channel measures for an identity is the channel's
subject, not a subject of its own. Its argument carrier is the third type, and
it is the one to get right — the reader needs the run's facts, and those are
`MetricRepositoryInterface` (already in `Core/Metric/`, already carried on
`AnalysisResult` per §2.8) plus whatever P2 recorded during rule execution. A
carrier assembled from Core types only is what keeps `baseline: [core]`
satisfiable while the *implementations* live next to the rules that know their
metric keys.

### 5.4 Observation identity

The logical key is: rule name, violation code, canonical `SymbolPath`, contract
**id** (without version), and an optional stable occurrence discriminator.

The contract version is deliberately **excluded** from the key. v6 put it in the
key while also expecting a bump to yield `incompatible`; those are mutually
exclusive — a versioned key simply fails to match and reads as `resolved` plus
`new`. Here the version is compared after the identity matches.

Rules that can provide a stable occurrence discriminator must do so; where none
exists, a bucket count is stored and the blind spot is documented (§14.2).
Duplicate logical identities are combined per the declared occurrence contract
or rejected as an invariant violation. Collection order never affects output.

### 5.5 Coverage is central, with sparse rule deviations

`Analysis` implements the Core coverage contract from data it already owns:
discovery inventory, parse failures, worker failures, enabled rules, and the
exclusion configuration. A scope is `Evaluated` when it was discovered, parsed,
not excluded from *evaluation* (§5.6), and its rule was enabled and completed.

Rules report only what the centre cannot see, as a sparse deviation list — never
a per-symbol map. Confirmed cases: `GodClassRule` skips classes by its own
applicability check; `ComplexityRule` can disable an individual level;
aggregate and graph rules must state whether their input was complete.

The coverage key is the **channel** — `ruleName` plus `violationCode` (§5.1).
One rule class can emit several, and `LayerViolationRule` emits five, four of
them under rule names no class declares as its own. Indexing by rule name alone
loses that granularity; indexing by rule class cannot see those four at all.

**What coverage decides at 8.0.** It no longer licenses a resolution — nothing
does except a reading. It answers whether a reading may be *trusted*: an
incomplete scope makes the reader `not answerable`, and the entry is
`unobserved`. The direction of the error is therefore harmless in a way it was
not at 7.9, where "evaluated" plus "absent" equalled "fixed" and a coverage bug
deleted entries. A coverage bug now costs a retained entry.

**The deviation list must be re-derived against the reader, and round 8 found
that it had not been.** 7.9's confirmed cases included `GodClassRule` skipping
classes by its own applicability check — but at 8.0 that is an *eligibility
gate*, and §5.6 requires the reader to read past gates, with a P1b Definition of
Done item pinning that switching on `excludeReadonly` must not change a reading.
Both cannot hold: if the skip reaches coverage, §7.1 step 4 makes the entry
`unobserved` and the DoD item is unobservable through the comparator; and since
`excludeReadonly` defaults to `true`, every affected entry would freeze as
`unobserved` for ever — never resolvable, and never build-failing either, so
growth on those symbols stops being reported.

The line, stated once: **a deviation belongs here only when it makes the
measurement untrustworthy, never when a rule merely declined to judge a symbol
whose metrics are intact.** Parse failure, worker failure, an incomplete
aggregate or graph input, and a disabled level are deviations. An applicability
filter is not: `GodClassRule::isExcluded()` reads `STRUCTURE_IS_READONLY` and
`STRUCTURE_METHOD_COUNT` out of the metric bag, so the metrics survive the gate
— which is exactly why the reader can read past it.

### 5.6 Three suppression categories, and what each decides now

7.0's two-way split contradicted the implementation (§2.5). The corrected
taxonomy survives the inversion unchanged **as a description of the code**;
what changes is what hangs off it.

| Category                       | Mechanisms                                                                                                                                              | Can the reader measure it? | Outcome                     | Counts toward exit | May mutate entry       |
| ------------------------------ | ------------------------------------------------------------------------------------------------------------------------------------------------------- | -------------------------- | --------------------------- | ------------------ | ---------------------- |
| **Not evaluated**              | discovery `exclude`, `@generated` stripping, `disabled_rules`, `only_rules`, `rules.<name>.enabled: false`, parse failure, worker failure, interruption | no — nothing was computed  | `unobserved`                | no                 | no                     |
| **Area silenced by config**    | `exclude_paths`, `exclude_namespaces`, per-rule exclusions, architecture `exclude:` / `allow:` / `relations:` blocks                                    | yes — the metrics exist    | the ordinary outcome status | **no**             | only on positive proof |
| **Finding silenced by author** | `@qmx-ignore`, `@qmx-ignore-file`, `@qmx-ignore-next-line`                                                                                              | yes                        | the ordinary outcome status | **no**             | only on positive proof |

**Rule applicability filters left this table at 8.0** — `GodClassRule`'s
`minMethods` / `excludeReadonly`, the LCOM and Maintainability preconditions.
They are eligibility gates, they read metrics that survive them, and the reader
reads past them (§5.5). A gate-excluded symbol is measured; only the rule
declined to judge it, and at 8.0 that silences nothing.

Two mechanisms 7.9 listed here have left the table, and both departures are
consequences of the inversion rather than corrections:

- **Magnitude cutoffs** (`maxCycleSize`) are not silencing any more. The reader
  for that channel reads the cycle set recorded *before* the cutoff (§7.4), so
  growth past the band is measured, not hidden.
- **Occurrence allow-lists** are not silencing either — they shape which
  occurrences exist at all, which the reader reproduces rather than ignores.
  The paragraph after next states that boundary, because it is the one place
  where "read ahead of the rule's policy" would be wrong.

#### Silencing decides visibility and exit — never comparison, never resolution

This is the whole of the change, and it is what §17.3 means by the query
dropping "from safety-critical to reporting-only".

The status is computed from the measurement in every case. Silencing then
qualifies it: a silenced entry is reported as silenced, and a `regressed`
silenced entry does not fail the build. It is an **attribute**, not a status —
`suppressed` leaves §7.1's precedence entirely (§0.10), for the same reason
§17.5 B gives for preferring an attribute to a status: the state it describes is
orthogonal to the outcome, and a status can only carry one of the two.

The error directions are now bounded on both sides, which is what makes the
enumeration a quality problem rather than a correctness one. A false "not
silenced" fails a build the user expected to pass — visible and immediately
diagnosable. A false "silenced" hides one regression until the exclusion is
lifted. Neither deletes anything, and at 7.9 both did: `suppressed` outranked
`resolved`, so the same query decided whether an entry survived.

Two consequences worth stating rather than leaving to be derived:

- **P2's normative enumeration is no longer the harder half of that package.**
  It remains a Definition of Done item, because an unclassified mechanism is
  still a reporting defect and the reader is entitled to know which entries the
  user silenced. It is no longer a mechanism by which a mistake destroys data,
  and it must not be scheduled as though it were.
- **A silenced entry may now be removed on proof.** 7.9 forbade mutating one at
  all, on the reasoning that resolving inside a silenced area is circular —
  silence explains itself. Under measurement it is not circular: the reading is
  taken from metrics the exclusion never touched, so it is as sound inside an
  excluded path as outside it. Refusing to act on it would leave a permanent
  sediment of dead entries in exactly the areas a user has stopped watching.

#### The one thing the reader must *not* read ahead of

§17.2 lists what the reader reads ahead of: thresholds, eligibility gates, band
cutoffs and exclusion filters. **Every path by which a rule discards a candidate
occurrence before constructing a violation for it** is deliberately not on that
list, and an implementer generalising the sentence will break the design in a
way that surfaces as noise on every run.

Such a path decides which occurrences *exist*, not which of the existing ones
are reported. A reader ignoring one would count occurrences the rule never
captured, disagree with the observation on every firing finding — tripping
§5.2's self-check as a hard error — and report a permanent phantom regression
for every bucket-counted entry. So the reader applies the same filtering the
rule applies, and the risk that a *change* to that filtering looks like a repair
is carried where every other measurement-input change is carried: by the
configuration fingerprint (§5.7).

**Phrased as a behaviour, not as an interface — and 8.0 had to learn this
twice.** The obvious shorthand is "the rule's allow-list", meaning
`AbstractCodeSmellRule` skipping entries through `EntryFilteringOptionsInterface`.
That names one of two paths in a single rule: `BooleanArgumentRule` overrides
`shouldIncludeEntry()` a second time and drops promoted-property occurrences
unless `flag_promoted_properties` is set, independently of that interface, in
the same method, before any violation is built. Both defaults are live —
`allowedPrefixes` is non-empty and `flagPromotedProperties` is `false` — so an
ordinary PHP 8 class with a promoted boolean constructor parameter beside a
plain boolean argument makes a rule that fires for one occurrence and a
DoD-satisfying reader that counts two. Under §9.3 that is not a miscount but an
aborted run.

Revision 7.9 had already been corrected on exactly this point and phrased the
rule as *any path by which a rule discards a measured entry*; the 8.0 rewrite
lost the generalisation and reverted to naming the mechanism. It is restored
here, and P1b's Definition of Done tests both paths in one fixture.

The general rule, stated once so it can be applied to the next mechanism nobody
has met yet: **the reader ignores what decides whether a finding is reported,
and reproduces what decides whether a measurement exists.** Eligibility gates
sit on the first side despite looking like the second — a gate-excluded symbol
has no entry to compare, so ignoring the gate costs nothing and buys proof
against the gate being switched on.

#### What remains of the run-scoped silencing query

Reduced to its central half. The configuration knows the paths, the namespaces,
the per-rule exclusion blocks and the architecture blocks, and the run knows the
class facts the architecture blocks are decided against — which is why the query
is run-scoped rather than configuration-scoped.

**It annotates every entry in the second category except one family, and the
exception is stated rather than discovered.** `LayerPolicy::isAllowed()` needs
the source layer, the target layer and the dependency type of a *specific edge*.
That triple lives on the current run's `Violation`, never on the persisted
entry, and `architecture.layer-violation` is one of the six channels with no
reader — so once the edge stops firing there is nothing left to feed the query,
and "silenced by a new `allow:` entry" is indistinguishable from "the edge is
gone". Those entries are `unobserved` either way, which is the correct outcome;
what is unobtainable is the *explanation*. The report says so instead of
guessing. Extending the deferred snapshot of §14.10 to carry edge identity would
close it, and is listed there rather than here.

Two subtleties survive from 7.9 because they are facts about the code rather
than about the design:

- `RuleExecutor` applies per-rule exclusions under the **executing class's own
  name**, not under the `ruleName` of the violation it drops, so
  `rules.architecture.layer-violation.exclude_namespaces` silences all five of
  that class's channels while configuration written under a diagnostic's own
  name never fires. The query must reproduce that keying, which means resolving
  channel → declaring rule through the registry.
- `PathExclusionFilter` and `NamespaceExclusionFilter` both pass
  `architecture.*` violations through **unconditionally**, so for those channels
  the global exclusions silence nothing at all.

Both were load-bearing at 7.9 and are now accuracy-of-reporting items. They are
kept because getting them wrong still produces a build that fails where the user
expected silence, and because rediscovering them costs more than reading them.

**The rule-side sparse silencing report is deferred, not deleted.** 7.9 required
every rule holding an allow-list to report what it silenced, addressed by the
observation's own identity — an obligation that existed because `resolved` was
otherwise unreachable for those channels. It is unreachable no longer: the
reader measures them directly. What the report would still buy is one line of
explanation in the output, so it is recorded in §14.8 as an optional
enhancement, and P1b no longer owns it.

**The author-tag half stays inside Baseline.** `@qmx-ignore` records are
extracted during collection, reach `AnalysisResult::$suppressions`, and are
already consumed by `src/Baseline/Suppression`. Baseline answers that half
itself and takes the silenced answer if either half gives one; the report names
which half decided, since "silenced by configuration" and "silenced by an author
tag in this file" call for different actions. Where the entry carries no line
and the tag is line-addressed, Baseline answers conservatively — a matching tag
anywhere in the entry's file silences the entry. At 7.9 that conservatism
protected an entry from deletion; now it only widens the set of entries reported
as silenced, and §14.8 is downgraded accordingly.

Implementation note, and an explicit scope item rather than a description of
today's behaviour: comparison and generation both consume the violation list
**after** evaluation-exclusion and **before** presentation-suppression.
`ViolationFilterPipeline` currently applies the baseline first, so P2/P4 must
reorder it. That ordering is what lets a finding inside `exclude_paths` still
reach the comparator — which is necessary, because for a reader-less channel the
finding is the only measurement there is (§5.2). P2's Definition of Done owns
the coverage gate; P4 owns the pipeline order.


### 5.7 Comparison uses the current rule contract

An independent contract registry carries, for each **(rule, violation code)**
pair, its current contract id, version, kind, axis names, directions, and
epsilon. It is populated from the rules and their configuration, and does
**not** depend on a violation being emitted; without it, a forgotten version
bump on a now-passing symbol would read as `resolved` instead of
`incompatible`.

"At boot" is too early to state as the population point: computed-metric
contracts are not static rule metadata. Their names, formulas, levels,
thresholds, and `inverted` flags come from user YAML and reach the rule through
`ComputedMetricDefinitionHolder`, so the registry must be populated **after the
configuration pipeline has run**, and a registry built from static rule
reflection alone would be blind to every user-defined metric.

The file's contract manifest (§6.1) is compared against this registry. A
mismatch produces `incompatible` and never falls back to suppression.

#### The registry contract (8.0)

Shape only; population stays P1b's (rules declare) and configuration's
(computed metrics, above). Three types, each justified by a consumer that
cannot be written without it:

| Type                       | Carries                                                                                                                                   | Consumer that needs it                                                                                                       |
| -------------------------- | ----------------------------------------------------------------------------------------------------------------------------------------- | ---------------------------------------------------------------------------------------------------------------------------- |
| `ChannelContract`          | the channel, its `ContractReference`, its declared `ObservationKind`, its axis declarations, its onset source, and its reader declaration | P3 compares the first four against the file manifest (§6.1); a difference at equal version is `incompatible`                 |
| `AxisContract`             | axis name, worse direction, epsilon, and the declared onset comparison (inclusive / exclusive / not applicable)                           | the manifest stores exactly `worse` and `epsilon`; the comparison decides which side of an inclusive boundary counts as debt |
| `ChannelRegistryInterface` | which declaration covers this channel — an enumerated one, an open-ended one, or none — and whether it is active in this run              | §7.4's `orphaned` test (no declaration) and §7.1's activity gate (declared but inactive → `unobserved`)                      |

`ChannelDeclaringInterface` — the rule-side source, listing the channels a rule
can emit with their contracts — is the fourth type and stays as 7.9 specified
it: an opt-in interface rather than a method on `RuleInterface`, so the contract
package lands green and the breakage stays in P1b. At 8.0 it also carries the
rule's **reader declaration** per channel: a reader, or an explicit statement
that this channel has none (§5.2). "Neither" is a hard error at registration —
the failure this design cannot tolerate is a channel silently having no reader
and being read as resolvable.

**A declaration is either enumerated or open-ended, and `orphaned` reads that
distinction.** §7.4 draws the `orphaned` / `unobserved` line at the build rather
than at the configuration, and `orphaned` is the single class of entry
`cleanup --prune-orphaned` may delete — so getting it wrong deletes real debt.
But this registry is populated *after* the configuration pipeline, precisely so
that user-defined computed metrics are visible. A registry that only knew the
channels present after configuration would mark every `computed.*` entry
`orphaned` the moment its definition left `qmx.yaml`, and prune it — even though
putting the line back would restore it exactly.

Splitting this into "declared by the build" and "active in this run" was the
first attempt and it does not survive contact with `ComputedMetricRule`, which
cannot name its channels except from configuration. So the declaration itself
carries the distinction:

- an **enumerated** declaration names concrete channels — the ordinary case,
  including the four `architecture.*` diagnostics whose rule names no class
  declares as its own;
- an **open-ended** declaration names a rule name whose violation codes come
  from configuration, without enumerating them. `ComputedMetricRule` declares
  `computed.health` this way.

`orphaned` then means: the entry's channel matches no enumerated declaration
**and** no open-ended one. A deleted or renamed rule class removes both, which
is exactly the "no configuration change will ever restore it" case §7.4
reserves for `orphaned`. The test remains against declared channels, never
against rule class names.

**Activity gates the reader, and that is why it is still needed at 8.0.** A
reader can answer perfectly well for a channel whose rule the user has switched
off: disabling `complexity.cyclomatic` does not stop `ccn` being computed. Left
ungated, the entry would compare normally and a `regressed` reading would fail
the build for a rule the user deliberately disabled. So an inactive channel is
`unobserved` before any reading is attempted — the same answer as at 7.9, for a
different reason, and recoverable by putting the configuration back.

The failure mode that made this urgent at 7.9 is gone: a removed computed-metric
definition no longer sails through to `resolved`, because the metric it named is
absent from the repository and the reader answers `not answerable`. The gate now
prevents a spurious *failure*, not a deletion. It is kept because that failure is
otherwise certain, and because the three operations that reach it —
`--exclude-health=<dim>`, `enabled: false` on a computed metric, and removing its
thresholds from YAML — are all routine.

**Activity has two producers, and they union.** The registry is not the only
thing that can say a channel went unobserved: §5.5's sparse coverage deviations
already carry the case of a rule disabling one of its own levels
(`ComplexityRule` does this per level). The two are owned by different packages
— the registry by P1b, coverage by P2 — so the rule is stated rather than left
to fall out: **any source saying "not observed in this run" wins**, and a channel
is treated as observed only when neither says otherwise.

The channel-level traits collapse to **one**, and the deletion is as important
as what remains:

| Trait        | Values                                                                            | Why comparison needs it                                                         |
| ------------ | --------------------------------------------------------------------------------- | ------------------------------------------------------------------------------- |
| Onset source | none / configured tiers / symbol-conditioned / run-conditioned / collection-gated | it tells the onset provider how to obtain the boundary that feeds the allowance |

Firing predicate, band and coverage scope are gone (§0.10). Each existed to
tell the comparator which inference was safe for that channel, and there are no
inferences left to guard: a compound predicate that stops firing, a cutoff that
hides a cycle, and a symbol whose coverage is channel-wide are all answered by
reading the axes. Deleting them is not a simplification for its own sake — every
one of the three was the subject of a HIGH finding in rounds five to seven,
because a trait keyed on a proxy meets members where the proxy and the property
diverge.

**`collection-gated` survives as an onset source**, for
`duplication.code-duplication`. Blocks below `min_lines` / `min_tokens` are
never detected — `DuplicationDetector` drops them during Collection, before any
rule runs — so the channel's onset is `(min_lines, min_tokens)` and the provider
reads the same options the detector reads. At 8.0 that feeds the allowance only,
and the channel has no reader at all, so raising `min_lines` cannot resolve
anything by any path. P1b assigns this trait value per channel from the
inventory rather than by analogy.

#### The configuration fingerprint (§17.5 E)

Configuration can move the measurement instead of the boundary. The reader is
independent of the rule's policy but not of the collector's (§5.2, §17.7 H), and
the counterexamples are not exotic:

- `coupling.framework_namespaces` — under `scope: application`, `CboRule` reads
  a metric the collector computed *while excluding* the configured framework
  namespaces. Adding one namespace drops CBO with not one dependency removed.
- `exclude_health` and per-metric `enabled: false` — excluding a dimension
  renormalises the weights of `health.overall`, so the score rises on its own.
- A rule's own allow-list decides which occurrences exist (§5.6).

So: **the file records a fingerprint of the provenance its measurements were
taken under, and while it differs from the one this run computes, no entry it
covers may be reported `fixed`.** Everything
else proceeds — the allowance comparison, `regressed`, `improved`, `matched` —
because withholding those would flood a routine edit with diagnostics and teach
users to ignore them (§14.9). What is withheld is exactly the outcome that
deletes data.

**It is a fingerprint of the measurement's provenance, not of "the
configuration".** Round 8 established that the loose phrasing is unimplementable
here: the object this codebase calls a resolved configuration
(`ResolvedConfiguration`) carries `appliedSources` and `deferredWarnings` — pure
provenance of the configuration itself, affecting no metric — and its
`AnalysisConfiguration` carries `format`, `workers`, `memoryLimit`,
`cacheEnabled`, plus absolute `projectRoot` and `cacheDir` seeded from the
working directory. A baseline generated in a developer's checkout and checked in
CI would differ on every run, `fixed` would be unreachable by construction, and
the pressure would go straight to `generate --force` — the outcome the
fingerprint exists to avoid.

So the digest is taken over an **explicit, named projection**, and the plan
states its boundary rather than its members, because the member list will grow:

- **in** — everything that can change what a collector or a rule *measures*:
  rule options including thresholds, `coupling.framework_namespaces`,
  `exclude_health` and computed-metric definitions, duplication's `min_lines` /
  `min_tokens`, discovery `exclude`, the namespace strategy and aggregation
  prefixes, and every rule-side filtering list of §5.6;
- **out** — everything that cannot: configuration provenance
  (`appliedSources`, `deferredWarnings`), presentation and runtime settings
  (`format`, `workers`, `memoryLimit`, `cacheEnabled`, `failOn`), and every
  absolute path derived from the working directory;
- **plus the analyser build.** §10 explicitly permits changing how a metric is
  computed without bumping any contract, so an upgrade can move every reading
  with the configuration untouched — and this repository has exactly such a
  change queued for the project-level coupling formula. Without this component
  the next release deletes the debt it moved. The cost is stated plainly: an
  upgrade suspends automatic `fixed` until the baseline is regenerated, which is
  the honest consequence of numbers that may have moved.

Thresholds stay **in** even though 8.0 makes them provably unable to produce a
false `fixed` (§7.1 decides repair against the stored onset). Keeping them is
§17.5 E's decision, taken deliberately with its cost; they are the obvious first
candidate should the projection ever be narrowed.

Two mechanical constraints:

- **It is taken after defaults, presets and CLI overrides, with canonically
  ordered keys**, so two spellings of the same effective configuration digest
  identically.
- **Its algorithm is the file's pinned `hash_algorithm`** (§6.1). A
  feature-detected digest is the portability defect §6.1 already refuses
  elsewhere.

**The field is a map, not a scalar** (§6.1). A single string cannot express the
per-channel projection offered below: under a scalar field one channel's changed
inputs still move the one stored value and still block `fixed` project-wide, so
the opt-in would change which inputs are hashed and nothing about reachability.
The default writes one entry under a `global` key; the opt-in writes one entry
per channel. Readers of the file compare only the entries that are present.

**Who computes it, and when.** The projection is a function of the resolved
configuration, so it belongs to P4a, which owns `src/Configuration/**` — but P3
compares it and P3 lands *before* P4a. Round 8 caught the resulting gap: the
consumer's Definition of Done required a value whose only producer arrives two
packages later, with no contract between them, which is §0.8's failure repeated
one package on. So the **contract** — the fingerprint value type and the
interface that supplies it — is P1a′'s, alongside the other four; P4a implements
it; P3 consumes the interface and is testable against a stub from day one.

**The cost is real and is accepted with one reservation.** This repository tunes
thresholds continuously, and under a global fingerprint every `qmx.yaml` edit
suspends automatic `fixed` project-wide until the baseline is regenerated. A
`cleanup` that rarely acts pushes users toward `generate --force`, which
re-accepts the entire debt at once — a worse outcome than the risk it avoids. So
a **per-channel projection of the configuration is offered as an explicit
opt-in**, documented as less safe, with the global fingerprint in force unless
chosen. §17.5 E records this as the one decision taken without confidence;
revisit it once real usage exists.

One observation for that revisit, recorded because it is cheap now and
expensive to reconstruct: under 8.0 a **threshold-only** edit is provably unable
to produce a false `fixed`, since repair is decided against the onset stored in
the entry and never against the current one. Thresholds are therefore the
safest possible narrowing of the projection, and the first thing to try before
reaching for per-channel granularity. This is an observation, not a decision.

**The kind is stored, not derived.** Deriving it from (identity, magnitude)
was checked against all 52 inventory rows and fails twice over: it has no
branch for `architecture.coverage`, and it maps `duplication.code-duplication`
— whose line count genuinely drives its severity — onto the same value as the
eighteen occurrence channels that carry no magnitude at all.

**Four of §5.1's seven inventory dimensions are stored nowhere**, and the
enumeration that settled it is worth recording, because they look like
omissions:

- **Magnitude** describes what each rule puts in `Violation::metricValue`
  *today*, which §2.3 disqualifies as a debt contract and which v7 exists to
  replace. What the observation carries is its axes, and the axis declarations
  state that directly.
- **Identity** is not total over the rule set: `architecture.coverage` emits one
  aggregated project-level violation per run and answers none of `symbol` /
  `occurrence` / `graph`. What consumers need from it is carried by the
  `ObservationKind` and by the presence of an `OccurrenceKey`.
- **Firing predicate** and **band** were stored at 7.9 and are deleted at 8.0,
  per the trait table above.

**What is versioned and what is not**, because `ChannelContract` carries both
and the two behave oppositely:

- The **comparable shape** — kind, axis names, directions, epsilon, and the
  declared onset comparison — is the contract. A change to any of it requires a
  version bump, and a difference at equal version is `incompatible`. The
  comparison is in the manifest because an operator moved from inclusive to
  exclusive shifts which values count as debt without changing any number, and
  both operators are in live use across the rule set — higher-is-worse channels
  compare inclusively (`ComplexityOptions`), lower-is-worse ones strictly
  (`MaintainabilityOptions`).
- The **onset source and the reader declaration** are properties of the current
  run, not of the contract. They never bump the version and never produce
  `incompatible`. A channel that gains a reader is still comparable against
  entries captured before it had one; what changes is which entries can be
  proven repaired. Stating this both ways round is deliberate: an implementer
  who guesses the other way turns a purely behavioural change into a mass
  `incompatible` across every entry of the channel.

#### The onset provider contract (8.0)

The provider survives, narrowed to one consumer. §5.1 uses it to compute the
allowance and nothing else uses it at all — the reason test that consumed it at
7.9 is gone, and with it the four-valued answer that had to distinguish "no
boundary" from "a mode rather than a number" from "the symbol is gone".

Both halves live in `Core/Channel/` beside the registry: the onset is what a
channel applies to a symbol, so it is the channel's subject. The split into two
interfaces is forced by lifetime rather than by taste:

- **Rule-side.** Given a channel, a symbol, and the analysis context, answer
  with the current onset per axis. The context parameter is required —
  `ClassRankRule` scales its thresholds by the project's class count, and
  `LongParameterListRule` picks its boundary from the symbol's own metrics, so
  neither is answerable from the channel alone.
- **Run-scoped.** The same question without a context parameter, for consumers
  that run after rule execution. `AnalysisContext` is not carried on
  `AnalysisResult` (§2.8), so a comparator running in the filter pipeline has no
  context to pass.

**The answer is two-valued**: a numeric onset per axis, or none. "None" covers
every case the fourth value used to carry — a channel with no boundary
(`code-smell.goto`), a boundary that is a configured mode rather than a number
(`architecture.coverage`), and a symbol whose metrics are absent. All three mean
the same thing to the only remaining consumer: **the allowance is the captured
value**, and no widening applies.

Three onset sources remain forbidden, each because a revision of this plan used
one and was wrong: `Violation::threshold` (it is the tier the measurement landed
in, so the allowance would widen as the code worsened, §2.4); the field
literally named `warning` (`ComputedMetricRule` tests `errorThreshold` first and
nothing validates tier ordering, so `warning: 20, error: 10` starts violating at
10); and any value keyed by channel alone (the onset can depend on the symbol or
on the run).

Two consequences of the narrowing, both worth stating because they change how
this contract should be tested and reviewed:

- **An error here is now visible rather than destructive.** A wrong onset
  produces a wrong allowance, which reports a regression that is not one or
  misses one that is. Both surface in the next run's output. Nothing is deleted.
- **`architecture.coverage` loses its opaque mode token** (§6.2 at 7.9). It was
  stored so the reason test could compare `error` → `warn` → `ignore` for
  permissiveness. Nothing compares it now, and the channel has no reader, so its
  entries ratchet while the diagnostic fires and are `unobserved` when it stops.
  Its unmatched-end count is still declared as an axis — otherwise the channel
  sits outside the ratchet entirely and a project going from three unclassified
  classes to a hundred reports `matched`.


### 5.8 Vector comparison is strict Pareto ratcheting

A vector finding is not worse only when no axis exceeded its allowance beyond
epsilon. Any worsened axis is `regressed`; improvements elsewhere never
compensate.

- A **contract shape change** — the registry's axis set differs from the
  manifest's — is `incompatible`.
- A **null axis value** is not a shape change. An axis whose metric is
  unavailable is skipped in comparison; a transition between null and numeric is
  recorded and reported, never treated as improvement or regression. This
  applies identically to a null the reader returns and a null the rule observed:
  the axis exists, the metric does not.
- NaN and infinity are invalid as observations and as serialised values.

**A contract's axis set never tracks which criteria were evaluable** (moved here
from §5.9 at 8.0). Where a rule scores several criteria and some are vetoed by
the values of others — `GodClassRule` vetoes its LCOM criterion when TCC ≥ 0.5 —
the axes are the **raw underlying metrics**, fixed by the contract, with
unavailable ones null. Axes that came and went with evaluability would make a
legitimate cohesion improvement a shape change, and report `incompatible` for
code that got better. The rule's own scoring is not a contract at all: nothing
stores `matchedCount`.

A vector entry needs no reader of its own: its axes are separate metric reads,
and §5.2's protocol answers per axis. An axis the reader cannot answer is null,
which by the second bullet is skipped — so a partially readable vector compares
on the axes it has, and the entry can still be `regressed` on one of them. What
it cannot be is `resolved`: proof of repair requires every axis to have been
read (§7.1).

### 5.9 Compound rules — deleted at 8.0

This section required a compound channel — one whose firing predicate is a
predicate over two or more measured axes — to resolve with reason `policy` and
never `fixed`, so that `cleanup` would leave its captured axes in place. It
existed for one reason: such a predicate can stop firing while an axis worsens,
and a design that reads findings cannot tell the two apart.

A design that reads axes can. `DataClassRule`'s WOC and `GodClassRule`'s four
criteria are ordinary metrics in the repository; the reader returns them whether
or not the predicate fires, and §7.1 compares them like any other axes. Growth
that was invisible at 7.9 — WOC rising from 80 to 90 while WMC crosses its own
bound and silences the rule — is a plain `regressed` at 8.0. That case was the
section's own worked example, and it is now the argument for deleting it.

**"Like any other axes" is literal, and 8.0 initially got it wrong.** These axes
carry their own onsets, taken from the rule's own options: `wmcThreshold: 47`,
`lcomThreshold: 3`, `tccThreshold: 0.33`, `classLocThreshold: 300` for
`design.god-class`, and `wocThreshold` / `wmcThreshold` for `design.data-class`,
which an inline `@qmx-threshold` moves per axis. The plan had carried a 7.2-era
claim that compound axes have no onset at all; combined with §7.1's rule that an
onset-less axis is judged by presence, that would have made `resolved`
unreachable for both channels for ever — reinstating exactly the behaviour
§5.9's deletion is sold on.

One consequence has no precedent elsewhere in the rule set and must be declared
rather than assumed: **`design.data-class`'s two axes have opposite worse
directions.** It fires on high WOC *and low* WMC, so for this channel WMC is
lower-is-worse while WOC is higher-is-worse. The contract already carries
direction per axis (§5.3), so nothing new is needed — but a reader or a
comparator written on the assumption that a channel has one direction will
invert half of it.

Everything the section carried is subsumed:

- its axis-set rule (compound axes are the **raw underlying metrics**, fixed by
  the contract, never tracking which criteria were evaluable) moves to §5.8,
  where it belongs — it is a statement about contract stability, not about
  compound rules;
- its blanket refusal of `fixed`, and the deleted-symbol carve-out that had to
  be bolted onto that refusal at 7.9, are both gone: repair is proven by
  reading, and an absent symbol is `unobserved` for every channel alike (§5.2);
- its worry about single-metric coverage being partial — `complexity.wmc` and
  `design.lcom` exist while TCC, class LOC and WOC have no rule of their own —
  simply evaporates. The reader reads metrics, not rules. §14.1 records it.

The heading is retained as a stub because a dozen cross-references in this
document and its review history point at §5.9, and renumbering a section that
seven review rounds have cited by number is a worse defect than an empty one.


### 5.10 Writes are atomic and guarded per entry

Generate, migrate, update, cleanup, and rebase use temporary-file plus atomic
rename, with the hash algorithm pinned in the file.

The write guard is **per entry, not global**. v6 refused any write when any
required scope was unobserved; on this project's own configuration — two dozen
`exclude_paths` and `exclude_namespaces` blocks — that would refuse every
lifecycle command permanently, so the feature could not serve its own
dogfooding. Instead: an entry whose scope is not proven is carried forward
unchanged, and the command reports how many entries it left untouched and why. A
command aborts entirely only on contract-validation failure, serialisation
failure, or an unresolved migration.

Concurrency uses a real compare-and-swap: the file is locked, or its content
hash is verified inside the same critical section as the rename. A re-read
before writing is a TOCTOU window, not a guard, and does not satisfy this
requirement.

## 6. Baseline v7 File Contract

```json
{
  "version": 7,
  "mode": "ratchet",
  "generated": "2026-08-04T12:00:00+03:00",
  "hash_algorithm": "xxh3",
  "scope": ["src"],
  "config_fingerprint": { "global": "9f2c..." },
  "contracts": {
    "complexity.cyclomatic.method": {
      "version": 1,
      "kind": "scalar",
      "axes": { "ccn": { "worse": "higher", "epsilon": 0, "compare": "inclusive" } }
    }
  },
  "violations": {
    "method:App\\OrderService::calculate": [
      {
        "rule": "complexity.cyclomatic",
        "code": "complexity.cyclomatic.method",
        "contract": "complexity.cyclomatic.method",
        "occurrence_key": null,
        "axes": { "ccn": { "value": 25, "onset": 10 } },
        "occurrences": 1
      }
    ]
  }
}
```

### 6.1 Top-level fields

| Field                | Contract                                                                                                                                                                                                                                                              |
| -------------------- | --------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| `version`            | Exactly `7`                                                                                                                                                                                                                                                           |
| `mode`               | `ratchet` or `suppress`                                                                                                                                                                                                                                               |
| `generated`          | ISO 8601, from an injected clock                                                                                                                                                                                                                                      |
| `hash_algorithm`     | Explicit and pinned; names the algorithm used for occurrence keys, content hashing, and the configuration fingerprint. Never feature-detected.                                                                                                                        |
| `config_fingerprint` | Map of digests of the measurement provenance this file was captured under (§5.7, 8.0) — one `global` entry by default, one per channel under the opt-in. While an applicable entry differs from the one this run computes, no entry it covers may be reported `fixed` |
| `scope`              | The analysed path set that produced this file, normalised (7.9). See below                                                                                                                                                                                            |
| `contracts`          | Manifest: contract id → version, kind, and per axis its direction, epsilon and comparison (7.9)                                                                                                                                                                       |
| `violations`         | Canonical symbol keys → deterministic entry lists                                                                                                                                                                                                                     |

The manifest detects a forgotten version bump: if the registry's axes,
direction, epsilon, or declared comparison differ from the manifest at the same
declared version, the result is `incompatible`, not a silent miscomparison. Cost
is O(contracts).

**`scope` records what the file was captured over, and 8.0 changes why.** Every
lifecycle command takes a `<paths...>` argument, so
`bin/qmx baseline:cleanup baseline.json src/Foo` is a *complete* run with
respect to the paths given and blind to the rest of the project. At 7.9 that was
a data-destruction path: every entry outside `src/Foo` was absent from
discovery, resolved on that absence, found its onset unmoved, and was deleted.
One mistyped argument emptied the file.

That path no longer exists. Absence is not proof (§5.2), so an entry the run
never measured is `unobserved` and no command touches it — the guarantee Goal 5
asks for now comes from the comparison itself rather than from a field. This is
the clearest single demonstration of what the inversion bought, which is why the
old reasoning is left visible above rather than quietly replaced.

The field stays, with a smaller job. `cleanup` and `update` still refuse to run
when the current scope does not cover the recorded one, behind an explicit
`--force`: a run that can prove nothing about nine tenths of the file is almost
always a mistake, and reporting five hundred entries as `unobserved` is a poor
substitute for saying so. `migrate-apply`'s fingerprint of its analysis inputs is
unchanged.

`compare` is **required** on every manifest axis, and takes the literal value
`not-applicable` for a channel that has no onset boundary — the absence of a
boundary is a fact worth writing down, and an optional field would make "no
onset" and "someone forgot" identical on disk. A v7 file written before the
field existed is not a compatibility case: v7 has not shipped, and §6.2 already
requires the file to fail closed rather than guess.

The comparison is in the manifest, and at 8.0 for a different reason than 7.9
gave. 7.9 put it there because the reason test compared boundaries as numbers,
and that test is gone. It stays because it decides which values count as debt
at all: with captured onset 10 and an inclusive comparison, a reading of exactly
10 is still debt; with an exclusive one it is repaired. An operator moved
between the two changes the verdict without changing a number, and both are in
live use across the rule set. The channel traits of §5.7 are deliberately **not**
in the manifest — they describe the current run rather than the comparable
shape, and never make an entry `incompatible`.

Everything except `generated` is deterministic for the same analysis and
contracts. A no-op command preserves the existing timestamp and bytes.

### 6.2 Entry invariants

- `rule`, `code`, `contract`, `axes`, and `occurrences` are required;
  `occurrence_key` is optional and null when the rule offers no stable
  discriminator.
- Each axis carries its captured `value` (numeric or null) and the `onset`
  boundary in force when it was captured. **At 8.0 the stored onset is what
  decides repair** (§7.1): a reading no longer past it, under the manifest's
  declared comparison, is the positive proof `cleanup` acts on. It is a single
  number per axis. The *current* onset is a separate thing, queried per run, and
  is used only to widen the allowance (§5.1) — the two must not be confused, and
  an implementation that reads the stored onset for the allowance re-creates v6's
  absolute ratchet while one that reads the current onset for repair re-creates
  the defect the inversion removed.
- An axis whose channel has **no numeric onset** stores `onset: null`. Repair for
  such an axis is a reading of zero or absent (`code-smell.goto` is gone,
  the cycle is not in the recorded set) rather than a comparison — §7.1 states
  the two forms together. The opaque mode token and the per-entry `inputs`
  digest that 7.9 required here are both removed: the first had no consumer left
  and the second became the file-level `config_fingerprint` (§0.10).
- The referenced contract must exist in the manifest.
- **Every entry carries at least one measured dimension** — an axis, an
  occurrence count, or an identity presence (§7.1). An entry carrying none is
  rejected at load. This invariant is what makes §7.1's predicates total instead
  of vacuous, and its absence was the CRITICAL finding of review round 8: with
  the outcome table stated over axes alone, an axis-less entry satisfied "no axis
  is still debt" trivially and `cleanup` deleted every code-smell and security
  entry on the first run.
- Scalar entries have exactly one axis; vector entries at least two; presence
  and graph entries carry a presence dimension and may carry no axis; occurrence
  entries carry a count, an occurrence key, or both.
- Axis names are unique, deterministically sorted, and match the manifest; each
  manifest axis carries `worse`, `epsilon` and `compare`, the last possibly
  `not-applicable`.
- Entries under one symbol key sort deterministically by
  (rule, code, occurrence_key, contract).
- Numeric values are finite; `-0.0` is normalised.
- `occurrences` is a positive integer; zero-count entries are not serialised.
- Duplicate entry identities are invalid.
- The v5 `hash` field is not carried forward; identity is structural (§5.4), and
  a second identity system in one file is a defect surface.

### 6.3 `mode: suppress`

Matching identity is filtered regardless of value or count growth; contract
metadata is retained for readability and migration; unmatched identities stay
visible; the mode is never selected implicitly at runtime.

## 7. Comparison Semantics

### 7.1 Statuses and reasons

| Status         | Meaning                                                         |
| -------------- | --------------------------------------------------------------- |
| `new`          | Current violation has no baseline entry                         |
| `matched`      | The reading is within the allowance and no better than captured |
| `improved`     | Within the allowance, better than captured, still debt          |
| `regressed`    | At least one axis or the count is past its allowance            |
| `resolved`     | The reading proves the captured debt is gone                    |
| `unobserved`   | No reading can be trusted for this entry                        |
| `orphaned`     | The entry's channel is declared by no rule in this build        |
| `incompatible` | Contracts cannot be compared                                    |

`suppressed` is **not** in this list at 8.0. Silencing no longer preempts an
outcome; it qualifies one (§5.6).

#### The measured dimensions of an entry

Every predicate below is stated over an entry's **measured dimensions**, never
over its axes. This distinction is what makes the procedure total, and getting
it wrong was the CRITICAL finding of review round 8: a table written over axes
reads "no axis is still debt" as *true* for an entry that has no axes, so every
code-smell and security entry resolved on its first run and `cleanup` deleted it
while the smell sat in the file.

An entry's dimensions are:

- each **axis** it declares;
- its **occurrence count**, where it carries one;
- its **identity presence**, where its kind is Presence or Graph.

§6.2 requires at least one. An entry carrying none is rejected at load, not
decided.

#### Where the measurement comes from

Exactly one of two sources, in this order:

1. **The finding, if the rule reported it this run.** Its observation is the
   measurement (§5.2). The rule is the authoritative party for the axes it
   computes.
2. **The reader, otherwise.** It re-reads the same dimensions ahead of the
   rule's policy, and answers with values or with `not answerable`.

Nothing else may decide an outcome — in particular, not the absence of a
finding, which is what every defect in §17.1 had in common.

#### Precedence

The statuses are **ordered**, and exactly one applies to an entry. An entry can
simultaneously belong to a removed rule, reference a changed contract, and sit
in an unanalysed path. Evaluate in this order and stop at the first match:

1. `orphaned` — the channel is declared by no rule in this build, so nothing
   else can be computed about the entry.
2. `incompatible` — the manifest and the registry **both** carry a contract for
   this channel and they disagree at an equal declared version. Decided from the
   two declarations alone, before any reading is attempted: asking a reader for
   axes that no longer mean what they meant is not a question with a right
   answer.

   **A registry with nothing to say is not a disagreement.** An open-ended
   declaration whose configuration is gone — a deleted `computed_metrics` entry,
   `--exclude-health=<dim>` — leaves a declaration standing and no contract
   under it. That is the activity gate's case at step 4, not this one. Round 8
   found the opposite reading reachable and destructive: the entry would land on
   `incompatible`, never reach the gate, and `rebase-contracts` **removes** an
   `incompatible` entry whose rule emits nothing (§8), so restoring the YAML
   line would no longer restore the captured debt. (7.9 ordered `incompatible`
   after `unobserved`, which hid this; the swap is §0.10's derived change 5, and
   this paragraph is the part of it that was missing.)
3. **Establish the measurement** per the two sources above.
4. `unobserved` — no measurement was established. Four disjoint causes, and the
   reported reason names which: the channel is declared but inactive in this run
   (§5.7); the channel declares no reader (§5.2); the reader answered `not
   answerable`; or the input the measurement itself depends on was incomplete
   (§5.5).
5. The outcome.

**Step 3 sits ahead of step 4 deliberately**, and round 8 found the cost of the
7.9 ordering it replaces. Only one of step 4's causes used to be guarded by the
absence of a finding, so an entry whose rule had just reported a worsened
finding could be classified `unobserved` because *some other* scope in an
aggregate channel was incomplete — and §9.3 makes `unobserved` a diagnostic, so
a real regression stopped failing the build. A trust gate may only run when
there is nothing authoritative to trust.

#### The outcome

Two predicates, evaluated per dimension:

- **past the allowance** — worse than `allowance(dimension)` by more than
  epsilon, in its worse direction (§5.1, §7.2). Presence has no ordering and is
  therefore never past its allowance; a new identity is `new`, not a regression.
- **still debt** — per dimension:

| Dimension                          | Still debt when                                                    |
| ---------------------------------- | ------------------------------------------------------------------ |
| axis with a numeric captured onset | the reading is past that onset, under the manifest's `compare`     |
| axis with no numeric onset         | the reading is non-zero (`architecture.coverage`'s unmatched ends) |
| occurrence count                   | the count is above zero                                            |
| identity presence                  | the identity is in the reader's enumeration                        |

An entry is **still debt** when any of its dimensions is, **or when a finding
fired for it this run**.

| Condition                                                        | Status      |
| ---------------------------------------------------------------- | ----------- |
| any dimension past its allowance                                 | `regressed` |
| the entry is not still debt, and every dimension was read        | `resolved`  |
| some dimension better than captured and none worse than captured | `improved`  |
| otherwise                                                        | `matched`   |

`new` is not in this table or in the precedence: it applies to a current finding
with no baseline entry at all, which is the one case where there is nothing to
compare against.

Three parts of this deserve their reason stated, because each closes a round-8
finding and each reads as redundant until it is removed:

- **The firing-finding disjunct.** Repair is decided against the captured onset,
  so when the *current* onset is tighter than the captured one a reading can sit
  below the captured onset while the rule is still reporting the finding —
  reachable by tightening a threshold, by deleting an inline `@qmx-threshold`,
  by `LongParameterListRule`'s VO branch, or by `ClassRankRule`'s run-scaled
  boundary. Without the disjunct the entry reads `resolved` while a live
  violation of the same identity is reported in the same run, and nothing says
  which of the two wins. With it, **`resolved` implies no current finding**,
  which is what §8 and §9 assume throughout.
- **"every dimension was read".** A partially readable entry cannot prove
  repair. It falls through to `improved` or `matched` and is retained; §5.8 said
  so and §7.1 did not, which left a `resolved` entry with no admissible
  `resolutionReason` (below, `fixed` requires every dimension read and
  `unproven` has one unrelated producer).
- **The order of the first two rows.** They cannot collide: an entry exists only
  because its captured value was past its captured onset, and the allowance is
  never stricter than the captured value, so *past the allowance* implies *still
  debt*. For a vector, one dimension past its allowance outranks another
  dimension clearing — any worsened dimension is a regression and improvements
  never compensate (§5.8).

**Repair is decided against the captured onset, never the current one.** This is
the whole inversion in one line, and the place an implementer will be tempted to
save a field. Reading the *current* onset here would resolve an entry the moment
a threshold was relaxed, which is mechanism one of the nine (§17.1) and the
error direction that deletes debt. Reading the captured onset asks the only
question that admits a positive answer: *is the measurement that was accepted
still there?*

#### Reasons and attributes

One reason and two attributes qualify a status rather than multiplying the list.

**`resolutionReason`** — `fixed` or `unproven`, on `resolved` only:

- `fixed` — every dimension was read, none is still debt, no finding fired, and
  the file's `config_fingerprint` matches the one this run computes. `cleanup`
  removes these and only these.
- `unproven` — the reading says the debt is gone, but the measurement's
  provenance is not the provenance it was captured under (§5.7), so the drop is
  not attributable to repair. Reported, retained, never cleaned. Regenerating
  the baseline, or restoring the configuration, is what clears it.

`unproven` replaces 7.9's `policy`, and the rename is deliberate rather than
cosmetic: `policy` meant "the boundary moved", a case that no longer produces
`resolved` at all, and an implementer carrying the old meaning across would look
for a boundary comparison that is not there.

**`withinWidenedPolicy`** — set when the status came from a reading with no
current finding (§17.5 B). It qualifies `matched`, `improved` and `regressed`,
and is the single attribute that tells a reader why the ratchet is talking about
something `qmx check` is silent on. Revision 7.3 removed this attribute as
unreachable; under measurement it is reachable and means what it was introduced
to mean.

It does **not** qualify `resolved`: "no rule reports this and the debt is still
there" is false of an entry whose reading proves the debt gone, and a `resolved`
entry never has a current finding anyway, so the attribute would be constant and
misleading there.

The name is slightly wider than its literal reading, which is deliberate: the
policy in force for that symbol may have widened by a raised threshold, by a
compound predicate that stopped firing, by a band cutoff, or by an eligibility
gate switching on. All four are the same fact to the user — *the tool is no
longer reporting this, and the debt is still there* — and splitting them into
four attributes would report the mechanism instead of the fact.

The canonical case §14.4 was written for is now visible rather than lost:
captured 10, onset raised to 20, reading 15 — inside the allowance, so
`matched`; worse than captured, so worth showing; reported with the attribute
and with both numbers.

**`silenced`** — `config` or `author-tag`, from §5.6. It suppresses the exit
failure and annotates the report. It never changes the status.

`regressed` findings are reported as violations of their rule's severity, and
carry a stable `baseline-regression` reason code in machine output. **Where no
finding fired, the severity is the tier the current reading falls into**,
computed from the onset provider, which already knows the tiers; for a channel
with no tiers it is that channel's declared fixed severity. The file stores no
severity (§5.4 excludes it from identity deliberately) and three machine formats
in §9.2 require a level, so leaving this to the implementer would produce three
different answers.

#### Reachability

7.9 needed a page here, because which statuses could occur depended on where the
onset sat relative to the captured value, and two revisions shipped states that
read as correct and could never happen. That analysis is gone: a reading is
available whenever a reader is, independently of policy, so every outcome status
is reachable for every channel that has a reader.

Two reachability facts do survive, and §13 asserts both directly rather than
leaving them to be derived:

- For a channel with **no reader**, `resolved` is unreachable by construction,
  and that is the guarantee's declared partiality (§17.4), not a defect. Six
  channels are in this position, enumerated in §5.2.
- `improved` requires a reading strictly better than captured while still past
  the captured onset, so it is unreachable for any **entry whose only dimension
  is a presence** — there is nothing between "present" and "gone", and
  `resolved` follows directly from `matched`. This is a property of the entry,
  not of the channel: an entry of a magnitude-free channel reads as a presence
  only when it has a stable occurrence key. Without one it carries a bucket
  count (§5.4), and a count of five falling to three is an ordinary `improved`.
  An implementer who writes the `resolved` branch only for axes has written the
  CRITICAL defect of round 8 back into the design.


### 7.2 Scalar and vector

- Higher-is-worse: `current > allowance + epsilon` is worse.
- Lower-is-worse: `current < allowance - epsilon` is worse.
- Vector: any worse axis is `regressed`; at least one better and none worse is
  `improved`; a registry/manifest shape change is `incompatible`; a null axis is
  skipped (§5.8).
- The **still-debt** test uses the captured onset with the manifest's declared
  `compare`: inclusive means the boundary value itself is debt, exclusive means
  it is not. Epsilon applies to the allowance comparison and **not** to this
  one — a boundary is a boundary, and slackening it by epsilon would resolve
  entries sitting exactly on the line the team accepted.

### 7.3 Occurrence, presence, graph

The kinds differ in what the reader returns, not in how §7.1 decides:

- **Occurrence** — the reader counts the identities the run holds for this
  entry, applying the rule's own allow-list and nothing else (§5.6). Count above
  the allowance is `regressed`, equal is `matched`, lower but positive is
  `improved`, zero is `resolved`. A stable occurrence key takes precedence over
  a bucket count, and where the entry carries one the reader answers for that
  identity alone.
- **Presence** — the reader answers whether the construct is still there. Absent
  is `resolved`; present is `matched`; there is no middle value, per §7.1's
  reachability note.
- **Graph** — identity present in the run's band-independent evidence is
  `matched`, absent is `resolved`, and a new identity is `new`. Graph identity
  must be canonical and traversal-order independent (§2.7), and the same
  canonicalisation must produce the key on both paths — see §7.4.

### 7.4 When the finding is absent

This section was "missing symbols and scope" at 7.9, and its content was a set
of rules for when silence could be trusted. Under 8.0 silence is never trusted
and the section states what is *read* instead.

**A symbol absent from a complete discovery is `unobserved`.** Not `resolved`,
whatever the coverage evidence says. A rename and a delete-plus-create are
indistinguishable from outside (§17.4), and the error direction of guessing is
the one that deletes debt. `update`'s debt-neutral re-pointing (§8) is the
supported path for a moved symbol, which is why §17.5 D calls it a required part
of the lifecycle rather than a convenience.

This is a deliberate loss of one convenience: deleting a class no longer cleans
its entries automatically on the next run. It cleans them on the next
`baseline:update`, which is the command that exists to notice moves and
deletions, and the trade is a rename that can never be silently accepted as a
repair.

#### Which absences are measurements, and which are not

"Absence is never proof" is the headline, and taken literally it is false —
§7.3 decides three of the five kinds by an identity not being there. Round 8
was right to call the claim out, and the boundary is stated here rather than
left implicit:

- **Absence from an enumeration this run produced is a measurement.** The
  occurrences of a smell in a file that was analysed, the cycle set the detector
  built: the run looked and found nothing. That is a positive fact about the
  code, and it is what lets a repaired `goto` resolve at all.
- **Absence from discovery is not.** The symbol was not there to be enumerated,
  so nothing was measured and nothing follows (§17.4).

The two meet in a case that must not be decided by which sentence an implementer
read last. An occurrence key or a cycle identity is **composite** — built from
symbol names — so renaming any member changes the identity, and the entry's
identity then goes missing from an enumeration that was otherwise complete. The
rename argument transfers unchanged: rename a non-representative class of a
baselined cycle and the entry's own `symbolPath` still resolves, the enumeration
answers, the identity is absent, and the entry would read as repaired while the
cycle is intact.

So: **if any symbol named by a composite identity is absent from discovery, the
entry is `unobserved`**, whatever the enumeration says. `update`'s re-pointing
is the path, exactly as for a symbol-keyed entry.

**Aggregate and graph entries need the evidence recorded while rules run.**
`AnalysisContext` does not survive (§2.8), so a reader for such a channel is a
snapshot taken during rule execution and carried onto the analysis result. This
is the shape 7.9 introduced for pre-cutoff cycle evidence; at 8.0 it is simply
one of the two reader implementations, and the only one for
`architecture.circular-dependency`.

Three requirements on it, all carried forward from 7.9 unchanged because none of
them depended on the inference that was removed:

- **The snapshot is taken before the band cutoff.** `AnalysisContext::$cycles`
  holds the full set; `CircularDependencyOptions::getSeverity()` applies
  `maxCycleSize` afterwards, inside the rule. A snapshot taken after the cutoff
  would make a cycle that *grew* past the band read as gone — resolving an entry
  because its debt increased.
- **The snapshot key and the observation key are byte-identical.** The rule
  reports its identities from one method and attaches occurrence keys to
  observations from another; two independent canonicalisations of one cycle — a
  different member order, a different escaping, a different part list — never
  match, so every entry of the channel reads as absent. There must be one
  canonicalisation point and a test asserting both paths agree for the same
  finding.
- **A producer with no snapshot answers `not answerable`, never absent.**
  Falling back to the finding set is exactly the forbidden inference.

**`unobserved` and `orphaned` are distinguished by why the rule is missing**,
and the line is drawn at the build, not the configuration:

- the rule exists in this build but this run did not apply it — `disabled_rules`,
  `only_rules`, `rules.<name>.enabled: false` — → `unobserved`. Re-enabling the
  rule restores the entry, so it must survive untouched.
- the entry's **channel** is not declared by any rule in this build — removed,
  renamed, or from a version that no longer ships it — → `orphaned`. No
  configuration change will ever restore it, so it is the one class of entry
  `cleanup` may prune, behind an explicit flag.

The test is against **declared channels**, never against rule class names
(§5.1). Four `architecture.*` diagnostics are emitted under rule names no class
declares as its own; a class-name test would mark their entries permanently
`orphaned` while the same run re-emits the findings as `new` — an oscillation
that repeats every run and never converges.

Because analysis is always full (§2.6), `--report=git:*` narrows *presentation*
only. A regression outside the reported scope still counts toward the exit code
and appears in the summary line; its detail lines are collapsed. Making the exit
code depend on a display flag would be a footgun.


## 8. CLI and Lifecycle

```text
bin/qmx baseline:generate <baseline> <paths...> [--mode=ratchet|suppress] [--force]
bin/qmx baseline:migrate-plan  <baseline> <paths...> --out=<plan>
bin/qmx baseline:migrate-apply <baseline> <plan> [--mode=ratchet|suppress]
bin/qmx baseline:rebase-contracts <baseline> <paths...> --contract=<id>... --force
bin/qmx baseline:update  <baseline> <paths...> [--force]
bin/qmx baseline:cleanup <baseline> <paths...> [--force] [--prune-orphaned]
                                              [--prune-missing] [--prune-unprovable]
bin/qmx check <paths...> --baseline=<baseline> [--explain=<status>]
```

**The block above is the complete signature, not a namespace sketch**, and every
flag the prose of this section or §9.1 requires appears in it. Round 8 found four
missing — `--force` on `update` and `cleanup`, `--prune-orphaned`,
`--prune-missing` and `--explain` — in a block introduced with "these names are
final", which an implementer would reasonably read as the command's `configure()`
surface. A flag named in prose and absent here is a defect in this section, not
a stylistic choice.

Command names follow `noun:verb` per `docs/internal/CLI_CONVENTIONS.md`; the
two migration phases are separate verbs rather than one command with `--plan`
and `--apply` modes. **These names are final.** The conventions document had no
rule on multi-word verbs, so P0 added one rather than leaving the reading to the
implementer: a kebab-case qualifier is permitted when the bare verb would be
ambiguous or would overstate the command's scope, and it names a phase
(`migrate-plan`, `migrate-apply`) or the sub-object actually affected
(`rebase-contracts` rewrites the contract manifest, not the whole file), never
the namespace noun. All six names above conform as written; none is renamed.

All five lifecycle commands need to run an analysis. They share one
analysis-run service; the orchestration must not be copied out of
`CheckCommand`.

- **generate** — ratchet by default; refuses to overwrite without `--force`;
  captures the violation list **after evaluation-exclusion and before
  presentation-suppression** (§5.6). This is a deliberate change: today
  generation consumes the raw pre-filter list (§2.5), capturing findings that
  `check` would never report.
- **migrate-plan** — v5 in, disposition plan out: every v5 entry classified as
  matched, resolved, ambiguous, or unobserved, with a canonical fingerprint of
  the baseline and of the analysis inputs. The plan is a documented,
  user-editable artefact; its schema is part of the public surface and is
  specified in P3, not left to the implementation.
- **migrate-apply** — applies a reviewed plan, refusing if either fingerprint
  changed. Multiplicity is never invented: a v5 hash matching several
  indistinguishable occurrences captures `1`, and the rest surface as
  regressions. Unmatched current violations are never added.

**A v5 entry matching several *distinguishable* current identities expands to
all of them.** v5 deduplicated by canonical symbol plus hash, while v7 identity
additionally carries the contract and an occurrence key, so one v5 record can
correspond to several distinct v7 identities — different violation codes, or
different occurrence keys under one symbol. This is not the multiplicity case
below: those identities are individually distinguishable, and every one of them
was suppressed by that single v5 record. Migration therefore creates one v7
entry per matched identity, each capturing its own current axes, which is
faithful rather than debt-accepting — the disposition plan lists the expansion so
the user can drop any of them before applying. Only *indistinguishable*
occurrences of one identity fall back to capturing `1`.

**An unmatched v5 entry cannot be carried into v7 at all**, and the plan must
say so rather than implying a disposition exists. A v5 entry is a rule name plus
an opaque 16-character hash — no symbol, no axes, no contract, no kind, and not
even a record of which algorithm produced the hash, since `ViolationHasher`
picks `xxh3` or `sha256` at runtime. v7 identity is structural, so an entry with
no current finding to match against has nothing from which to reconstruct one.
The only honest dispositions are therefore **drop** or **abort the migration**,
both chosen explicitly by the user in the plan file; there is no "carry
forward". The disposition plan must present unmatched entries with whatever
context is available (rule name, hash, and any near-miss candidates) so the
choice is informed, and `migrate-apply` must report the dropped count
prominently rather than in a summary tail. Recorded in §14.6.
- **update** — monotonic: tightens allowances, reduces counts, never adds an
  identity, never increases accepted debt, never changes contract or kind. It
  additionally performs **debt-neutral identity re-pointing**: when an entry is
  `unobserved` because its symbol is absent (§7.4) and a `new` finding shares
  its contract, the entry may follow the moved symbol. "Debt-neutral" is defined
  precisely, because a loose reading lets a rename smuggle in growth: the new
  finding's **current** axes must be no worse than the entry's **captured** axes
  on every axis, and its occurrence count no greater. A `new` finding has no
  captured axes of its own — the comparison is old-captured against new-current,
  and the count is part of it, so a rename taking one occurrence to five is
  refused rather than re-accepted. The occurrence key must match too, or distinct
  occurrences would be conflated. And re-pointing requires evidence that a move
  actually happened: the entry's **original symbol must be absent from the
  discovered inventory**. Without that precondition a genuinely new finding
  elsewhere, sharing a contract and carrying a smaller magnitude, would be
  silently absorbed as a rename — new debt accepted without anyone deciding to.
  Where the match is ambiguous — several candidates satisfy it — `update`
  re-points nothing and reports the ambiguity.

  **At 8.0 this is a required part of the lifecycle, not a convenience**
  (§17.5 D). Absence stopped being proof of repair, so a rename no longer
  resolves anything by itself: `update` is now the *only* mechanism that moves an
  entry to a renamed symbol, and the only remedy for a mass rename (§14.3). The
  matching side of the predicate is unchanged from 7.9 — what changed is the
  status it starts from, which was `resolved` and is now `unobserved`.
- **cleanup** — **modifies the existing `BaselineCleanupCommand`**, it is not a
  new command. Today it takes only a baseline path, runs no analysis, and
  removes entries whose *file no longer exists on disk*. v7 replaces that
  heuristic: the command gains a required `<paths...>` argument, runs a full
  analysis, and removes entries confirmed `resolved` with reason `fixed`, plus
  `orphaned` entries behind an explicit flag. It never removes `unobserved`,
  `unproven`, or entries with any other status. A silenced entry proven `fixed`
  *is* removed — the proof comes from metrics the exclusion never touched
  (§5.6). The added argument is a breaking CLI change and needs a `Breaking`
  changelog entry.

  **The `cleanup` / `update` collision of 7.9 is dissolved, not guarded.** At
  7.9 a renamed class satisfied both commands at once — its symbol was absent,
  which made it `fixed` for `cleanup` and a re-pointing candidate for `update` —
  and running `cleanup` first destroyed the captured axes, leaving
  `generate --force` as the only exit. At 8.0 an absent symbol is `unobserved`
  and `cleanup` acts only on `fixed`, so the two predicates no longer overlap at
  all. The elaborate ordering rule 7.9 needed here is deleted along with the
  hazard; what survives is its test, which §13 keeps as a regression guard
  against re-introducing either half.

  **An entry no proof can reach needs an ergonomic exit, and it is explicit.**
  The inversion closed a data-loss path and opened a housekeeping one: two
  populations of entry are now permanently `unobserved` and no ordinary command
  will ever touch them. Left there, the file accumulates dead weight and the
  pressure goes back to `generate --force`, which re-accepts everything. So
  `cleanup` gains two flags, both acting on the **user's** assertion rather than
  the tool's inference, neither a default, neither implied by `--force`, both
  reported per entry:

  - `--prune-missing` — entries whose symbol is absent from a complete run.
    Symmetrical with `--prune-orphaned`.
  - `--prune-unprovable` — entries on a channel that declares **no reader**
    (§5.2), whose symbol is present and whose finding no longer fires. Round 8
    found this population had no exit at all: `--prune-missing` does not apply
    because the symbol is there, `update` never removes, and §14.10's only
    answer was hand-editing the file. Restricted to reader-less channels by
    construction — on any other channel a reading exists and must be used, so
    the flag would be a way of ignoring evidence.

- **rebase-contracts** — the only path for a known contract change; explicit
  contract ids plus `--force`; prints old and new data before writing. It must
  also be the exit for an `incompatible` entry **whose rule currently emits no
  violation**: `update` refuses contract changes and `cleanup` does not remove
  the status, so without this the only escape would be `generate --force`, which
  re-accepts every unrelated debt in the project and contradicts this command
  being the single contract-change path. For such an entry there are no current
  raw axes to rebase onto. It is therefore **removed**, not rewritten: §6.2
  requires every entry to carry axes matching its contract's manifest, so an
  axis-less entry is a file the tool's own loader would reject. Removal is
  reported per entry. This loses the captured magnitude, which is the honest
  outcome — the contract that gave those numbers meaning no longer exists, and
  the finding will be recaptured with current values if it reappears.
- `--generate-baseline` on `check` is removed, with no alias.

There is no `baseline:accept`. Accepting more debt is a threshold change in
`qmx.yaml`.

## 9. Reporting and Exit Behaviour

### 9.1 Output shape

Output is read by humans and by agents, so the signal must survive 500 entries.

1. A **summary line first**: `regressed N / new N / matched N / improved N /
   resolved N / unobserved N / orphaned N / incompatible N`, followed by
   `(silenced N)` as a qualifier rather than a bucket. The counts must satisfy a
   checkable invariant — every baseline entry falls into exactly one bucket, and
   `new` accounts for the rest of the current findings. §13 requires that
   invariant to be asserted, because a bucket introduced without a counter is
   exactly how the first draft lost `suppressed`. At 8.0 `suppressed` is not a
   bucket at all (§5.6): the same entries are counted in their outcome bucket
   and again in the qualifier, and the invariant is stated over the buckets only.
2. **Expanded by name**: `regressed` and `new` only. `regressed` prints as its
   own first block and is exempt from `TextFormatter`'s existing detail limit —
   otherwise a summary can honestly report ten regressions whose lines were all
   truncated away behind hundreds of `new` findings, leaving an agent with a
   count and no address.
3. **Collapsed to one line each**: every other status, with `--explain=<status>`
   to expand one on demand. A `regressed` finding is printed once, in the
   ratchet block, not additionally in the ordinary violation list.

A regression line carries rule and symbol, captured and current value or count,
delta and direction, the applicable allowance, whether the allowance was widened
by policy, the rule's recommendation, and the contract id when verbose.

**A regression the rule no longer reports must say so on its own line**
(§17.7 I). With the regression-implies-violation invariant retired (§5.1), an
entry can be `regressed` on a symbol that produces no violation at all — a
compound predicate that stopped firing while an axis worsened is the canonical
case, and a user reading `qmx check` will see nothing there. Printing a bare
delta invites the reading "the tool contradicts itself". The line names the
case instead: the measurement, the captured value, and the fact that no rule
currently reports this symbol, which is what the `withinWidenedPolicy` attribute
carries. P6 owes the same explanation in the user documentation.

`resolved` lines carry their reason. `unproven` is worth a sentence rather than
a word, because its remedy is not obvious: the debt reads as gone, the
configuration that measured it has changed, and regenerating the baseline is
what settles it.

### 9.2 Machine formats

- **JSON** — native keys for status, attributes, captured, current, delta,
  contract, coverage reason, plus per-status counts.
- **SARIF** — `baselineState` admits only `new`/`unchanged`/`updated`/`absent`,
  so v7 detail goes in `result.properties`; `baselineState` is populated only
  where a status maps honestly. No invented enum members.
- **Checkstyle, GitLab Code Quality** — no extension point; status, reason code,
  captured/current values, and delta go in the existing message field, keeping
  the schema valid.
- **GitHub** — per the selected annotation format's capabilities.

### 9.3 Exit behaviour

`fail_on` defaults to `error`, and Info and Warning findings exit 0. A
regression that stays inside the warning tier would therefore print and pass —
removing v6's independent failure path was a step wider than §5.1's premise,
which proves a violation *exists*, not that it *fails*.

Therefore: **`regressed` fails the build regardless of the finding's severity**,
controlled by an explicit configuration key so the behaviour can be turned off
deliberately. This is an exit-code policy, not a second debt-acceptance surface;
the §15 objection to `baseline:accept` does not apply.

**Exit follows the allowance, not the visibility** (§17.5 C). A `regressed`
entry fails whether or not a rule reported the finding — that is the whole point
of measuring — and **nothing else newly fails**. Debt that is unchanged but no
longer reported is shown, not fatal. The ratchet fails on worsening, never on
invisibility. Stating the second half matters as much as the first: if
invisibility failed the build, the first run after adopting 8.0 would fail on
every entry whose rule had quietly stopped reporting, and the feature would be
switched off before it proved anything.

A `silenced` entry never contributes to the exit code, whichever half of §5.6
silenced it. The status is still computed and still reported; the user asked for
that area not to fail the build, and the ratchet is not a way around an
exclusion.

`new` findings follow ordinary `fail_on`. `matched` and `improved` are filtered.
`resolved` and `orphaned` are informational. `unobserved` and `incompatible`
produce diagnostics and block mutation of the entries concerned. A reader /
observation disagreement (§5.2) is not a finding at all — it is an internal
error and aborts the run. Loader and schema errors keep the existing
configuration error class.

## 10. Interaction With Other Features

- **Thresholds** — changing the onset boundary changes the allowance by design.
  Tightening may surface findings as `new`; relaxing widens allowances, so
  growth up to the new boundary is `matched` and flagged with
  `withinWidenedPolicy` (§7.1). At 8.0 relaxing a threshold **no longer resolves
  anything**: repair is measured against the onset stored in the entry, so the
  captured debt stays in the file until the code actually improves past the line
  the team accepted. Re-tightening therefore restores the original entries
  rather than re-admitting them as `new` at today's worse values — the property
  7.9 bought with reason `policy` and 8.0 gets for free.
- **Configuration edits generally** — any change to the resolved configuration
  moves the fingerprint (§5.7) and suspends `fixed` until the baseline is
  regenerated. On a repository that tunes `qmx.yaml` continuously this is the
  most user-visible cost of 8.0, which is why the per-channel projection exists
  as an opt-in.
- **Git scopes** — presentation only (§7.4).
- **Contract versioning** — bumped when an observation's *meaning* changes
  (axes, direction, epsilon, **the declared onset comparison**, identity,
  occurrence semantics), not on every algorithm edit. The comparison is on that
  list because an operator moved from inclusive to exclusive changes which
  readings count as debt without changing a number, so neither the manifest
  check nor this list would otherwise catch it. The channel traits and the
  reader declaration are deliberately **not** here — they describe the current
  run, not the comparable shape. Bumps land only in major releases and are
  listed in `CHANGELOG.md` by contract id, because each turns consumer entries
  `incompatible` until rebased.
- **Computed metrics** — need a deterministic contract id derived from the
  normalised formula, level, axis semantics, and observation version. Display
  rounding is never used for comparison.
- **AST cache** — there is no metric cache: the only cache is `CachedFileParser`
  storing parsed AST nodes, keyed by `CacheKeyGenerator` on realpath, mtime,
  size, and the PHP and parser versions. Collector *output* is not cached, so a
  new collector output shape cannot be served stale. What the key does lack is
  any Qualimetrix version component, so an upgrade that changes parsing
  assumptions reuses old nodes; adding that component is a small, separate
  improvement and is **not** a prerequisite of this plan. An earlier revision
  asserted a metric cache that does not exist and set a DoD against it.

## 11. Work Packages

Every tracked path has exactly one owning package. Parallel packages use
separate worktrees. No package stashes, restores, or cleans a shared worktree.

Two ownership rules that round 2 showed were missing, and whose absence is the
most likely source of silent defects:

- **A package owns every test its production changes break**, not only the tests
  it adds. Otherwise an agent finishes with a red `composer check` and no right
  to fix it. Each package below therefore names the existing tests its changes
  are expected to break.
- **A package may add test files only inside its listed test directories.**

Cross-package data contracts are owned by **P1a′** — P1a held that role until
its own review found four such contracts missing from its Definition of Done
(§0.8), and 8.0 adds a fifth. Where a package produces data another package
consumes, the *shape* is defined in Core first; a DoD that only asserts "my side
works" is insufficient, because both sides pass independently while the seam is
broken.

The rule has a corollary that round 8 had to apply twice: **a package's DoD may
not require a value whose only producer lands later.** Both times the symptom
was the same — a consumer testable only against a hand-supplied stub, with
nothing in Core to make the stub and production agree.

### P0 — Contract freeze ✅ closed at 7.7
Files: this document, `docs/internal/CLI_CONVENTIONS.md`. Dependencies: none.
DoD: review passes with no unresolved CRITICAL or HIGH finding; the
layout decision (§16) is recorded; every command name in §8 is final, and where
`CLI_CONVENTIONS.md` neither permits nor forbids a hyphenated verb phrase
(`migrate-plan`, `rebase-contracts`) the rule is added there rather than left
for the implementer to guess.

Closed: four review rounds (§0.2–§0.6) plus the inventory validation (§0.7) left
no unresolved CRITICAL or HIGH finding; §16 records the layout decision;
`CLI_CONVENTIONS.md` gained a *verb segment* rule and all six §8 command names
conform under it without renaming.

### P1a — Core contracts and topology
Files: `src/Core/Observation/**`, `src/Core/Violation/Violation.php`, `qmx.yaml`,
`tests/Unit/Core/Observation/**`,
`tests/Integration/Architecture/DogfoodingTopologyTest.php`,
`tests/Unit/Core/Violation/ViolationTest.php`.
Dependencies: P0.
Small and deliberately first: it unblocks P2 and P3 simultaneously. `qmx.yaml`
and its pinning test are owned here and by no one else — note the topology test
hardcodes layer names, so any layer addition touches both.

**Also owns the occurrence-key carrier.** P1c produces occurrence
discriminators in collectors and P1b consumes them in rules; the type, field
name, and null semantics of that carrier are defined here, next to
`DebtObservation`, before either consumer starts. `DebtObservation.occurrenceKey`
is the sink, not the source, and naming the sink alone lets both sides pass
their own DoD while the seam is broken.

DoD: observation, axis, kind, contract-reference, coverage-contract, status
enum, and occurrence-key carrier types exist in Core; Core remains
dependency-free; the topology admits `Analysis\Coverage` **and** names the full
set of inbound edges its consumers will need (`analysis-pipeline`,
`analysis-collection`, `analysis-ruleexecution`, and the Infrastructure layers),
so that no later package inherits a red `composer check` it cannot fix;
`baseline: [core]` stays satisfiable; a consumer stub exercises the coverage
contract from the P3 side before it is frozen; PHPStan passes.

**P1a status: implemented and reviewed; three follow-ups open.** Ownership was
extended during implementation beyond §11's literal list, to
`src/Core/Coverage/**`, `src/Core/Comparison/**`,
`src/Core/Violation/ViolationChannel.php` and their tests: §5.3 requires the
coverage contract and the status enum to live in Core without saying where, and
ADR 0016's naming test makes them separate subjects rather than one directory
named for the feature. Recorded here so the extension is deliberate rather than
discovered later as a package writing outside its list.

The follow-ups from §0.8 are carved out as their own package, P1a′ below, and
must be closed **before P1b, P1c and P3 start**, because each is a seam those
packages would otherwise cross blind.

Until they land, the consumer stub in P1a's tests decides `resolved` from
channel-wide coverage evidence alone. At 7.9 that was an overstatement of what
the coverage contract could answer; at 8.0 it is a decision no evidence of that
kind may make at all — resolution requires a reading (§7.1). Retiring or
rewriting the stub is P1a′ item 9, and it matters more than it did: a green test
pinning "coverage proves repair" in the frozen base is the exact proposition the
inversion removed.

### P1a′ — the contracts the comparison cannot be written without

**Re-cut at 8.0.** The package keeps its position and most of its file list; two
of its four contracts change shape, because the inversion moved the work from
"decide which inference is safe" to "read the value".

Files: `src/Core/Channel/**` (registry, channel contracts, the reader contract
and its run-facts carrier, both onset providers), `src/Core/Coverage/**`,
`src/Core/Suppression/**`, `src/Core/Violation/Violation.php`,
`src/Core/Violation/ViolationChannel.php` (moved out), `src/Core/README.md`,
`tests/Unit/Core/Channel/**`, `tests/Unit/Core/Coverage/**`,
`tests/Unit/Core/Suppression/**`, `tests/Unit/Core/Violation/**`, this document.
Dependencies: P1a. **Blocks P1b, P1c and P3.**

| Contract                   | Shape                                                                                      | Specified in |
| -------------------------- | ------------------------------------------------------------------------------------------ | ------------ |
| Declared-channel registry  | `ChannelContract`, `AxisContract`, `ChannelRegistryInterface`, `ChannelDeclaringInterface` | §5.7         |
| **Channel reader**         | the reader interface, its three-valued answer, and the run-facts carrier it is given       | §5.2, §5.3   |
| Config-driven silencing    | a two-valued run-scoped query, in `Core/Suppression/` — reporting and exit only            | §5.6         |
| Onset in force now         | the rule-side and run-scoped onset providers, two-valued, feeding the allowance only       | §5.7         |
| **Measurement provenance** | the fingerprint value type and the interface that supplies it (added at round 8)           | §5.7         |

The fifth is here for the reason the other four are: P3 compares the fingerprint
and P4a computes it, P4a lands two packages later, and without a Core contract P3
is testable only against a value it invents. That is §0.8's failure exactly, and
round 8 caught it before a line of code was written this time.

**The run-facts carrier must include the threshold overrides.** §5.1 requires the
onset provider to reproduce the rule's actual behaviour, inline
`@qmx-threshold` included, and §13 tests it — but overrides live on
`AnalysisContext`, which does not survive (§2.8), and 8.0 first specified the
carrier as "the metric repository and the recorded snapshots". On this
repository the population is non-empty — `AnalysisContext.php` carries an
override in its own docblock — so every symbol with a widening override would
have had its allowance computed from the global boundary, and growth the user
explicitly licensed reported as `regressed`.

Two of these are smaller than their 7.9 versions and one is new:

- The **reader** replaces 7.9's occurrence-addressed coverage answer *and* the
  repair half of the onset provider. Its carrier is the part to get right: the
  run's facts are `MetricRepositoryInterface` (already Core, already on
  `AnalysisResult`) plus whatever P2 records during rule execution, and a
  carrier assembled from Core types is what keeps `baseline: [core]` satisfiable
  while the implementations live beside the rules.
- The **silencing query** drops to two-valued and central-only. Its rule-side
  sparse report is deferred (§5.6), and its errors no longer delete anything.
- The **onset provider** drops from four answers to two.

`ViolationChannel` moves from `src/Core/Violation/` to `src/Core/Channel/`,
joining what a channel *declares* to the address it is declared under. The
precedent is `SymbolPath`, which left `Violation/` for `Symbol/` for the same
reason. Three production consumers, all inside Core; every test that references
it already sits in a directory this package owns.

**Definition of Done — stated as decisions, with the producer absent.** P1a was
delivered complete against a DoD that enumerated types and still left four seams
open; listing types is what produced that outcome. Each item below is a stub
consumer making a decision, not a type being constructed:

1. a stub comparator decides `incompatible` for a channel whose rule emitted
   **no violation in this run**, from the registry alone — the one scenario the
   registry exists for;
2. a stub computes `resolved` / `fixed` for an entry with **no finding in hand**,
   from a reader answer compared against the entry's **captured** onset — and
   the same entry, with the file's `config_fingerprint` differing from the
   current one, yields `unproven` instead. Both directions, since the second is
   the only remaining producer of that reason;
3. a stub decides **`regressed` for an entry with no current finding**, from a
   reading past its allowance. This is the state 7.9's vocabulary could not
   express (§17.5 A) and the reason the whole inversion was taken; a suite
   without it proves nothing about 8.0;
4. a stub distinguishes the four causes of `unobserved` — reader `not
   answerable`, no reader declared for the channel, the channel declared but
   inactive, and incomplete coverage — and reports which. Collapsing them is how
   a user loses the difference between "we did not look" and "this channel
   cannot be proven";
5. a stub decides `matched` with `withinWidenedPolicy` for captured 10, captured
   onset 8, current onset 20 and a reading of 15 — §17.5 B's own example,
   asserted to be reachable, because the attribute it revives was removed at 7.3
   for being unreachable;
6. a stub decides `orphaned` for a channel matched by neither an enumerated nor
   an open-ended declaration, and **not** for a `computed.*` channel whose
   definition was removed from configuration while its open-ended declaration
   stands;
7. a stub decides `regressed` **plus** `silenced` for an entry inside an
   excluded path whose reading is past its allowance, and the exit policy leaves
   the build green. Silencing must qualify the outcome, never preempt it — the
   7.9 behaviour was the opposite and an implementation carrying it forward
   passes every other item here;
8. **a positive outcome is proven reachable end to end.** One entry traverses
   the whole §7.1 precedence — declared in the registry, contract matching,
   reader answering, fingerprint matching — and arrives at `resolved` / `fixed`.
   Items 1–7 include four refusals, and this plan has twice shipped a refusal
   that could never be lifted;
9. the consumer stub written under P1a is brought in line or retired: it decides
   `resolved` from channel-wide evidence and substitutes a raw array for the
   registry, which §0.8 names as the proof the contract was missing. Leaving it
   is leaving a wrong decision pinned by a green test in the frozen base;
10. Core stays dependency-free; PHPStan level 8 clean; `composer check` green;
    `src/Core/README.md`'s structure block lists every new file;
11. no rule, collector, or Analysis file is touched — a contract package that
    needs a production change to prove itself has not defined a contract.

### P1b — Rule observations and readers
Files: `src/Rules/**` (including `src/Rules/AbstractRule.php`),
`src/Architecture/Rules/**`, `tests/Unit/Rules/**`,
`tests/Architecture/Unit/Rules/**`, `tests/Integration/Rules/**`,
`src/Rules/README.md`, `src/Architecture/README.md`,
`docs/plan/channel-trait-inventory.md`.
Dependencies: P1a′. Splittable by rule category across agents with disjoint
directories.

`AbstractRule.php` belongs here, not to P1a: the onset-boundary helper that
every rule needs sits naturally beside the existing `optionsForSymbol()`
`@qmx-threshold` handling, and splitting the file between two packages is the
most likely merge conflict in the plan.

Coordinate with the external `CircularDependencyRule` cycle-identity fix
(§2.7): it edits `src/Architecture/Rules/`, which this package owns. Either land
it before P1b starts or fold it in; the completion predicate is a test pinning
canonical cycle representative selection independent of graph traversal order.

**P1b owns the rule-side half of three P1a′ contracts**, and at 8.0 the reader
is the largest of them. P1a′ defines shape, P2 implements the run-scoped halves;
without this paragraph the producer side belongs to nobody, every rule passes
its own DoD, and the registry stays empty while the run-scoped answers return
`unobserved` for ever — the P1a failure repeated one package later. The three
are the channel declarations, the reader (or its explicit absence), and the
onset provider.

**The per-channel obligation, stated exactly** (§17.6 item 3). For every channel
in the inventory, this package delivers **either** a reader **or** an explicit
declaration that the channel has none. Registration rejects a channel that
declares neither: a channel silently without a reader is indistinguishable, at
the consumer, from one whose reader always answers `not answerable` — except
that the second is honest and the first is an omission nobody sees until entries
stop resolving.

The expected split is 45 metric-repository readers, one snapshot reader, and six
declared absences (§5.2). That distribution is **derived from the inventory and
must be confirmed channel by channel, not assumed**: this plan's four worst
findings all came from a rule asserted over a set nobody had enumerated. A
channel whose reader cannot be written from the metric repository is a finding
against §5.2's table, not a judgement call for the implementer.

DoD: a registry-driven test asserts every rule in `RuleRegistry` emits an
observation of a declared kind — no hand-maintained list; **every channel in the
inventory is reachable through the registry and declares a reader or its
absence**; the onset boundary is never derived from the tier-matched threshold;
`GodClassRule` emits fixed raw axes with nulls for unavailable metrics;
threshold changes alter boundaries but never raw values or identities; **the
rule-side onset provider returns different boundaries for two symbols under one
violation code** (`LongParameterListRule`'s VO branch is the case, and it
bypasses `getEffectiveOptions()`, so an inline `@qmx-threshold` reaches ordinary
methods only — the provider must reproduce that, not an idealisation of it);
**`architecture.circular-dependency`'s snapshot is filled before the band cutoff
and the key it reports is byte-identical to the one its own observation
carries**; `architecture.coverage` declares its unmatched-end count as an axis so
the channel ratchets while it fires.

Three DoD items exist only because of the inversion, and they are the ones to
verify against the diff rather than the report:

- **the reader/observation self-check runs on every analysis** (§17.5 F) and a
  disagreement is a hard error naming the channel, the identity and both values;
- **a reader reproduces every path by which its rule discards a candidate
  occurrence** (§5.6), proven on a fixture carrying **both** of
  `BooleanArgumentRule`'s filters at once — one occurrence dropped by the
  interface-level allow-list and one dropped by `shouldIncludeEntry()` under
  `flag_promoted_properties` — since a reader written against the interface
  alone satisfies the narrower wording and still aborts the run on ordinary
  PHP 8 code;
- **the self-check is proven to fire on a seeded disagreement for a
  bucket-counted channel**, not only for a scalar one (§5.2) — that population
  is where an axis-only comparison is vacuously green;
- **a reader answers for a symbol its rule skipped by an eligibility gate**:
  turning on `excludeReadonly` or raising `minAfferent` must not change a
  reading. This is the half of "reads ahead of policy" that has no observation to
  compare against and therefore no other test.

Three obligations 7.9 placed here are **removed**: the pre-cutoff identity
reporter (it is now simply this channel's reader), the sparse silencing report
(deferred, §5.6), and the inventory's coverage-scope column (the trait is
deleted, §5.7). The per-channel measurement-digest keys are removed with them —
the fingerprint is global (§17.5 E).

### P1c — Occurrence identity in collectors
Files: `src/Metrics/**`, `src/Analysis/Duplication/**`,
`src/Core/Duplication/**`, `src/Infrastructure/Parallel/**`,
`src/Infrastructure/Serializer/**`, `tests/Unit/Metrics/**`,
`tests/Unit/Analysis/Duplication/**`, `src/Metrics/README.md`.
Dependencies: P1a′. Parallel with P1b.

`src/Analysis/Duplication/**` is included because the normalised token hash is
produced there, not in `Core/Duplication`. The parallel and serializer paths are
included because collectors run inside workers: a new output shape crosses
worker serialisation, and unit tests run single-process, so a defect there is
invisible until an end-to-end run in the default parallel mode.

DoD: stable occurrence keys for code-smell, security, and duplication findings,
reproducible across runs and across file-order changes; **identities are
byte-identical between `--workers=0` and a parallel run** on the same input; a
cross-package assertion shows a rule reading a key that a collector produced,
rather than each side asserting only its own half.

### P2 — Coverage and the run's facts
Files: `src/Analysis/Coverage/**`, `src/Analysis/Collection/**`,
`src/Analysis/Pipeline/**`, `src/Analysis/RuleExecution/**`, matching tests,
`src/Analysis/README.md`.
Dependencies: P1a′.

Implements the run-scoped halves of P1a′'s contracts: run coverage, the
silencing query, the run-scoped onset facade, and — new at 8.0 — **the run-facts
carrier the readers are given**. That carrier is the package's centre of gravity:
the metric repository is already on `AnalysisResult` (§2.8), but anything a
reader needs that lives on `AnalysisContext` must be recorded while rules run
and carried forward, and today nothing is.

DoD: partial, failed, and interrupted runs are distinguishable from complete
ones; the deviation list is empty on a clean full run **and contains no entry
for a symbol a rule merely declined to judge** (§5.5 — an applicability filter
is not a deviation, and `excludeReadonly` defaults to `true`, so getting this
wrong freezes every affected entry as `unobserved` for ever); the coverage key
includes the violation code; the evaluation gate implements §5.6's first
category before rules execute; **the run-facts carrier reaches a post-analysis
consumer with the metric repository, the recorded snapshots and the threshold
overrides on it**, proven by a reader answering from it after `RuleExecutor` has
finished and by an onset query returning a symbol's inline-overridden boundary; the
`architecture.circular-dependency` snapshot is taken **during** rule execution,
before the band cutoff, and survives onto the analysis result, since
`AnalysisContext` does not (§2.8, §7.4); the silencing query is **two-valued**
and central, and its answer changes reporting and exit only — a test asserts
that an entry inside `exclude_paths` still reaches a computed outcome status;
**removing a `computed_metrics` definition from `qmx.yaml` leaves its entries
`unobserved`, not `orphaned`** — asserted end to end against a real
configuration change, not on a stub with both registry answers supplied by hand,
since the stub is exactly what would pass while production did the opposite.

### P3 — Baseline v7 domain and lifecycle
Files: `src/Baseline/**`, its tests, `src/Baseline/README.md`.
Dependencies: P1a′ only — genuinely parallel with P2 now that the coverage and
reader contracts are in Core.
DoD: the lifecycle policy §0.8 kept out of Core lands here and is asserted
against §5.6's table — which statuses an ordinary command may mutate, and which
resolution reasons `cleanup` may remove; comparison matrices pass for every kind,
status, reason and attribute; **the comparator reads the entry's captured onset
for repair and the run's current onset for the allowance, and a test pins that
swapping them changes the outcome** — the single mistake that re-creates either
v6's absolute ratchet or 7.9's deleted debt; **the `config_fingerprint` is
written on capture and checked on comparison through P1a′'s Core interface, not
against a value this package invents**, with `fixed` becoming `unproven` when it
differs and everything else unchanged; `--prune-missing` removes only entries
whose symbol is absent from a complete run, `--prune-unprovable` only entries on
a channel that declares no reader, and neither is implied by `--force`; the migration plan schema is specified and versioned; malformed files
fail closed; writes are atomic with a real CAS guard; no-op operations preserve
bytes; v5 is rejected outside migration.

### P4 — Configuration, CLI, DI, reporting
Split into three sub-packages with disjoint directories, because as one unit it
is a serialised bottleneck after P2 and P3:

- **P4a — configuration and pipeline order.** `src/Configuration/**`,
  `src/Infrastructure/Console/ViolationFilterPipeline.php` and its siblings,
  `src/Infrastructure/DependencyInjection/**`, `src/Configuration/README.md`.
- **P4b — commands.** `src/Infrastructure/Console/Command/**`,
  `src/Infrastructure/Console/CheckCommandDefinition.php`,
  `src/Infrastructure/README.md`,
  `tests/Functional/Console/Command/CheckCommandTest.php`,
  `tests/Integration/Documentation/DocumentationConsistencyTest.php`.
- **P4c — reporting.** `src/Reporting/**`, `src/Reporting/README.md`.

`src/Configuration/**` is named explicitly because §9.3 promises an opt-out
configuration key, and adding one is not a one-line change: per `AGENTS.md` it
requires a `ConfigSchema` constant and `ENTRIES` row, a
`SectionNormalizationPolicy` entry (a missing policy is a fail-fast
`LogicException`, not a silent default), handling in `DefaultsStage`/`CliStage`,
and a consumer in `AnalysisConfiguration`. 7.0 left this path unowned, so the
key would have shipped as a hardcoded constant or not at all.

**Two keys, not one, at 8.0.** The second is the fingerprint scope of §5.7 —
global by default, per-channel projection as an explicit opt-in — and it needs
the same four steps. It is also where the **provenance fingerprint is computed**,
which belongs to P4a for the reason the key does: §5.7's projection is a
function of what `src/Configuration/` produces, and digesting raw YAML instead
makes every CI run under a preset differ from the developer's baseline. P1a′
owns the contract it is supplied through, so P3 never waits on this package.

Dependencies: P2 and P3. P4c may run parallel to P4a; P4b depends on P4a.

DoD: **both** new configuration keys exist as real options with all four
`ConfigSchema` steps present — the ratchet failure key of §9.3 and the
fingerprint-scope key of §5.7; **the fingerprint is computed over §5.7's named
projection**, proven by a test that a baseline generated under one checkout path
and one `--workers` value verifies unchanged under another, and by a test that
changing `coupling.framework_namespaces` does move it; one shared analysis-run
service backs all
lifecycle commands rather than five copies of `CheckCommand`'s orchestration;
the filter pipeline runs comparison after evaluation-exclusion and before
presentation-suppression; `generate` captures the list at that same point, and a
test shows an `exclude_paths` finding is absent from a freshly generated
baseline; the summary line precedes details, `regressed` is printed as its own
first block and is **exempt from `TextFormatter`'s existing detail limit** so a
regression cannot be truncated away behind hundreds of `new` findings; SARIF
uses `properties`; Checkstyle and GitLab stay schema-valid; HTML, Health, and
Summary formatters either render the status or are documented as unchanged,
with `composer test:js` and `composer build:js` run if
`src/Reporting/Template/` is touched; `regressed` fails the build with
`fail_on: error` and a warning-severity finding, and passes with the key
disabled; `--generate-baseline` fails with an actionable message.

### P5 — Seam tests
Files: `tests/Integration/BaselineRatchet/**`,
`tests/Functional/Console/Command/BaselineLifecycleTest.php`, `tests/Fixtures/BaselineV7/**`.
Dependencies: P4.
DoD: every kind exercised end to end; the warning→error transition is a
regression, not a `matched`; relaxing a threshold widens the allowance without
hiding growth beyond the new boundary, and resolves nothing; `@qmx-ignore` and
`exclude_paths` both suppress the build failure while the entry still receives a
computed status; lifecycle commands succeed on this project's own `qmx.yaml`;
§14 limitations are pinned by tests so they cannot be silently "fixed"; memory
measured against the 2G ceiling on the largest benchmark project.

**P5 owns the half of the reader self-check that cannot run at run time**
(§17.7 G). §5.2's pairing validates readers against findings that still fire;
the verdict that destroys data is the opposite one, where the reader says the
debt is gone and no observation exists to disagree. So:

- **one fixture per reader shape** — scalar metric, vector, occurrence with a
  stable key, occurrence with a bucket count, presence, and the graph snapshot —
  in which a finding is deliberately removed from the fixture code and the
  reader must return the cleared value. Six fixtures, named after the shapes
  rather than after the channels, so a new channel of an existing shape inherits
  a test rather than a gap;
- **the dogfooding run**: generate a baseline against this repository, fix a
  handful of findings by hand, and assert `cleanup` removes exactly those. This
  is the only test in the plan where the whole chain — collector, rule, reader,
  fingerprint, comparator, command — runs against real code at real scale;
- **the regression guard for the dissolved `cleanup` / `update` collision**
  (§8): a renamed class must reach `unobserved`, `cleanup` must leave it, and
  `update` must re-point it. The hazard is gone by construction, and the test
  exists so that re-introducing either half fails loudly;
- **§14.3's documented rename behaviour, asserted directly**: a rename combined
  with a change — the case re-pointing refuses — produces `unobserved` plus
  `new`, and `--prune-missing` is the only thing that clears the first. §13
  promised this test and no bullet delivered it;
- **the composite-identity rename** (§7.4): renaming a non-representative member
  of a baselined cycle must reach `unobserved`, never `resolved`, even though
  the entry's own symbol still resolves and the cycle enumeration answers.

### P6 — ADR and documentation
Files: `docs/adr/0017-ratchet-baseline.md`, `docs/adr/README.md`,
`docs/ARCHITECTURE.md`, `website/docs/usage/baseline{,.ru}.md`,
`website/docs/usage/cli-options{,.ru}.md`, `website/docs/ci-cd/*{,.ru}.md`,
`CHANGELOG.md`.
Dependencies: P5 behaviour frozen.
DoD: a test compares documented options against `--help` output; EN/RU parity;
strict MkDocs build clean; `Breaking` entries name every removed surface per the
Backward Compatibility Policy in `AGENTS.md`; the stale `composer deptrac`
reference in `ARCHITECTURE.md` (removed by ADR 0014) is corrected.

## 12. Execution Sequence

1. ~~Review, then approve P0.~~ Done — P0 closed at 7.7.
2. ~~P1a; standard review; freeze as the common base.~~ Done — landed and
   reviewed; the review found four contract seams its DoD had not enumerated.
3. ~~Review revision 8.0 before writing any code against it~~ (§17.6 item 4).
   Done — round 8, three reviewers on disjoint slices, 1 CRITICAL and 13 HIGH,
   all folded in at 8.1 (§0.11).
4. **Round 9, narrow and without slicing.** Every reviewer attacks the same
   propositions: §0.11's corrections, §7.1's dimensions and precedence, and
   §5.7's fingerprint projection. Round 8's slices bought breadth and no
   redundancy, so a clean slice was indistinguishable from a weak reviewer —
   yet the two findings that were reached twice, independently, are the two the
   corrections lean on hardest. This is also the round that checks the
   corrections themselves, which is where every previous round's yield was.
5. P1a′; standard review. Only then is the base actually frozen.
6. P1b and P1c in parallel worktrees; verify each diff against its DoD.
7. P2 and P3 in parallel; integrate one at a time.
8. P4, then P5 without touching earlier packages' files.
9. Full validation and self-analysis, including lifecycle commands against this
   repository's own `qmx.yaml`.
10. Extended review with three independent reviewers.
11. Verify every finding, fix confirmed ones, re-validate.
12. P6, final validation, website build, seam-focused second round if round 1
    found contract or coverage issues.

## 13. Test Plan

- **Core contracts** — per-kind construction invariants; finite values; null
  axis values; epsilon; identity canonicalisation; version compared after
  identity match, never as part of it.
- **The four P1a′ seams, each asked from the consumer's side** — a registry
  answer for a channel with no violation this run; a reader answer for an entry
  with no finding, feeding `resolved`/`fixed` against the **captured** onset; a
  silencing answer that qualifies an outcome without preempting it; and an onset
  answer used for the allowance and nowhere else. A producer-side test that only
  builds the type and reads it back proves nothing about a seam — P1a passed
  exactly that kind of test with four seams open. Every stub asserts a
  **decision**, not a shape.
- **Reachability of the positive outcome, asserted directly.** One entry walks
  the whole §7.1 precedence and arrives at `resolved`/`fixed`; one arrives at
  `regressed` with **no current finding at all**; one inside `exclude_paths`
  arrives at `regressed` plus `silenced` and leaves the build green; one arrives
  at `matched` with `withinWidenedPolicy`. Three revisions of this plan have
  shipped a state that reads as correct and cannot occur, and each time the
  suite consisted only of tests that something is refused. A refusal-only suite
  cannot detect that nothing is ever allowed.
- **The two onsets are not interchangeable.** A test in which the captured onset
  and the current onset differ, asserting that reading the current one for repair
  resolves an entry that must stay, and reading the captured one for the
  allowance reports a regression that must not. This is one test and it pins the
  single most consequential implementation mistake available in 8.0.
- **The reader reads ahead of policy** — the same fixture measured with a
  threshold raised, an eligibility gate switched on, a band cutoff crossed, and
  an `exclude_paths` entry added, asserting the reading is identical in all
  four. And the counterpart: a rule's allow-list gaining an entry **does** change
  the reading, which is why the fingerprint exists.
- **The reader / observation self-check** — a deliberately wrong reader for one
  channel, asserted to abort the run with both values named; and the full-scale
  pairing running green on this repository.
- **The allowance rule** — captured tighter than onset; onset tighter than
  captured; both directions; **the warning→error transition, asserted to be
  `regressed` and not `matched`**; inline `@qmx-threshold` changing the onset for
  one symbol only; rules with no numeric boundary, where the allowance is the
  captured value; compound rules, where an inline override moves `minCriteria`
  and must not widen any axis allowance; a **magnitude cutoff**, where a cycle
  grown past `maxCycleSize` is `regressed` rather than resolved — the case that
  motivated the whole inversion.
- **Channels and readers** — a registry-driven test enumerating every channel a
  rule can emit, including the four `architecture.*` diagnostic names that no
  class declares as its own; **every channel declares a reader or its explicit
  absence**, and registration rejects a channel declaring neither; an emitted
  violation whose channel is undeclared fails the build; the onset provider
  queried for a symbol-conditioned rule (`LongParameterListRule` VO versus
  ordinary constructor) returning different onsets under one violation code; a
  computed metric configured `warning: 20, error: 10`, asserting the onset is 10;
  a compound channel whose predicate stops firing while an axis worsens,
  asserted `regressed` — the case §5.9 existed to work around.
- **Rule observations** — raw versus display-rounded values; inverted directions; `GodClassRule` axis stability
  when TCC is missing and when the LCOM veto engages; stable computed-metric
  contract ids; stable cycle and duplication identity; occurrence multiplicity.
- **Coverage** — complete, disabled, `only_rules`, discovery `exclude`,
  `exclude_paths`, parse failure, worker failure, interruption, incomplete
  aggregate and graph scope; the three categories of §5.6 producing their
  documented outcomes, with the second and third **not** preempting a status;
  deleted versus unobserved versus orphaned.
- **Comparison** — every status, reason and attribute for every kind, with
  `fixed` asserted **reachable** on an ordinary improved scalar and on a compound
  entry alike; `unproven` produced by a changed `config_fingerprint` and by
  nothing else; the status precedence exercised by an entry that is
  simultaneously excluded, contract-changed, and rule-removed; manifest mismatch
  at an equal declared version; a forgotten version bump on a rule that emits
  **no** violation at all, asserted to yield `incompatible` rather than
  `resolved` — this is the sole scenario justifying the contract registry
  (§5.7), and comparing against contracts harvested from emitted observations
  would pass every other test; `unobserved` versus `orphaned` for a
  config-disabled rule versus a rule absent from the build; ratchet versus
  suppress.
- **Serialisation** — round trip including `occurrence_key`, stored onsets and
  `config_fingerprint`; byte stability; fixed-clock generation; no-op
  preservation; path portability; malformed values; NaN and infinity rejection;
  atomic write and failed-rename cleanup; concurrent writers under the CAS guard.
- **Lifecycle** — plan/apply fingerprint guards; ambiguous entries surfacing in
  the plan rather than aborting; unmatched v5 entries offered only drop-or-abort
  and the dropped count reported; per-entry guard leaving unproven entries
  untouched while writing the rest, and never writing a partially trusted file
  when a parse failure means the run could not see part of the tree; `update`
  debt-neutral re-pointing **and its monotonicity** — an attempt to widen an
  allowance must be refused, not merely absent from the happy path; `cleanup`
  refusing `unproven` entries, pruning `orphaned` ones only behind its flag and
  missing-symbol ones only behind `--prune-missing`; `generate` capturing after
  evaluation-exclusion and before presentation-suppression, asserted by an
  `exclude_paths` finding being absent from a freshly generated baseline.
- **Reporting and exit** — summary-line-first ordering, with the bucket sum
  invariant and `silenced` counted as a qualifier rather than a bucket;
  expansion rules; `--explain`; SARIF properties; schema-valid Checkstyle and
  GitLab; a warning-severity regression failing the build under `fail_on:
  error`, and passing when the ratchet failure key is disabled; a `regressed`
  entry with no current finding printing a line that says so.
- **Residual limitations** — §14.1, §14.2, §14.3 and §14.10 each pinned by a
  test that asserts the documented behaviour.
- **Full validation** — `composer check`; `bin/qmx check src/`; strict MkDocs
  build; private-leak guard; benchmark regression suite; memory and wall-time
  measurement against the 2G ceiling. `RuleExecution` is 1–3% of runtime today,
  so the expected wall-time impact is small — but the reader self-check adds a
  second measurement per finding, so it is measured, not assumed.

## 14. Residual Limitations

Each limitation below must be pinned by a test (§13) that asserts the documented
behaviour, so that it cannot be silently "fixed" into a different behaviour.

**Three of the nine closed at 8.0** — items 1, 4 and 7 — with item 4's closure
explicitly partial: it holds for the 46 channels that have a reader and stands
unchanged for the six that do not. (Non-goal 8 was withdrawn in the same pass,
which is what an earlier draft of this paragraph was counting as a fourth
closure.) They are kept, struck through in prose rather than deleted, because a
limitation that disappears without explanation reads as an oversight, and
because each one names a defect class this design must not re-acquire.

1. ~~**Compound rules**~~ — **closed at 8.0.** Per-axis worsening was invisible
   once `GodClassRule` stopped firing; the reader returns those axes whether the
   predicate fires or not, so the growth is an ordinary `regressed` (§5.9,
   deleted). The related worry — that single-metric coverage is partial, since
   WMC and LCOM have rules of their own while TCC, class LOC and WOC do not —
   closes with it: the reader reads metrics, not rules.

   What remains is **one channel, not a class of them**, and round 8 corrected
   the plan on the facts here. Both compound channels declare per-axis onsets and
   the plan had claimed neither does: `GodClassOptions` carries `wmcThreshold`,
   `lcomThreshold`, `tccThreshold` and `classLocThreshold`, each configurable,
   and `DataClassOptions::withOverride()` maps an inline `@qmx-threshold` onto
   `wocThreshold` *and* `wmcThreshold`. The residue is `design.god-class` alone,
   where the inline override moves `minCriteria` — not an axis — so it widens no
   axis allowance. Carrying 7.2's blanket wording into 8.0 was the proxy mistake
   again, and under §7.1's still-debt rule it would have made `resolved`
   unreachable for both channels for ever.
2. **Count fallback** — without a stable occurrence key, one removed plus one new
   occurrence at equal count is indistinguishable.
3. **Renames** — mitigated but not solved by `update`'s debt-neutral
   re-pointing (§8), which requires no-worse captured axes; a rename combined
   with a change still appears as `unobserved` plus `new`. A configuration change
   can rename symbols just as a code change can — the namespace strategy and the
   aggregation prefixes both do — and such an entry is indistinguishable from a
   deletion by the symbol inventory alone. At 8.0 the consequence is retention
   rather than loss: an unmatched rename leaves an `unobserved` entry that only
   `--prune-missing` will clear. The order-dependence of 7.9, where `cleanup`
   could destroy a re-pointing candidate, is gone (§8).
4. ~~**Growth inside a widened policy is invisible**~~ — **closed at 8.0 for the
   46 channels with a reader, and standing for the six without.** Relaxing a
   threshold still widens every allowance under it in one move, which is the
   accepted cost of making policy the source of truth and is visible in the
   `qmx.yaml` diff. But growth *inside* the widened range is now measured and
   reported: `matched` with `withinWidenedPolicy`, carrying captured and current
   values (§7.1). The attribute 7.3 removed as unreachable is reachable, and this
   entry is the reason it was worth reviving. For a channel with no reader the
   old text still holds exactly — no reading, no report.
5. Ratchet is not historical trend analysis.
6. **Unmatched v5 entries are lost, not migrated** (§8). A v5 entry carries only
   a rule name and an opaque hash, so one with no current finding to match
   cannot be reconstructed into v7's structural identity. The user chooses
   between dropping it and aborting the migration; there is no third option, and
   no amount of tooling creates one.
7. ~~**A rule with a magnitude cutoff cannot prove resolution by absence**~~ —
   **closed at 8.0.** Both banded channels are handled by reading rather than by
   inference. `architecture.circular-dependency` reads the cycle set recorded
   before its `maxCycleSize` cutoff, so a cycle that grew past the band is
   `regressed`, not silently resolved. `design.data-class` reads WOC and WMC
   directly, so the finding disappearing as WMC crosses its bound changes
   nothing about what is measured. The band trait that distinguished the two
   shapes is deleted with them (§5.7).
8. **A line-addressed author tag annotates its whole file for entries that carry
   no line** (§5.6). `@qmx-ignore` and `@qmx-ignore-next-line` key on a line
   number, and a baseline entry carries a symbol. Where the entry's symbol has no
   line span to test against, Baseline answers conservatively: a matching tag
   anywhere in the file marks the entry silenced.

   At 7.9 this cost a retained entry, because `suppressed` blocked resolution.
   At 8.0 silencing does not block anything (§5.6), so the residue is smaller
   still: the entry is reported as silenced when it may not be, and its build
   failure is suppressed when it need not have been. The report says which half
   decided, so the user can tell.

   The optional rule-side silencing report §5.6 defers would narrow this further,
   by letting a rule say precisely which scopes its own allow-list covered. It
   buys explanation, not correctness, which is why it is no longer in P1b.
9. **Values measured under different provenance are still compared.** The file
   records a fingerprint of the measurement's provenance — the configuration
   projection *and the analyser build* (§5.7) — and a difference withholds
   `fixed`, but the allowance comparison itself proceeds as usual. Strictly, a
   value produced under different measurement inputs is about as comparable as
   one produced under a different contract, and the rigorous answer would be
   `incompatible`. That answer is not taken, because a routine edit to
   `coupling.framework_namespaces` would then invalidate every coupling entry in
   the file at once. The fingerprint is stored, so the decision can be revisited
   without a file-format change.

   The analyser-build component was added at round 8 and its cost is
   user-visible: **every upgrade suspends automatic `fixed` until the baseline is
   regenerated.** It is not optional. §10 permits changing how a metric is
   computed without bumping any contract, this repository has exactly such a
   change queued for the project-level coupling formula, and without the
   component the release carrying it would delete every entry whose reading it
   moved.
10. **Six channels cannot prove repair, and the reason is scheduling rather than
    impossibility.** `duplication.code-duplication` and the five
    `LayerViolationRule` channels declare no reader (§5.2, §17.4), so their
    entries ratchet while their findings fire and go `unobserved` when they stop.
    No proof can reach them, so `cleanup` never removes them automatically; the
    exit is `cleanup --prune-unprovable`, on the user's own assertion (§8), which
    round 8 added after finding that hand-editing the file was the only answer
    this section offered.

    A second consequence, smaller and worth naming: for
    `architecture.layer-violation` the *explanation* is unobtainable too. Once
    the edge stops firing, nothing left in the run carries its
    (from, to, type) triple, so the silencing query cannot say whether a new
    `allow:` entry silenced it or the dependency is gone (§5.6). Extending the
    snapshot below would close both at once.

    The mechanism that would close this exists and is already built for another
    channel: `architecture.circular-dependency` records its evidence during rule
    execution and reads it afterwards, and duplicate blocks and layer assignment
    are the same shape of fact. Two things make it more than a copy — duplication
    blocks are gated by `min_lines` / `min_tokens` before anything sees them, so
    a snapshot is only sound because the fingerprint covers those keys; and layer
    assignment would have to be recorded per unmatched end rather than per
    diagnostic. Neither is hard. Both are out of scope here so that the six are
    honestly declared rather than half-supported, and adding a reader later needs
    no contract change — which is the property that makes deferring safe.

## 15. Rejected Alternatives

**Absolute ratchet independent of policy (v6).** Rejected, and the reason is
narrower at 8.0 than the one recorded at 7.0. The old reason — the baseline and
the threshold configuration become two policies that drift apart *with no
instrument to reconcile them* — was circular: the instrument was missing because
the design inferred from findings, and inference was chosen partly to avoid the
drift. Measurement supplies the instrument. An entry stores its captured value
and its captured onset, the reader reads the current value, and the report can
say exactly where the baseline is stricter than the configuration — that is what
`withinWidenedPolicy` (§7.1) shows.

What survives the narrowing is a real objection, and only one: an absolute
ratchet **fails the build for growth the team's own policy explicitly permits**.
Raising a threshold to 30 and then reporting a build-breaking regression at 26
teaches users to regenerate the baseline, and `generate --force` re-accepts
everything. So the allowance rule stands as §5.1 states it. But the alternative
is no longer incoherent, and a future `ratchet: absolute` option would be a
reporting choice rather than a redesign.

Recorded dissent: one reviewer preferred the absolute ratchet plus a narrow
`baseline:accept-regression` command. Still not adopted — it adds a second
acceptance surface alongside `qmx.yaml`, and duplicated acceptance paths are how
policy drifts in the first place.

**Retaining observations for every evaluated symbol.** Rejected: O(symbols ×
rules) retention, when the metric repository already holds those values and the
reader queries it for baselined identities only — O(entries), on demand, after
analysis. 7.0's reason for this rejection was that such data "cannot change any
outcome under §5.1", which 8.0 makes false: reading non-violating symbols is
exactly what the design now does. The rejection is unchanged and its reason is
replaced, because an implementer reading the old one could conclude the reader
itself was rejected here.

**Stored debt predicates with a versioned operator DSL.** Rejected: an
expression language embedded in a data file, when the configuration already
supplies the boundary. Note the stored per-axis onset (§6.2) is *not* a revival
of this: it is one number used to explain a resolution, never to decide one.

**Deriving the boundary from `Violation::threshold`.** Rejected: it is
tier-dependent (§2.4), so the allowance would widen as the measurement worsened.

**Adding one `value` field to v5.** Rejected: cannot represent inverted, vector,
occurrence, graph, or dynamic computed-metric contracts.

**Deriving values from violation messages.** Rejected: presentation, unstable,
and intentionally excluded from identity.

**Silently treating v5 as ratchet.** Rejected: v5 has no values, axes,
directions, multiplicity, or coverage evidence.

**Keeping suppress as the default.** Rejected: it preserves the exact blind spot
this feature closes.

**`baseline:accept` in MVP.** Rejected: routine acceptance of new debt conflicts
with ratcheting; the threshold configuration is the acceptance mechanism.

## 16. Layout Decision (Settled)

**Layered, as planned.** Decided before P0 freeze; the reasoning belongs in this
plan's ADR and follows [ADR 0016](../adr/0016-subject-cohesion.md).

By ADR 0010's two-criteria checklist the feature scores one criterion met
(five lifecycle adapters, one of them genuinely multi-stage — stronger than the
Architecture pilot's single debug command), one half-met (the comparator
consumes Analysis-time coverage), and ADR 0012's "analogous complexity" escape
hatch closed: baseline has **zero** `ConfigSchema` entries today, so the
"configuration loader complex enough to live separately" half is not met at all.
Counting criteria therefore decides nothing, which is precisely why ADR 0016
states the underlying rule.

Under that rule the decision is unambiguous, for two independent reasons that
agree:

1. **Subject placement.** The debt-observation contract is "measured debt of a
   symbol" — a cross-cutting primitive of the same kind as `SymbolPath` and
   `Violation`, constructed by every rule and consumed by both Baseline and
   Reporting. It belongs in `Core` by subject, not merely by constraint.
   Coverage is "what this run actually evaluated" — orchestration, so
   `Analysis`. The file schema and comparator are "accepted debt and its file" —
   `Baseline`. The reader contract added at 8.0 changes nothing here: its
   *subject* is what a channel measures, so the contract sits in `Core/Channel/`
   beside the registry, while each implementation lives with the rule that knows
   its metric keys — the same split as the onset provider, and the reason the
   decision below did not have to be reopened.
2. **A slice would be a relabelling.** Once the contract sits in Core, a
   `src/Baseline/{Domain,Processing,Configuration}` slice would contain exactly
   today's `src/Baseline/` content, and adapters stay in `Infrastructure/`
   either way (ADR 0016: "many adapters" is not an argument for a slice). Not
   one dependency edge would change.

Internal sub-directories inside `src/Baseline/` may still be introduced as it
grows — ADR 0016 makes that a refactoring decision, subject to the same tests,
not an architectural one requiring an ADR.

Observation recorded during this analysis, out of scope here: `src/Baseline/
Suppression/` (inline `@qmx-ignore` and `@qmx-threshold` extraction) is a
different subject that happens to share the directory. §5.6 separates the two
concepts; separating them physically is a later, independent piece of work.

## 17. The v8 Inversion — Decision, 2026-08-05

**Decision: replace the negative inference behind `resolutionReason` with
positive proof of repair.**

**Applied at revision 8.0.** This section is kept verbatim as the decision
record — the argument for the inversion, its four sub-decisions and its three
cautions are the rationale the rest of the document now assumes, and P6's ADR
draws on it. §0.10 records what applying it changed. Where this section and the
body disagree in wording, the body governs; where they disagree in *substance*,
that is a defect and §0.10's derived-consequences table is the first place to
look, since everything derived during the rewrite is listed there.

### 17.1 Why the current design cannot be finished by enumeration

§7.1 decides `fixed` by comparing boundaries, because §5.2 leaves no current
observation once a finding stops firing. That makes the decision an **open
negative**: a repair is inferred whenever no known mechanism explains the
disappearance. Four review rounds discovered nine such mechanisms, one or two
per round, and each was folded in as another rule keyed on another proxy:

widened threshold · compound predicate stops firing · magnitude cutoff hides
growth · measurement inputs changed (`coupling.framework_namespaces`,
`exclude_health` renormalisation, layer definitions) · eligibility gate on a
measured value (`minAfferent`, `excludeDataClasses`) · silenced by configuration
· channel deactivated · scope not analysed · symbol renamed

The list has no reason to be complete, and the error direction deletes real
debt: `cleanup` acts on `fixed`. Two reviewers working independently — one
attacking a stated hypothesis, one given only the symptom and blind to it —
converged on the same correction, which is the strongest evidence available
that it is the right one.

### 17.2 The inversion

`fixed` requires a **positive answer from a policy-independent reader** of the
same underlying facts: the axes the entry captured are re-read now, ahead of
thresholds, eligibility gates, band cutoffs and exclusion filters. No reader, no
completeness, or a changed configuration fingerprint yields "not established" —
never `fixed`. An undiscovered tenth mechanism is closed by the same default
that closes the nine known ones.

The consequence worth stating plainly, because §5.1 and §5.2 both move under it:
**the baseline stops being a record of accepted findings compared against
findings, and becomes a record of accepted measurements re-measured each run.**
The finding becomes secondary evidence.

### 17.3 What survives, and why now is the cheapest moment

Everything landed in `main` survives: `DebtObservation` and its axes,
`ContractReference`, `OccurrenceKey`, `WorseDirection`, the coverage contracts,
the status and reason vocabulary, `Violation::$observation`, the `qmx.yaml`
topology. The rule remains authoritative at capture time, so no drift risk is
introduced there.

What changes is mostly **P1a′, which is not written yet**: the channel registry
survives nearly as-is; the onset provider becomes a value reader; the silencing
query drops from safety-critical to reporting-only, since an error in it can no
longer delete an entry; the per-channel measurement digest collapses into one
configuration fingerprint; pre-cutoff evidence becomes a special case of the
general reader protocol rather than its own feature. The cost of switching only
grows from here.

### 17.4 The four sub-decisions

| Question                                                    | Decision                                                                                                           | Consequence accepted                                                                                                             |
| ----------------------------------------------------------- | ------------------------------------------------------------------------------------------------------------------ | -------------------------------------------------------------------------------------------------------------------------------- |
| Symbol absent from complete discovery                       | **not** proof of repair                                                                                            | a rename is indistinguishable from delete-plus-create, so `update` becomes a required part of the lifecycle, not a convenience   |
| Configuration fingerprint granularity                       | one global fingerprint of the resolved configuration, by default                                                   | any `qmx.yaml` edit suspends automatic `fixed` project-wide; a per-channel projection may be offered later, explicitly less safe |
| Where the measurement comes from                            | read the metric repository — no new pipeline phase                                                                 | covers 45 of 52 channels; `AnalysisResult` already carries the repository and `baseline: [core]` already permits consuming it    |
| The six channels whose artefacts die with `AnalysisContext` | `duplication.code-duplication` and five `architecture.*` declare themselves unsupported for automatic repair proof | the guarantee is explicitly partial; those entries are reported as requiring manual review, never auto-cleaned                   |

### 17.5 The decisions the completeness pass forced — settled 2026-08-05

**A. No new status is needed, and `regressed` stops requiring a violation.**
The completeness pass surfaced a state the vocabulary could not express — "the
finding is gone and the debt is still measurable" — and the first proposal was
to add a status for it. Working the decision through dissolved the problem
instead: once a measurement is the basis of the decision, all four outcome
statuses are decidable *without* a finding, and the state falls into the
existing vocabulary rather than beside it.

| Measurement, read policy-independently | Status                        |
| -------------------------------------- | ----------------------------- |
| worse than the allowance               | `regressed`                   |
| better than captured, still debt       | `improved`                    |
| no longer debt at all                  | `resolved`, reason from below |
| otherwise                              | `matched`                     |
| unavailable                            | `unobserved`                  |

The consequence must be stated rather than left to emerge: **§5.1's invariant
that a regression is always also a current violation is retired.** That
invariant was a property of inferring from findings; under measurement a
compound predicate that stops firing while an axis worsens is a plain
`regressed`, which is exactly the case §5.9 was built to work around. §5.9's
special handling of compound channels goes away with it.

**B. One attribute, not a status, carries "no rule reports this any more."**
It qualifies `matched` / `improved` / `regressed` when the status came from
measurement alone. The same attribute covers §14.4's case — captured 10, onset
raised to 20, current 15 — which is `matched` by the allowance rule and worth
showing as growth inside a declared policy. This is the `withinWidenedPolicy`
attribute that revision 7.3 removed as unreachable; under measurement it is
reachable and means what it was introduced to mean.

**C. Exit behaviour follows the allowance, not the visibility.** `regressed`
fails the build exactly as §9.3 already specifies, whether or not a rule
reported the finding. Nothing else newly fails: debt that is unchanged but no
longer reported is shown, not fatal. The ratchet fails on worsening, never on
invisibility — otherwise the first run after adopting this design fails on every
entry whose rule quietly stopped reporting, and the feature is switched off
before it proves anything.

**D. Renames are not repairs** (§17.4), so `update` is a required part of the
lifecycle rather than a convenience, and §8's re-pointing predicate is the only
path for them.

**E. Configuration fingerprint: global by default, projection as an opt-in.**
A single fingerprint of the resolved configuration is the safe default. It is
also genuinely costly on this repository, which tunes thresholds continuously:
every `qmx.yaml` edit suspends automatic `fixed` project-wide, and a `cleanup`
that rarely acts pushes users toward `generate --force`, which re-accepts the
entire debt at once — a worse outcome than the risk it was avoiding. So a
per-channel projection of the configuration is offered as an explicit opt-in,
documented as less safe, with the global fingerprint in force unless chosen.
This is the one decision here taken without confidence; revisit it once real
usage exists.

**F. The reader is self-checked on every run.** Every firing finding yields both
a rule observation and a reader measurement of the same axes. They must agree,
and the check runs at full scale on every analysis rather than in a fixture.
This is the only structural defence against the two implementations drifting,
and it is a Definition of Done item for P1b, not a rationale paragraph.

### 17.6 What the next session does

**Items 1–3 are done — that is revision 8.0. Item 4 is outstanding and is the
next action on this plan.** The list is kept as written so the rewrite can be
audited against its own brief.

The design decisions are settled above; what remains is rewriting and
re-cutting. In order:

1. **Rewrite the affected sections against the reader**: §5.1 (retire the
   regression-implies-violation invariant), §5.2 (the rule is authoritative at
   capture; the reader answers at comparison), §5.9 (deleted — subsumed by A),
   §7.1 (the table in 17.5 A replaces the boundary test; add the attribute from
   B), §7.4 (pre-cutoff evidence becomes one case of the reader protocol),
   §9.1/§9.3 (the attribute and C), §14.4 (closes for the 46 measurable
   channels, stays for the six), §15 (narrow the v6 rejection, whose stated
   reason was circular).
2. **Re-cut P1a′** against the new contract — the registry survives, the onset
   provider becomes the reader, the silencing query drops to reporting-only, the
   per-channel digest collapses into E's fingerprint.
3. **Re-cut P1b's per-channel obligation**: a reader per channel or an explicit
   declaration of no support, plus F's self-check.
4. **Review the rewrite before writing any code.** Four rounds on this document,
   and every round found HIGH findings in the previous round's corrections.
   Reviewing the corrections is not optional here; it is where the yield was.

Two things not to redo: the uncommitted lifecycle work in §6 and §8 (the `scope`
field, the shared re-pointing predicate, the deadlock rule) is unaffected by the
inversion and stands; and the channel inventory needs no new trait columns under
this design, since the reader answers per channel what the columns were being
invented to declare.

### 17.7 Three cautions carried forward

**G. The self-check of F has a blind spot exactly where the risk is.** F pairs
every firing finding's observation with the reader's measurement, which
validates readers against the population of findings that still fire. The
decision that destroys data is the opposite one: the reader claiming a debt is
gone, where by construction no observation exists to disagree with it. A channel
whose findings have all been repaired stops being exercised precisely when its
reader's verdict starts deleting entries. So the run-time pairing is necessary
and not sufficient: P5 owes fixtures where a finding is deliberately removed and
the reader must return "cleared", one per reader shape, plus the dogfooding run
against this repository's own baseline.

**H. "Policy-independent" means independent of the *rule's* policy, not of the
collector's.** The reader reads ahead of thresholds, eligibility gates, band
cutoffs and exclusion filters — but for the measurable channels it reads the
metric repository, and several metrics are themselves computed under
configuration (`coupling.framework_namespaces` shapes `cbo_app` during
Collection; `min_lines` / `min_tokens` decide what duplication exists at all).
That residue is exactly what E's fingerprint covers, and the boundary must be
written that way, or an implementer will claim an independence the design does
not have.

**I. The ratchet and `check` will visibly disagree, and that is new.** With the
invariant of §5.1 retired, an entry can be `regressed` on a symbol that produces
no violation at all — a compound predicate that stopped firing while an axis
worsened is the canonical case. A user reading `qmx check` will see nothing
there. This is correct behaviour and it will read as a bug unless the reports
say why, so P6 owes an explanation of it in the user documentation, and §9.1's
regression line should name the case rather than printing a bare delta.
