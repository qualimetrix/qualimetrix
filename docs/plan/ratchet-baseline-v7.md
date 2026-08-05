# Ratchet Baseline v7 Plan

**Status:** revision 7.9 — **superseded in principle; see §17 before implementing anything**
**Date:** 2026-08-05

> **Read §17 first.** A design round on 2026-08-05 established that the
> `fixed` / `policy` decision in §7.1 rests on an inference that cannot be made
> safe by enumeration, and the decision was taken to invert it. §17 records that
> decision, what survives, and what the next session must rewrite. Everything in
> §5.2, §5.9, §7.1 and §7.4 that derives a repair from the *absence* of a
> finding is pending replacement — do not implement it. The rest of this
> document, including the whole of P1a′ except its onset provider, stands.
**Target release:** TBD
**Review status:** Four rounds complete, all CRITICAL and HIGH findings folded
in, and the trait model since validated against a full inventory of the rule set
(§0.7). P0 closed at 7.7 — see §11 P0.

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

**Decision state.** Settled: the allowance rule (§5.1), observations riding on
violations (§5.2), contract placement in Core (§5.3), the three suppression
categories (§5.6) and who decides `suppressed` (§5.6, settled at 7.9), the file
schema (§6), the status model (§7.1), the lifecycle commands (§8), exit
behaviour (§9.3), layered layout (§16). Open: nothing that blocks P1a′. The one
judgement call deliberately left to implementation is the naming of the
migration disposition-plan schema fields, which P3 specifies.

**Where execution stands.** P0, P1a and the two external prerequisites have
landed. The next package is **P1a′** (§11) — four Core contracts that P1a's own
Definition of Done did not enumerate. P1b, P1c and P3 are blocked on it.

**Review state.** Seven rounds are complete. Rounds five, six and seven all
examined 7.9 before a line of code was written: the fifth its contract
specifications, the sixth the corrections the fifth forced, the seventh the new
design elements the sixth introduced. Each of the three found HIGH findings in
its predecessor's corrections, and all three are summarised at the end of §0.9.
The first four are summarised in §0.2–§0.6
with the correction every finding forced. Round 1 examined 7.0 with three
reviewers; round 2 examined 7.1 with two; rounds 3 and 4 were deliberately
narrow, covering only §5.1, §7.1, and §8 — the sections that had changed
substantially and that this plan's history showed to be its defect sink. §0.7
then replaced the plan's universal claims about the rule set with an actual
enumeration of it.

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

## 1. Executive Summary

Baseline v5 is an identity-only suppression snapshot: once a violation is
listed, it stays hidden however much worse it gets. v7 makes **ratcheting** the
default for newly generated baselines. A baselined finding stays hidden while
its measured debt is within the allowance, and is reported when it exceeds it.
Full suppression remains available through an explicit `mode: suppress` file.

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

## 3. Goals

1. Ratcheting is the default for newly generated baselines.
2. Worsening is detected for scalar, vector, occurrence, presence, and graph
   findings.
3. An explicit full-suppression mode is preserved.
4. Comparison never depends on human-readable messages.
5. Unevaluated scope is conservative: it never resolves or mutates an entry.
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
8. Per-axis ratcheting *inside* a compound rule — §14.1.
9. Introducing a changed-files-only analysis mode (§2.6).

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

Each channel therefore declares a value on each axis below. Totality holds by
construction — there is nothing to fail to map onto — and the registry test
asserts that every declared channel answers every dimension.

The dimensions and their values are **not** proposed from examples: they were
validated against a full inventory of the rule set, recorded in
[`channel-trait-inventory.md`](channel-trait-inventory.md) — 41 concrete rule
classes, 52 channels, every cell filled. That inventory is the artefact this
plan should have started from; P1b's first task is to keep it in sync as code,
not prose.

| Dimension        | Values                                                          | Notes                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                         |
| ---------------- | --------------------------------------------------------------- | ----------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| Onset source     | none / configured tiers / symbol-conditioned / run-conditioned  | `ClassRankRule` is run-conditioned: its thresholds scale by project class count                                                                                                                                                                                                                                                                                                                                                                                                                                               |
| Comparison       | inclusive / exclusive, **declared per boundary**                | Follows direction by an existing, documented convention: higher-is-worse compares inclusively (the threshold is the first *bad* value), lower-is-worse strictly (it is the first *acceptable* one — see the rationale comment in `MaintainabilityOptions::getSeverity()`). Declaring it is mechanical, not a judgement call. One exception: `CircularDependencyOptions::getSeverity()` mixes strict `>` for its cutoff with inclusive `<=` for its error tier, which is why the value is per boundary rather than per channel |
| Direction        | higher-is-worse / lower-is-worse                                | per axis                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                      |
| Firing predicate | single threshold / conjunction / criteria count / unconditional | `DataClassRule` conjunctive, `GodClassRule` criteria count                                                                                                                                                                                                                                                                                                                                                                                                                                                                    |
| Band             | unbounded / cutoff                                              | a cutoff hides the finding as debt grows (`maxCycleSize`)                                                                                                                                                                                                                                                                                                                                                                                                                                                                     |
| Magnitude        | none / scalar / vector / count                                  | `CodeDuplicationRule` carries a scalar *and* an occurrence identity                                                                                                                                                                                                                                                                                                                                                                                                                                                           |
| Identity         | symbol / occurrence / graph                                     | independent of magnitude                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                      |

The **onset provider** is the query behind the first dimension: given a channel,
a symbol, its metrics, and run-level context, it returns the current onset and
direction per axis, or reports that the channel has none. Run-level context is
required, not optional — without it `ClassRankRule` is unrepresentable. The
provider must reproduce the rule's *actual* behaviour rather than a symmetric
idealisation of it: `LongParameterListRule`'s VO branch bypasses
`getEffectiveOptions()`, so an inline `@qmx-threshold` applies to ordinary
methods only, and the provider must say so.

Three onset sources are forbidden, each because a revision of this plan used it
and was wrong: `Violation::threshold` (it is the tier the measurement landed in,
so the allowance widens as the code worsens, §2.4); the field literally named
`warning` (`ComputedMetricRule` tests `errorThreshold` first and nothing
validates tier ordering, so `warning: 20, error: 10` starts violating at 10);
and any value keyed by channel alone (the onset can depend on the symbol or on
the run).

#### The invariant, stated per trait rather than per shape

`allowance` is never stricter than the onset, so **a regression is also a
current violation** for every channel whose firing predicate is a single
threshold and whose band is unbounded. Two trait values break it, and both are
handled differently, and not in the same way as each other:

- **conjunction / criteria count** — the predicate can stop firing while an axis
  worsens. Such a channel still resolves when it stops firing, because under
  §5.7 the configured policy decides what counts as debt, but always with reason
  `policy` and never `fixed`, so `cleanup` leaves the captured axes in place
  (§5.9).
- **cutoff** — growth past the band hides the finding entirely, so silence
  proves nothing and resolution requires pre-cutoff evidence (§7.4).

§13 requires the invariant to be tested per trait combination present in the
codebase, not asserted in general.

### 5.2 Observations accompany violations

A rule attaches a structured observation to each `Violation` it emits. There is
no separate observation channel, no second traversal, and no change to
`RuleInterface::analyze()`'s return type. Rules remain stateless.

`Violation` gains one observation-carrying member. Because `Violation` already
carries a constructor-overinjection threshold override, the observation is a
single bundled value object, not a set of new scalar parameters.

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

### 5.6 Three suppression categories, matched to the code

7.0's two-way split contradicted the implementation (§2.5). The corrected
taxonomy:

| Category                       | Mechanisms                                                                                                                                                                                                                                                                    | Comparison             | Report       | May mutate entry |
| ------------------------------ | ----------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- | ---------------------- | ------------ | ---------------- |
| **Not evaluated**              | discovery `exclude`, `@generated` stripping, `disabled_rules`, `only_rules`, `rules.<name>.enabled: false`, rule applicability filters (`GodClassRule`'s `minMethods`/`excludeReadonly`, LCOM and Maintainability preconditions), parse failure, worker failure, interruption | impossible             | `unobserved` | no               |
| **Area silenced by config**    | `exclude_paths`, `exclude_namespaces`, per-rule exclusions, architecture `exclude:` blocks, occurrence whitelist filters, magnitude cutoffs such as `maxCycleSize` (§5.1)                                                                                                     | possible — data exists | `suppressed` | no               |
| **Finding silenced by author** | `@qmx-ignore`, `@qmx-ignore-file`, `@qmx-ignore-next-line`                                                                                                                                                                                                                    | possible               | `suppressed` | no               |

This list of mechanisms is **normative, not illustrative**. P2 must enumerate
every suppression and skip path in the codebase and assign each to a category;
a mechanism fitting none of the three is a finding against this plan, not a
judgement call for the implementer. Round 2 found six missing from the first
draft, so the enumeration should be treated as the harder half of that package.

`suppressed` is a reported outcome and therefore a bucket in the summary line
(§9.1), not a silent state — otherwise the per-status counts cannot sum to the
number of baseline entries.

The second category is the correction: those symbols *were* measured, so
claiming `unobserved` would be false. They are nonetheless never mutated —
silently resolving entries in a silenced area means un-silencing later
resurfaces everything as `new`.

#### The carrier for the second category, and who decides `suppressed` (7.9)

§7.1's precedence step 4 has to decide `suppressed` for an entry that produced
**no current finding at all** — a symbol under `exclude_paths` emits a violation
that is filtered away, and a symbol under a per-rule exclusion never reaches the
filter pipeline at all, because `RuleExecutor` drops it the moment
`$rule->analyze()` returns. A filter cannot classify what never flows through
it. The decision is therefore a **query answered inside comparison**, not a
pipeline stage that rewrites a status from outside. 7.8 left both readings
open; this is the one recorded.

The query is implemented by P2, which holds the exclusion configuration, the
layer registry, and the file inventory. It is **run-scoped**, not
configuration-scoped: the architecture `exclude:` blocks are decided against the
run's own class facts, so a configuration-only oracle could not answer for them.

Its contract joins the existing `Core/Suppression/` subject rather than opening
a new directory beside it. The justification is the subject, not the dependency
edge — "a finding being silenced" is exactly what that directory is already
about, `suppressed` is already the status name in §7.1, and a second directory
would split one subject across two synonyms. (`baseline: [core]` also requires
Core, but ADR 0016 is explicit that when the constraint and the subject
disagree the layout is wrong, not the constraint, so the constraint is not the
argument.)

That directory currently also holds `ThresholdOverride` and
`ThresholdDiagnostic`, which are about `@qmx-threshold` — a different subject
that happens to arrive through the same docblock scanner. Review flagged it and
it is a real cohesion defect, but it is **not** this package's to fix:
`ThresholdOverride` is referenced from `AnalysisContext`, from rules, and from
configuration, so moving it would break P1a′'s own rule that no rule or Analysis
file is touched. Recorded here as a follow-up rather than silently tolerated.

**The verdict is two-valued: `silenced` or `not silenced`.** A draft of this
subsection made it three-valued, on the premise that two mechanisms could not
be evaluated for an entry that produced no finding. Review disproved the
premise for the one that mattered, and the correction is worth the space
because the three-valued version was not merely redundant — it was unusable.

Enumerated against the code, with the source each answer is computed from:

| Mechanism                                          | Address it keys on                                | Answered from                                                       |
| -------------------------------------------------- | ------------------------------------------------- | ------------------------------------------------------------------- |
| global `exclude_paths` / `exclude_namespaces`      | file path / namespace                             | configuration + the entry's own symbol                              |
| per-rule `exclude_paths` / `exclude_namespaces`    | (**declaring rule's own name**, path / namespace) | configuration + the entry's own symbol + the registry's reverse map |
| architecture `exclude:` blocks                     | layer membership                                  | the run's class facts, via the layer registry                       |
| architecture `allow:` / `relations:` entries       | the (from-layer, to-layer, dependency type) edge  | the run's class facts, via the layer policy                         |
| any path by which a rule discards a measured entry | whatever that path keys on                        | **the rule, as a sparse silencing report** — see below              |
| magnitude cutoffs (`maxCycleSize`)                 | the finding's own magnitude                       | **outside this query** — §7.4's band evidence decides them          |

The second row's emphasis is load-bearing and easy to lose. `RuleExecutor`
applies per-rule exclusions under `$rule->getName()` — the executing class's own
name — not under the `ruleName` of the violation it drops. For the four
`architecture.*` diagnostics those differ, so
`rules.architecture.layer-violation.exclude_namespaces` silences all five of
that class's channels, while configuration written under a diagnostic's own name
never fires. A silencing query keyed on the entry's channel — the natural
reading of a channel-addressed contract — would answer "not silenced" for a
scope `RuleExecutor` did silence, and the entry would resolve and be deleted:
data loss inside a silenced area, which §5.6 exists to prevent. The query must
therefore reproduce the executor's keying, which means resolving channel →
declaring rule through the registry. That makes the registry an input to the
silencing implementation, and P2's dependency list must say so.

The fourth row is deliberately phrased as a *behaviour*, not as an interface.
Naming `EntryFilteringOptionsInterface` would have been the natural shorthand
and would have missed a second discard path in the same rule:
`BooleanArgumentRule` also drops entries by `flag_promoted_properties`, through
its own `shouldIncludeEntry()` override, without touching that interface. A
normative list keyed on an interface would classify one of a rule's two
silencing paths and silently omit the other.

**Why the whitelist is decidable after all.** It does not filter findings; it
filters *metric entries*, before a violation is ever constructed
(`AbstractCodeSmellRule::analyze()` reads `codeSmell.{type}` entries and skips
the allowed ones). Those entries stay in the metric repository, which is
carried on `AnalysisResult`, and each carries its `line` and its `extra`. So
"did this rule's allow-list silence anything in this scope" is exactly
computable — and it is computable *by the rule*, which is the only party that
holds both the entries and the allow-list.

That gives the third instance of a shape this plan now uses three times: **a
central answer plus a sparse rule-side report.** Coverage works that way
(§5.5), band evidence works that way (§7.4), and silencing works that way too —
a rule with its own allow-list reports the scopes it silenced, opt-in, sparse,
never a per-symbol map. The parallel is not cosmetic: it means P1b implements
one familiar pattern three times instead of three different ones, and it means
no mechanism in the table above needs a "we cannot tell" answer.

**A silencing report is addressed by the same identity as the observation** —
the occurrence key where the channel has one, and (symbol, channel) where it
does not. Saying only "sparse" is not enough, and the gap is not academic: the
finest scope `AbstractCodeSmellRule` names without computing an occurrence key
is the *file*, because it emits violations with the file's `SymbolPath` while
filtering individual metric entries inside it. A file-grained report would mark
every baseline entry of that channel in that file as silenced on the strength of
one allowed parameter name — reproducing at "channel × file" exactly the scale
error that the two-valued verdict was introduced to remove, and swallowing both
`resolved` and a counted `regressed` at step 4.

The identity must therefore be canonicalised in one place and asserted across
both paths — the same requirement, for the same reason, that §7.4 already places
on the pre-cutoff snapshot. Two independent ways of naming the same finding
never match, and a report that never matches silences nothing while a report
that matches too widely silences everything.

A silencing report is emphatically **not** a coverage deviation. Those scopes
were evaluated — the metric exists, the entry exists, the rule looked at it and
chose not to report. Filing them under coverage would claim `unobserved` for a
scope §5.6 classifies as measured, which is the distinction the whole
three-category taxonomy exists to preserve.

**The one mechanism that stays undecidable is not in this query.** A
line-addressed `@qmx-ignore` cannot be evaluated for an entry that carries a
symbol and no line. That is category three, it is answered inside Baseline
(below), and it is Baseline's own conservative call — it never reaches this
contract. §14.8 records the residue.

**Magnitude cutoffs are outside this query entirely.** They are silencing in
the §5.6 sense, but §7.4's band evidence decides them, and listing them here
would make §7.4's `absent` branch dead on arrival: the query would answer
`silenced` for `architecture.circular-dependency` at step 4, and step 5 would
never be reached.

One carve-out reverses the obvious reading and must not be re-derived by
whoever implements this: `PathExclusionFilter` and `NamespaceExclusionFilter`
both pass `architecture.*` violations through **unconditionally**, so for those
channels the global exclusions silence nothing at all.

The third category stays out of the run-scoped query. `@qmx-ignore` records are
extracted during collection, reach `AnalysisResult::$suppressions`, and are
already consumed by `src/Baseline/Suppression`, so Baseline answers that half
itself and takes the silenced answer if either half gives one. Where the entry
carries no line and the tag is line-addressed, Baseline answers conservatively —
a matching tag anywhere in the entry's file silences the entry — because
`suppressed` neither mutates nor resolves, so the conservative direction costs a
retained entry while the other costs a deleted one. The report names which half
decided; "silenced by configuration" and "silenced by an author tag in this
file" are different things for the reader.

Implementation note, and an explicit scope item rather than a description of
today's behaviour: the comparison must run **after** evaluation-exclusion and
**before** presentation-suppression. `ViolationFilterPipeline` currently applies
the baseline first, so P2/P4 must reorder it. P2's Definition of Done owns the
coverage gate; P4 owns the pipeline order.

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

#### The registry contract (7.9)

Shape only; population stays P1b's (rules declare) and configuration's
(computed metrics, above). Four types, each justified by a consumer that
cannot be written without it:

| Type                        | Carries                                                                                                                        | Consumer that needs it                                                                                                           |
| --------------------------- | ------------------------------------------------------------------------------------------------------------------------------ | -------------------------------------------------------------------------------------------------------------------------------- |
| `ChannelContract`           | the channel, its `ContractReference`, its declared `ObservationKind`, its axis declarations, and the four channel-level traits | P3 compares it against the file manifest (§6.1); a difference at equal version is `incompatible`                                 |
| `AxisContract`              | axis name, worse direction, epsilon, and the declared onset comparison (inclusive / exclusive / not applicable)                | the manifest stores exactly `worse` and `epsilon`; the comparison is what §7.1's reachability argument turns on                  |
| `ChannelRegistryInterface`  | which declaration covers this channel — an enumerated one, an open-ended one, or none — and whether it is active in this run   | §7.4's `orphaned` test (no declaration) and §7.1 step 2 (declared but inactive → `unobserved`); never keyed on rule class names  |
| `ChannelDeclaringInterface` | the rule-side source: the channels a rule can emit, with their contracts                                                       | P1b. Opt-in interface rather than a method on `RuleInterface`, so the contract package lands green and the breakage stays in P1b |

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

**A channel covered by an open-ended declaration but absent from this run is
`unobserved`, and something must actually say so.** An earlier draft asserted
that outcome without naming a producer for it, and the omission was fatal in a
way that is easy to miss: central coverage knows which *rules* were enabled,
not which violation codes a rule chose to emit, so it cannot see that
`health.typing` disappeared while `computed.health` ran normally. The entry
would then sail through steps 1–4 to `resolved`, and — with no definition left
to supply a boundary — the onset provider would answer "no onset by contract",
which §5.7 maps to `fixed`, and `cleanup` would delete it. Three supported
operations reach that state: `--exclude-health=<dim>`, `enabled: false` on a
computed metric, and simply removing its thresholds from YAML.

So the registry answers activity as well as declaration, and §7.1 step 2 reads
it: declared-but-inactive is `unobserved`, exactly like a disabled rule, and
recoverable by putting the configuration back. This is the second consumer that
makes the activity question real rather than decorative.

**Activity has two producers, and they union.** The registry is not the only
thing that can say a channel went unobserved: §5.5's sparse coverage deviations
already carry the case of a rule disabling one of its own levels
(`ComplexityRule` does this per level). The two are owned by different packages
— the registry by P1b, coverage by P2 — which is precisely the shape §7.4 warns
about, two packages implementing two behaviours and each passing its own tests.
The rule is therefore stated rather than left to fall out: **any source saying
"not observed in this run" wins**, and a channel is treated as observed only
when neither says otherwise. An implementer who reads the activity question as
covering only the open-ended case will send `complexity.cyclomatic.class`
straight to `fixed` when its level is disabled.

The four channel-level traits, and the branch each one feeds:

| Trait            | Values                                                                            | Why comparison needs it                                                                                                |
| ---------------- | --------------------------------------------------------------------------------- | ---------------------------------------------------------------------------------------------------------------------- |
| Onset source     | none / configured tiers / symbol-conditioned / run-conditioned / collection-gated | it says **how** to obtain the reason, never that a reason is impossible — see immediately below                        |
| Firing predicate | single threshold / conjunction / criteria count / unconditional                   | a predicate over **two or more measured axes** resolves as `policy` only (§5.9) — a conjunction over presence does not |
| Band             | unbounded / cutoff on identity / cutoff on an axis                                | the two cutoffs resolve by different rules and must not be conflated — see below                                       |
| Coverage scope   | symbol / channel                                                                  | `channel` means the entry's own symbol coverage is insufficient and the whole channel's input must be complete (§7.4)  |

**`onsetSource: none` makes `fixed` the correct reason, not an impossible
one.** An earlier draft of this subsection said the opposite, and it would have
killed `cleanup` for roughly twenty of the fifty-two channels — every
unconditional code-smell and security channel. The reason test (§7.1) asks
whether the boundary in force now is more permissive than the captured one. A
channel with no boundary at all has nothing that could have been widened, so
absence of the finding under proven coverage, with silencing ruled out at step
4, is exactly what `fixed` means: the `goto` was deleted. `policy` is for a
boundary that exists and moved, and for compound predicates per §5.9.

**`collection-gated` is a fifth onset source, and it exists because one channel
has a boundary the rule never sees.** `duplication.code-duplication` emits a
violation for every `DuplicateBlock` it is given — the inventory correctly
records its trigger as unconditional, and its configured tiers only choose a
severity. But blocks below `min_lines` / `min_tokens` are never *detected*:
`DuplicationDetector` drops them during Collection, before any rule runs. Both
of the other readings are wrong and wrong in opposite directions — reading the
tiers takes the onset from the severity tier, which §5.1 forbids outright, and
reading the trigger as unconditional yields "no onset" and therefore `fixed`,
so raising `min_lines` from 5 to 20 would report every vanished block as fixed
and `cleanup` would erase it, with not one duplicated line removed. The onset
for this channel is `(min_lines, min_tokens)`, and the provider reads the same
options the detector reads. P1b assigns this trait value per channel from the
inventory rather than by analogy; duplication is the case known today, not
asserted to be the only one.

**Configuration can move the measurement instead of the boundary, and the
reason test must not read that as a fix.** §7.1 compares boundaries because it
assumes the only bloodless way for a finding to vanish is a widened boundary.
That assumption is false in this codebase, and the counterexamples are not
exotic:

- `coupling.framework_namespaces` — under `scope: application`, `CboRule` reads
  a metric the collector computed *while excluding* the configured framework
  namespaces. Adding one namespace to that list drops CBO below the threshold
  with the boundary untouched and not one dependency removed.
- `exclude_health` and per-metric `enabled: false` — excluding a dimension
  renormalises the weights of `health.overall`, so the score rises and the
  finding disappears with `warning` and `error` exactly where they were.
- The namespace strategy and aggregation prefixes rename symbols, which makes
  an entry's symbol "absent" without a line of code changing — see §14.3.

Comparing boundaries cannot see any of this, so the entry resolves as `fixed`
and `cleanup` erases standing debt. The fix is to compare more than the
boundary: an entry records a **digest of the configuration its measurement
depended on**, and a difference in that digest forces reason `policy`. A digest
is the right shape rather than an enumeration of keys, because the list above is
what is known today and the next such key will be added by someone who has never
read this section. What must be enumerated is which keys feed which channel's
digest, and that is P1b's job alongside the trait columns.

Three constraints on the digest, each closing a way to make `fixed` unreachable
outright:

- **It covers measurement inputs only, never boundaries or severities.** The
  natural implementation — hash the rule's options block — sweeps the thresholds
  in with them, so the first edit to any threshold in `qmx.yaml` would force
  `policy` on every entry of that rule for ever, duplicating and overriding the
  boundary comparison built for exactly that case.
- **It is taken from the resolved configuration**, after defaults, presets and
  CLI overrides, with canonically ordered keys — so two spellings of the same
  effective configuration digest identically. Taken from raw YAML instead, a
  baseline captured locally and a CI run under `--preset=ci` disagree on every
  entry, and `policy` becomes a constant.
- **Its algorithm is the file's pinned `hash_algorithm`** (§6.1), whose
  description is widened to name this third use alongside occurrence keys and
  content hashing. A feature-detected digest is the portability defect §6.1
  already refuses elsewhere.

This bounds the reason test only. Whether values measured under different
inputs should be *compared* at all — arguably they are as incomparable as a
changed contract — is deliberately left open in §14.9 rather than settled here,
since the conservative answer would flood a routine configuration change with
`incompatible`.

**A band on an identity and a band on an axis are different traits, and the
criterion is not "what the cutoff compares".** The test is: *does the cutoff
hide an enumerable set of identities that something in the run still holds?* It
is deliberately not "is the cutoff applied to an axis", because
`architecture.circular-dependency` — the reference case for cutoff-on-identity —
compares `cycleSize`, which is precisely the axis it reports. Classifying by the
operand would file it under cutoff-on-an-axis, hand it to §5.9, and §5.9 does
not cover it (its trigger is a single threshold), leaving the pre-cutoff
machinery dead with nothing to replace it. What makes it identity-banded is that
the full cycle set survives the cutoff and can be enumerated; what makes
`design.data-class` axis-banded is that nothing survives — the predicate simply
stops being true.

§7.4's
pre-cutoff evidence works by enumerating identities the rule saw before its
cutoff, which presupposes that the cutoff hides *identities*
(`architecture.circular-dependency`: cycles above `maxCycleSize`). The
inventory records a second banded channel of a different shape:
`design.data-class` has its cutoff on the **WMC axis**, and that axis is not
even reported — the finding simply stops firing as WMC grows. There is no
identity set to snapshot, so a producer would answer `unknown` forever and
every `design.data-class` entry would be permanently `unobserved`, contradicting
§5.9, which already governs that channel: a compound predicate that stops
firing resolves with reason `policy` and is never auto-removed. So a
cutoff-on-an-axis channel is decided by §5.9 and never asks for band evidence;
only cutoff-on-identity channels do. §14.7's claim that only
`architecture.circular-dependency` is banded is corrected accordingly.

The coverage-scope trait has no column in the inventory yet, so it is the one
trait whose value is not yet established for all fifty-two channels. P1b adds
the column as its first task — the inventory is assigned to P1b in §11 — and
the plan does not assert values it has not enumerated.

**The inventory's first column is not this trait, despite matching names.**
Inventory column 1 is *Threshold source* (`none` / `configured tiers` /
`symbol-dependent` / `run-dependent`) and describes where a channel's
**severity tiers** come from. The onset trait describes where its
**violation-onset boundary** comes from, and §5.1 forbids deriving one from the
other. The two disagree in the codebase, not just in principle: the inventory
itself records that `getSeverity()` is dead code for the code-smell channels and
unreachable-false for the security ones, and `duplication.code-duplication` is
the case above. So P1b derives this trait from each rule's emission path, using
the inventory as the *enumeration* of what must be classified — not as the
source of the value.

**Two of §5.1's seven inventory dimensions are deliberately not stored**, and
the enumeration that settled it is worth recording, because both look like
omissions:

- **Magnitude** is not a property of the contract. The inventory's magnitude
  column describes what each rule puts in `Violation::metricValue` *today* —
  which §2.3 already disqualifies as a debt contract, and which v7 exists to
  replace. Copying it into the registry would re-import the thing the design
  removed. What the observation carries is its axes, and the axis declarations
  above state that directly. The two disagree in the codebase and will keep
  disagreeing: `design.god-class` records magnitude `count` because
  `metricValue` is `matchedCount`, while §5.9 requires its observation to carry
  the raw underlying metrics as a vector.
- **Identity** is not total over the rule set, which is why it cannot be a
  required declaration. `architecture.coverage` emits one aggregated
  project-level violation per run and answers none of `symbol` / `occurrence` /
  `graph`; the inventory records a literal `?` for it. What the consumers
  actually need from that dimension is two separate things, and both are
  already stated elsewhere: whether the finding is individually addressable
  (the `ObservationKind` and the presence of an `OccurrenceKey`) and whether
  its coverage question is per-symbol or channel-wide (the coverage-scope
  trait above).

**The kind is stored, not derived.** Deriving it from (identity, magnitude)
was checked against all 52 inventory rows and fails twice over: it has no
branch for `architecture.coverage`, and it maps `duplication.code-duplication`
— whose line count genuinely drives its severity — onto the same value as the
eighteen occurrence channels that carry no magnitude at all.

**What is versioned and what is not**, because `ChannelContract` now carries
both and the two behave oppositely:

- The **comparable shape** — kind, axis names, directions, epsilon, and the
  declared onset comparison — is the contract. A change to any of it requires a
  version bump, and a difference at equal version is `incompatible`. The
  comparison is added to §6.1's manifest for exactly the reason the other four
  are already there: `resolutionReason` compares boundaries as numbers, so
  moving an operator from inclusive to exclusive makes the boundary strictly
  more permissive while leaving the number untouched — the entry would read as
  `fixed` and `cleanup` would delete debt that never went away. Both operators
  are in live use across the rule set — higher-is-worse channels compare
  inclusively (`ComplexityOptions`), lower-is-worse ones strictly
  (`MaintainabilityOptions`) — so a channel changing direction or convention
  changes the operator without changing any number. (An earlier draft cited
  `CircularDependencyOptions::getSeverity()` as mixing the two in one method. It
  does, but neither of its operators is an onset comparison: the `>` is the band
  cutoff and the `<=` picks a severity tier. The citation was wrong; the design
  is not.)
- The **four traits** are properties of the current run, not of the contract.
  They never bump the version and never produce `incompatible`. A channel that
  gains a cutoff, or whose coverage scope widens, is still comparable against
  entries captured before the change; what changes is which branch decides
  them. Stating this both ways round is deliberate: an implementer who guesses
  the other way turns a purely behavioural change into a mass `incompatible`
  across every entry of the channel.

#### The onset provider contract (7.9)

§0.8 listed three gaps; this is a fourth of exactly the same shape, found
while specifying them. §7.1 decides `resolutionReason` by comparing the
captured onset against **the onset in force now**, for a symbol that is not
violating — so the value cannot come from an observation, and §5.1 puts it
behind an onset provider. No Core type expresses that query today, and
`baseline: [core]` means the query contract has to be there.

Both halves live in `Core/Channel/` beside the registry: the onset is what a
channel applies to a symbol, so it is the channel's subject, not a subject of
its own. The split into two interfaces is forced by lifetime rather than by
taste:

- **Rule-side.** Given a channel, a symbol, and the analysis context, answer
  with the current onset per axis. The context parameter is required —
  `ClassRankRule` scales its thresholds by the project's class count, and
  `LongParameterListRule` picks its boundary from the symbol's own metrics, so
  neither is answerable from the channel alone.
- **Run-scoped.** The same question without a context parameter, for
  consumers that run after rule execution. `AnalysisContext` is built for
  `RuleExecutor::execute()` and is not carried on `AnalysisResult`, so a
  comparator running in the filter pipeline has no context to pass. The
  run-scoped implementation is the one thing that holds it.

**The answer has four values**, because a boundary can be absent, numeric, or
neither, and because the symbol it applies to may be gone:

| Answer                         | Meaning                                                                  | What §7.1 does with it                                     |
| ------------------------------ | ------------------------------------------------------------------------ | ---------------------------------------------------------- |
| the onset, per axis            | the boundary in force now                                                | compare against the captured onset — `fixed` or `policy`   |
| no onset, by contract          | the channel's onset source is `none`; there is no boundary and never was | `fixed` (nothing could have widened — see the trait above) |
| a non-numeric onset            | the boundary exists but is a configured mode, not a number               | `policy` — a mode switch is a policy change, never a fix   |
| not answerable for this symbol | the symbol is absent from this run, or its metrics are                   | see below                                                  |

The third answer is not a placeholder: `architecture.coverage` fires according
to a configured `mode` (`ignore` / `warn` / `error`). Without it an implementer
must pick one of the other three, and the natural pick — "no onset, by
contract" — turns switching that mode to `ignore` into a report of fixed code,
with `cleanup` deleting the entries. Numeric comparison is not the only way a
boundary can move.

But a non-numeric onset must also be **recorded**, or the answer degenerates
into a constant. If the entry stores no mode, comparison has nothing to compare
against, every run answers `policy`, and the channel's entries survive `cleanup`
for ever — including after the layer map is completed and the coverage gap
genuinely closed. So the entry stores the captured mode as an opaque token, and
the test is the same one every other axis uses: a boundary no more permissive
than the captured one admits `fixed`; a widened one (`error` → `warn` →
`ignore`) gives `policy`.

The same channel also has a magnitude nobody declared: it fires on the count of
unmatched ends, which is an ordinary higher-is-worse count. P1b declares it as
an axis. Otherwise the channel sits outside the ratchet entirely and a project
going from three unclassified classes to a hundred reports `matched`.

The last value is not an edge case: `resolutionReason` is computed exactly
when the finding is gone, and the commonest way for a finding to be gone is for
the symbol to have been deleted. A symbol-conditioned channel then has no
metrics to read. Collapsing that into "no onset" would report `policy` for
deleted code — the least ambiguous `fixed` there is — and `cleanup` would never
remove it. So: a symbol absent from a **complete** discovery resolves as
`fixed`, on the coverage evidence of §7.4 rather than on a boundary comparison;
a symbol absent from an incomplete one is `unobserved`, as it already is. This
rule overrides §5.9's blanket refusal of `fixed` for compound channels, and
§5.9 now carries the carve-out explicitly — the two were written to give
opposite answers for a deleted God class.

### 5.8 Vector comparison is strict Pareto ratcheting

A vector finding is not worse only when no axis exceeded its allowance beyond
epsilon. Any worsened axis is `regressed`; improvements elsewhere never
compensate.

- A **contract shape change** — the registry's axis set differs from the
  manifest's — is `incompatible`.
- A **null axis value** is not a shape change. An axis whose metric is
  unavailable is skipped in comparison; a transition between null and numeric is
  recorded and reported, never treated as improvement or regression.
- NaN and infinity are invalid as observations and as serialised values.

### 5.9 Compound rules ratchet on their firing identity

This section covers every channel whose firing predicate is a predicate over
**two or more measured axes, any of which can worsen while the predicate stops
firing**. That is the property the section's whole argument rests on, and 7.9
had to state it this way after review found the earlier wording — "a
conjunction or a count of matched criteria" — capturing channels it was never
about.

The inventory marks five channels conjunction or criteria-count, and they do
not all belong here. Enumerated, because a rule about a set requires the set:

| Channel                         | Predicate                                                                                                          | In scope? |
| ------------------------------- | ------------------------------------------------------------------------------------------------------------------ | --------- |
| `design.data-class`             | WOC ≥ t **and** WMC ≤ t — two measured axes                                                                        | yes       |
| `design.god-class`              | at least `minCriteria` of four measured tests                                                                      | yes       |
| `architecture.layer-violation`  | both ends resolve to layers **and** the edge is not allowed — a predicate over *presence*, carrying no axis at all | **no**    |
| `architecture.coverage`         | mode is not `ignore` **and** unmatched ends remain                                                                 | **no**    |
| `architecture.potential-shadow` | a class matches more than one layer                                                                                | **no**    |

The three excluded channels have no measured axis that could worsen unnoticed,
so the reasoning below simply does not apply to them, and applying it anyway
had a concrete cost: `architecture.layer-violation` is the flagship channel of
the whole architecture feature, its normal repair is deleting one dependency
edge, and under the trait-keyed wording every such repair would have resolved
as `policy` and stayed in the baseline for ever. `cleanup` acts only on
`fixed`.

The lesson is the same one §5.1 learned twice: a rule keyed on a **proxy** for
a property — here the inventory's trigger column — meets members where the
proxy and the property diverge. The two genuine members are in the codebase:

- `DataClassRule` fires only when `WOC >= threshold` **and** `WMC <= threshold`.
- `GodClassRule` fires when at least `minCriteria` of four criteria match, and
  its evaluable set is value-dependent: the LCOM criterion is vetoed when
  TCC ≥ 0.5.

A previous revision restricted this section to `GodClassRule` because a reviewer
correctly pointed out that `DataClassRule`'s criteria are fixed. The inference
was wrong: what matters is not whether the criterion set is dynamic but whether
the predicate can **stop firing while one axis worsens**, and a fixed
conjunction does exactly that — WOC rising from 80 to 90 while WMC crosses its
own bound silences the rule, and the WOC regression disappears with it.

Two consequences, which must not be run together:

1. **Axis set drift.** If observation axes tracked criterion evaluability, a
   legitimate cohesion improvement would change the axis set and report
   `incompatible`. Compound axes are therefore the **raw underlying metrics**,
   fixed by the contract, independent of which criteria were evaluable.
   Unavailable metrics are null axes (§5.3).
2. **The predicate stops firing.** The finding then emits no observation.
   Detecting the hidden worsening would require observations for non-violating
   symbols, which reinstates the memory cost the design removed.

v7 accepts (2) deliberately: while the rule fires, axis worsening is caught;
once it stops, the finding is resolved by the configured policy, consistent with
§5.1. Coverage by single-metric rules is **partial** — `complexity.wmc` and
`design.lcom` exist; TCC, class LOC, and WOC have none, and `DataClassRule`'s
WMC semantics are inverted relative to `complexity.wmc`. Recorded in §14.1.

**Resolution of a compound entry never carries reason `fixed` — except when the
symbol is gone** (added 7.9, after review found the two rules colliding). The
carve-out is narrow and its reasoning is the whole justification for the rule it
qualifies: this section forbids `fixed` because a compound predicate can stop
firing while an axis worsens, so absence proves nothing about the code. A symbol
absent from a *complete* discovery is the one case where that argument does not
apply — there is no axis left to worsen and no predicate left to tighten. Absent
this carve-out, §5.7's rule for deleted symbols and this paragraph give opposite
answers for a deleted God class, `cleanup` acts only on `fixed`, and the two
packages that own the halves would each have passed their own tests.

The reason test
(§7.1) compares current onset against captured onset, and such a channel has no per-axis
onset to compare — worse, `GodClassOptions::withOverride()` maps an inline
`@qmx-threshold` onto `minCriteria`, so a policy change alone can stop the rule
firing. A compound entry that stops firing is therefore resolved with reason
`policy`, which `cleanup` will not remove. This is deliberately conservative: it
keeps the captured axes for the case where the predicate is later tightened
again, at the cost of compound entries needing manual removal.

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

| Field            | Contract                                                                                                                                          |
| ---------------- | ------------------------------------------------------------------------------------------------------------------------------------------------- |
| `version`        | Exactly `7`                                                                                                                                       |
| `mode`           | `ratchet` or `suppress`                                                                                                                           |
| `generated`      | ISO 8601, from an injected clock                                                                                                                  |
| `hash_algorithm` | Explicit and pinned; names the algorithm used for occurrence keys, content hashing, and measurement-input digests (§5.7). Never feature-detected. |
| `scope`          | The analysed path set that produced this file, normalised (7.9). See below — without it `cleanup` deletes                                         |
| `contracts`      | Manifest: contract id → version, kind, and per axis its direction, epsilon and comparison (7.9)                                                   |
| `violations`     | Canonical symbol keys → deterministic entry lists                                                                                                 |

The manifest detects a forgotten version bump: if the registry's axes,
direction, epsilon, or declared comparison differ from the manifest at the same
declared version, the result is `incompatible`, not a silent miscomparison. Cost
is O(contracts).

**`scope` closes a data-destruction path that nothing else in this design
guards.** Every lifecycle command takes a `<paths...>` argument, and §7.4 lets
an entry absent from *complete* discovery resolve — where "complete" was
reasoned from §2.6's "analysis is always full". That is full *with respect to
the paths given*, not with respect to the project. So
`bin/qmx baseline:cleanup baseline.json src/Foo` is a technically complete run
in which every entry outside `src/Foo` is absent from discovery, resolves, finds
its onset unmoved, is reported `fixed`, and is deleted. One mistyped argument
empties the file.

That is also a regression against today's behaviour, which is fail-safe:
`BaselineCleanupCommand` takes no paths and keeps any entry whose file it cannot
resolve. Replacing a conservative heuristic with an inference that destroys data
on a typo inverts Goal 5 — "unevaluated scope is conservative: it never resolves
or mutates an entry". `migrate-apply` already carries a fingerprint of its
analysis inputs; `cleanup` and `update` carried nothing.

So the file records the scope that produced it, and `cleanup` and `update`
refuse to run when the current scope does not cover the recorded one, with an
explicit `--force`. An entry whose symbol lies outside the analysed paths is
`unobserved`, never `resolved` — which is what §5.6's first category says about
any scope that was not evaluated, applied to the one input nobody had written
down.

`compare` is **required** on every manifest axis, and takes the literal value
`not-applicable` for a channel that has no onset boundary to compare — the
absence of a boundary is a fact worth writing down, and an optional field would
make "no onset" and "someone forgot" identical on disk. A v7 file written before
the field existed is not a compatibility case: v7 has not shipped, and §6.2
already requires the file to fail closed rather than guess.

The comparison joined that list at 7.9. It has to: the reason test compares
boundaries as numbers, so switching an operator from inclusive to exclusive
makes the boundary strictly more permissive without changing the number, and an
entry would resolve as `fixed` while its debt sits exactly where it was. The
four channel traits of §5.7 are deliberately **not** in the manifest — they
describe the current run rather than the comparable shape, and never make an
entry `incompatible`.

Everything except `generated` is deterministic for the same analysis and
contracts. A no-op command preserves the existing timestamp and bytes.

### 6.2 Entry invariants

- `rule`, `code`, `contract`, `axes`, and `occurrences` are required;
  `occurrence_key` is optional and null when the rule offers no stable
  discriminator.
- Each axis carries its captured `value` (numeric or null) and the `onset`
  boundary in force when it was captured. The stored onset exists to
  distinguish "the code was fixed" from "the policy moved" (§7.1) — it is a
  single number per axis and is **not** used to decide comparison, which always
  uses the current onset boundary.
- An entry whose channel has a **non-numeric onset** stores the captured mode
  as an opaque token instead of a number, and an entry whose measurement
  depends on configuration the rule does not see stores an `inputs` digest of
  that configuration (§5.7). Both exist for the same reason as the stored
  onset: without them a policy change is indistinguishable from a fix, and
  `cleanup` deletes standing debt. Both are compared only in the reason test,
  never in the allowance comparison.
- The referenced contract must exist in the manifest.
- Scalar entries have exactly one axis; vector entries at least two; presence
  and graph entries may have none.
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

| Status         | Meaning                                                  |
| -------------- | -------------------------------------------------------- |
| `new`          | Current violation has no baseline entry                  |
| `matched`      | Within allowance                                         |
| `improved`     | Within allowance and better than captured                |
| `regressed`    | At least one axis or the count exceeded its allowance    |
| `resolved`     | No current violation, under proven coverage              |
| `suppressed`   | Comparison succeeded and is deliberately silenced (§5.6) |
| `unobserved`   | Coverage cannot prove the finding was evaluated          |
| `orphaned`     | The entry's rule does not exist in this build            |
| `incompatible` | Contracts cannot be compared                             |

The statuses are **ordered**, and exactly one applies to an entry. Without a
stated precedence an implementer can pick either of two defensible answers when
conditions overlap — an entry can simultaneously sit in an excluded path, belong
to a removed rule, and reference a changed contract. Evaluate in this order and
stop at the first match:

1. `orphaned` — the rule is absent from the build, so nothing else can be
   computed about the entry.
2. `unobserved` — the scope was not evaluated, so no comparison is possible.
3. `incompatible` — the scope was evaluated but the contracts cannot be
   compared.
4. `suppressed` — comparison succeeded and the result is deliberately silenced.
5. the outcome statuses: `regressed`, then `resolved`, then `improved`, then
   `matched`.

Within step 5, `matched` means "inside the allowance and not better than
captured" — `improved` is tested first, so the two do not overlap. `new` is not
in the ordering: it applies to current findings with no entry at all.

One attribute qualifies a status rather than multiplying the list:

- `resolutionReason` — `fixed` or `policy`, decided by **comparing boundaries,
  not values**. When a finding resolves there is no current observation (no
  violation, so nothing was emitted), so the current value is unavailable and
  cannot be part of the test. What is available is the captured onset stored in
  the entry and the onset in force now:
  - current onset **not more permissive** than captured → the absence of a
    violation by itself proves the measurement improved past the same line →
    `fixed`;
  - current onset **more permissive** than captured → absence proves nothing
    about the code, only that the line moved → `policy`.

  `cleanup` removes `fixed` entries only; `policy` entries are reported and
  retained, so re-tightening the threshold restores the original captured debt
  rather than re-admitting it as `new` at today's worse values.

  An earlier revision defined `fixed` by comparing the *captured* value against
  the *captured* onset. That test is tautological — an entry exists only because
  its captured value crossed its captured onset — so `fixed` was unreachable and
  `cleanup` could never remove anything, including genuinely fixed code. Noted
  here because it is the kind of error that reads as correct.

**Which statuses are reachable depends on where the onset sits relative to the
captured value**, and an implementer who does not work this out will write
branches that can never execute. Writing `A` for the allowance:

- **Onset no more permissive than captured** (`A` = captured). A violation
  exists whenever the current value crosses the onset, so the full range is
  reachable: worse than captured → `regressed`; equal → `matched`; better but
  still violating → `improved`; no longer violating → `resolved`.
- **Onset more permissive than captured** (`A` = onset — the team raised the
  threshold above the recorded debt). A violation requires reaching the onset,
  which is also the allowance. For a channel declaring inclusive comparison the
  boundary value itself both violates and sits inside the allowance, so
  `matched` is reachable **exactly at the boundary**, and within epsilon around
  it. For a channel declaring exclusive comparison it is not. `improved` is not reachable, since any observable value is
  at or beyond `A`. Everything above the boundary is `regressed`; everything
  below stops violating and becomes `resolved`.

  A prior revision claimed only `regressed` and `resolved` were reachable here.
  That holds only for channels declaring exclusive comparison; inclusive is the
  majority, and the registry test asserts each channel's declared comparison
  against its behaviour rather than the plan asserting a count.

A prior revision carried a `withinWidenedPolicy` attribute for the "growth
accepted by a widened policy" case. It has been **removed**, but not for the
reason 7.3 gave. That revision called it unreachable, arguing that it needs an
observation for a symbol not violating current policy. The argument assumed
strict comparison and is wrong for the inclusive shapes: with captured 10,
captured onset 8, and the onset raised to 12, a current value of exactly 12
violates (12 >= 12), is worse than captured, and sits inside the allowance — so
the attribute would fire.

It is removed because that is the *only* place it fires. The band is
`[onset, onset + epsilon]`: an artefact of where the boundary happens to be, not
the situation the attribute was introduced to report. The case a user cares
about — debt growing substantially inside a widened policy — stays invisible,
because such a value stops violating and yields no observation at all. An
attribute that stays silent for the case it was designed for and fires on a
boundary coincidence misinforms more than its absence does. §14.4 records the
invisibility as a limitation instead.

`regressed` findings are reported as violations of their rule's severity and
carry a stable `baseline-regression` reason code in machine output.

### 7.2 Scalar and vector

- Higher-is-worse: `current > allowance + epsilon` is worse.
- Lower-is-worse: `current < allowance - epsilon` is worse.
- Vector: any worse axis is `regressed`; at least one better and none worse is
  `improved`; a registry/manifest shape change is `incompatible`; a null axis is
  skipped (§5.8).

### 7.3 Occurrence, presence, graph

- Occurrence: count above allowance is `regressed`, equal is `matched`, lower
  positive is `improved`, zero is `resolved` under proven coverage. Stable
  occurrence keys take precedence over count buckets.
- Presence and graph: identity present is `matched`, a new identity is `new`, a
  missing identity is `resolved` only under complete relevant coverage. Graph
  identity must be canonical and traversal-order independent (§2.7).

### 7.4 Missing symbols and scope

Absent from complete discovery → may be `resolved`. Absent because the scope was
not evaluated (§5.6, first category) → `unobserved`. Aggregate and graph entries
require complete aggregate or graph coverage.

**A channel with a cutoff resolves only on pre-cutoff evidence.** A banded
channel stops reporting when debt grows past its band, so its silence carries no
information. Resolution needs evidence produced *before* the cutoff is applied.
For circular dependencies that evidence exists and an earlier revision missed
it: `AnalysisContext::$cycles` is populated by the detector, while the cutoff
lives in `CircularDependencyOptions::getSeverity()`, so "this identity is absent
from the full cycle set" cleanly separates a fixed cycle from one that grew past
the band.

Symbol inventory cannot do this job, and a revision that used it left the normal
repair unhandled: a cycle is usually fixed by deleting one dependency edge,
which leaves every member class in place, so an inventory check would never
resolve the entry and no other command would touch it. Where a banded channel
has no pre-cutoff artefact, its entries are reported as requiring manual review
rather than resolved by inference.

#### The evidence is a snapshot, not a query (7.9)

The earlier text said the evidence "exists" in `AnalysisContext::$cycles`. It
does — the detector populates the full set and the cutoff is applied afterwards,
inside the rule — but the context **does not survive rule execution**. It is
built for `RuleExecutor::execute()` and is not carried on `AnalysisResult`, so
no post-analysis stage can read it. A comparator that plans to consult the
context at comparison time will find nothing there. The evidence must therefore
be *recorded while rules run* and carried forward, which makes this a producer
obligation rather than a lazy lookup, and changes who owns it.

This applies to a **cutoff on an identity** only. §5.7 splits the band trait in
two, and the other half — a cutoff on an axis, as in `design.data-class`, where
the finding stops firing once WMC grows past its bound — has no identity set to
enumerate and is decided by §5.9 instead: the predicate stopped firing, so the
entry resolves with reason `policy` and is never auto-removed. A producer must
not be asked for evidence it cannot have, or every entry of that channel is
permanently `unobserved`.

Two contracts, mirroring the coverage pair that already exists:

- **Rule-side, opt-in.** A channel whose band cuts off identities reports the
  ones it saw **before** applying that cutoff. Only the rule knows how its
  identity is canonicalised, and only a handful of channels have a band, so this
  is the same opt-in shape and the same argument as
  `CoverageDeviationReporterInterface` — not a method every rule must answer
  emptily.
- **Run-side.** The coverage contract gains an occurrence-addressed answer:
  given a channel and an occurrence key, is that identity `present`, `absent`,
  or `unknown` in the run's band-independent evidence.

**The evidence answer gets its own named type; `ScopeCoverageStatus` must not
be reused for it.** The two are isomorphic in shape — three values, first one
positive — and *opposite* in meaning: `Evaluated` licenses reading absence as
disappearance, while `present` means the identity is still there and the entry
must not be touched. The types sit in the same directory, so the reuse is the
obvious move and it inverts the branch without a single failing test.

**The snapshot key and the observation key must be byte-identical.** The rule
reports its pre-cutoff identities from one method and attaches occurrence keys
to observations from another; two independent canonicalisations of the same
cycle — a different member order, a different escaping, a different part list —
never match, so `present` never fires, and an entry hidden by the band is read
as `absent` and resolved. That is precisely "resolve an entry because its debt
grew". There must be one canonicalisation point, and a test asserting the two
paths agree for the same finding — the same cross-package assertion P1c already
owes for the collector → rule seam.

The three answers map onto §7.1 without inference:

| Evidence  | With no current finding | Why                                                                                                       |
| --------- | ----------------------- | --------------------------------------------------------------------------------------------------------- |
| `present` | `suppressed`            | the band hid it — which §5.6 already classifies as configuration-silencing, so the entry is never mutated |
| `absent`  | resolution permitted    | the identity is gone from evidence taken before the cutoff, so silence carries information                |
| `unknown` | `unobserved`            | the "requires manual review" case above, stated as a status rather than as prose — but see the note       |

A producer with no snapshot for a channel answers `unknown`. It must never
answer `absent` by falling back to the finding set: for a banded channel that is
precisely the forbidden inference — evaluated plus absent equals fixed — and it
would resolve an entry *because* its debt grew.

**`unknown` widens `unobserved` rather than fitting inside it.** §7.1 defines
that status as "coverage cannot prove the finding was evaluated", and its
precedence step reads "the scope was not evaluated, so no comparison is
possible". Neither is true here: the scope *was* evaluated, the rule ran, and
only the band evidence is missing. Left as it stands, an implementer following
the precedence procedure finds step 2's condition false, steps 3 and 4
inapplicable, and step 5's `resolved` forbidden — with no step to land on. So
§7.1's step 2 is restated to cover both readings: *no comparison can be trusted*
— either because the scope was not evaluated, or because the evidence a banded
channel needs was not recorded. The reported reason distinguishes them, since
"we did not look" and "we looked but kept nothing" call for different fixes.

`unobserved` and `orphaned` are distinguished by **why the rule is missing**,
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

Both forbid mutation by ordinary commands; they differ in reporting and in
whether `cleanup --prune-orphaned` may remove them. An earlier revision
classified `disabled_rules` as both, which would have let two packages
implement two different behaviours and pass their own tests independently.

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
bin/qmx baseline:update  <baseline> <paths...>
bin/qmx baseline:cleanup <baseline> <paths...>
bin/qmx check <paths...> --baseline=<baseline>
```

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
  additionally performs **debt-neutral identity re-pointing**: when a `resolved`
  entry and a `new` finding share a contract, the entry may follow the moved
  symbol. "Debt-neutral" is defined precisely, because a loose reading lets a
  rename smuggle in growth: the new finding's **current** axes must be no worse
  than the entry's **captured** axes on every axis, and its occurrence count no
  greater. A `new` finding has no captured axes of its own — the comparison is
  old-captured against new-current, and the count is part of it, so a rename
  taking one occurrence to five is refused rather than re-accepted. The occurrence key must match too, or
  distinct occurrences would be conflated. And re-pointing requires evidence
  that a move actually happened: the entry's **original symbol must be absent
  from the discovered inventory**. Without that precondition a genuinely new
  finding elsewhere, sharing a contract and carrying a smaller magnitude, would
  be silently absorbed as a rename — which is new debt accepted without anyone
  deciding to. Where the match is ambiguous — several candidates satisfy it —
  `update` re-points nothing and reports the ambiguity. This is the only remedy for a mass rename
  (§14.3).
- **cleanup** — **modifies the existing `BaselineCleanupCommand`**, it is not a
  new command. Today it takes only a baseline path, runs no analysis, and
  removes entries whose *file no longer exists on disk*. v7 subsumes that
  heuristic (a missing file implies a missing symbol implies `resolved` under
  complete coverage) and replaces it: the command gains a required
  `<paths...>` argument, runs a full analysis, and removes entries confirmed
  `resolved` with reason `fixed`, plus `orphaned` entries behind an explicit
  flag. It never removes `unobserved`, silenced-area, or `policy`-resolved
  entries. The added argument is a breaking CLI change and needs a `Breaking`
  changelog entry.

  **`cleanup` must not delete a re-pointing candidate.** `update`'s
  debt-neutral re-pointing is predicated on the entry's original symbol being
  absent from the inventory — which is exactly the predicate that now makes an
  entry `fixed` (§5.7). So a renamed class satisfies both commands at once, and
  nothing ordered them. Running `cleanup` first destroys the captured axes; the
  finding then reappears under the new name as `new`, `update` will not adopt it
  because it never adds an identity, and the only remaining exit is
  `generate --force`, which re-accepts the project's entire debt.

  **The re-pointing predicate is defined once, in `update`, and `cleanup` cites
  it — it is never restated.** A first attempt restated it and dropped one of
  its four conditions, which is enough to deadlock both commands: `fixed` is
  reachable without the symbol disappearing at all, so any unrelated `new`
  finding of the same contract would block `cleanup` while `update` declined the
  entry for failing its own absent-symbol precondition. For a channel with no
  stable occurrence key the block is total rather than incidental — "matching on
  occurrence key" is `null == null`, and "no worse than the captured axes" is
  vacuous for the twenty channels that carry no axes, so one stray
  `code-smell.goto` finding anywhere would pin every resolved entry of that
  contract for ever.

  Two rules follow, and both are testable. A candidate must satisfy the **whole**
  predicate, absent-symbol precondition included, and the predicate must be
  non-vacuous: where the channel offers no occurrence key, a candidate must
  additionally name a symbol. And the two commands may never both decline: if
  `update` refuses — including the ambiguous case where several candidates match
  and it re-points nothing — `cleanup` must either remove the entry or report a
  cause the user can act on. "Both refuse, silently, for ever" is a state §13
  pins as forbidden.
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
   suppressed N / resolved N / unobserved N / orphaned N / incompatible N`. The
   counts must satisfy a checkable invariant — every baseline entry falls into
   exactly one bucket, and `new` accounts for the rest of the current findings.
   §13 requires that invariant to be asserted, because a bucket introduced
   without a counter is exactly how the first draft lost `suppressed`.
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

`new` findings follow ordinary `fail_on`. `matched` and `improved` are filtered.
`resolved` and `orphaned` are informational. `unobserved` and `incompatible`
produce diagnostics and block mutation of the entries concerned. Loader and
schema errors keep the existing configuration error class.

## 10. Interaction With Other Features

- **Thresholds** — changing the onset boundary changes the allowance by design.
  Tightening may surface findings as `new`; relaxing widens allowances, marks
  affected entries as resolved with reason `policy`,
  and is visible in the `qmx.yaml` diff.
- **Git scopes** — presentation only (§7.4).
- **Contract versioning** — bumped when an observation's *meaning* changes
  (axes, direction, epsilon, **the declared onset comparison**, identity,
  occurrence semantics), not on every algorithm edit. The comparison joined this
  list at 7.9, alongside the manifest field: an operator moved from inclusive to
  exclusive shifts the boundary without changing a number, so neither the
  manifest check nor this list would have caught it. The four channel traits are
  deliberately **not** here — they describe the current run, not the comparable
  shape. Bumps land only in major releases and are listed in
  `CHANGELOG.md` by contract id, because each turns consumer entries
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

Cross-package data contracts are owned by P1a and by nobody else. Where a
package produces data another package consumes, the *shape* is defined in Core
first; a DoD that only asserts "my side works" is insufficient, because both
sides pass independently while the seam is broken.

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

Until they land, the consumer stub in P1a's tests overstates what the coverage
contract can answer: it decides `resolved` from channel-wide evidence alone,
which §7.4 permits only for unbanded channels.

### P1a′ — the contracts P1a's Definition of Done did not enumerate
Files: `src/Core/Channel/**` (registry, contracts, and both onset providers),
`src/Core/Coverage/**`, `src/Core/Suppression/**`,
`src/Core/Violation/Violation.php`, `src/Core/Violation/ViolationChannel.php`
(moved out), `src/Core/README.md`, `tests/Unit/Core/Channel/**`,
`tests/Unit/Core/Coverage/**`, `tests/Unit/Core/Suppression/**`,
`tests/Unit/Core/Violation/**`, this document.
Dependencies: P1a. **Blocks P1b, P1c and P3.**

Four contracts, three of them from §0.8 and the fourth found while specifying
them:

| Gap                             | Closed by                                                                                  | Specified in |
| ------------------------------- | ------------------------------------------------------------------------------------------ | ------------ |
| Declared-channel registry       | `ChannelContract`, `AxisContract`, `ChannelRegistryInterface`, `ChannelDeclaringInterface` | §5.7         |
| Config-driven silencing carrier | a two-valued run-scoped query plus a sparse rule-side report, in `Core/Suppression/`       | §5.6         |
| Occurrence-level coverage       | an occurrence-addressed coverage answer, its own status type, and a rule-side reporter     | §7.4         |
| Onset in force now (new)        | the rule-side and run-scoped onset providers, four-valued                                  | §5.7         |

`ViolationChannel` moves from `src/Core/Violation/` to `src/Core/Channel/`,
joining what a channel *declares* to the address it is declared under. The
precedent is `SymbolPath`, which left `Violation/` for `Symbol/` for the same
reason. Three production consumers, all inside Core; every test that references
it already sits in a directory this package owns.

**Definition of Done — stated as seams, not as types.** P1a was delivered
complete against a DoD that enumerated types and still left four seams open;
listing types is what produced that outcome, so this package is measured by the
questions its consumers can answer:

1. a stub comparator decides `incompatible` for a channel whose rule emitted
   **no violation in this run**, from the registry alone — the one scenario the
   registry exists for;
2. a stub decides `suppressed` for an entry whose scope produced **no finding**,
   from the silencing query alone, and — the symmetric half, without which an
   implementation can simply skip the query whenever a finding exists — a second
   entry that **does** have a current finding worse than its allowance, inside
   an excluded path, also reaches `suppressed` rather than `regressed`, and is
   left unmutated. §5.6 requires both directions; item 5 below requires the
   opposite one, and an implementation must satisfy them together;
3. a stub refuses to resolve a banded entry whose evidence is `unknown`, and
   resolves the same entry when the evidence is `absent` — the two branches
   §7.4 forbids collapsing;
4. a stub computes `resolutionReason` with **no observation in hand**, from the
   run-scoped onset query, and distinguishes all four of its answers: a moved
   numeric boundary gives `policy`, a channel with no boundary at all gives
   `fixed`, a non-numeric mode gives `policy`, and a symbol absent from a
   complete run gives `fixed` — including for a channel with a compound
   predicate, which §5.9 otherwise refuses `fixed`;
5. **a positive outcome is proven reachable end to end.** One entry traverses
   the whole §7.1 queue — declared in the registry, coverage proven, silencing
   answered, band evidence `absent` — and arrives at `resolved` / `fixed`. Each
   of items 1–4 asserts a refusal, and this plan has twice shipped a refusal
   that could never be lifted; a suite made only of refusals cannot catch the
   third time. A second entry, on a channel whose allow-list silenced *other*
   scopes but not this one, reaches `regressed` — pinning that a rule-reported
   silencing is scoped, not channel-wide, and that step 4 does not swallow
   step 5;
6. a stub decides `orphaned` for a channel matched by neither an enumerated nor
   an open-ended declaration, and **not** for a `computed.*` channel whose
   definition was removed from configuration while its open-ended declaration
   stands;
7. the consumer stub written under P1a is brought in line or retired: it decides
   `resolved` from channel-wide evidence and substitutes a raw array for the
   registry, which §0.8 itself names as the proof the contract was missing.
   Leaving it is leaving a wrong decision pinned by a green test in the frozen
   base;
8. Core stays dependency-free; PHPStan level 8 clean; `composer check` green;
   `src/Core/README.md`'s structure block lists every new file;
9. no rule, collector, or Analysis file is touched — a contract package that
   needs a production change to prove itself has not defined a contract.

### P1b — Rule observations
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

**P1b owns the rule-side half of four P1a′ contracts.** P1a′ defines their
shape and P2 implements the run-scoped halves; without this paragraph the
producer side belongs to nobody, every rule passes its own DoD, and the registry
stays empty while the run-scoped answers return `unknown` and `orphaned` forever
— the P1a failure repeated one package later. The four are the channel
declarations, the onset provider, the pre-cutoff identity reporter, and the
silencing report for rules that carry their own allow-list. All four follow the
same shape the codebase already uses for coverage deviations: opt-in, sparse,
never a per-symbol map.

DoD: a registry-driven test asserts every rule in `RuleRegistry` emits an
observation of a declared kind — no hand-maintained list; the onset boundary is
never derived from the tier-matched threshold; `GodClassRule` emits fixed raw
axes with nulls for unavailable metrics; threshold changes alter boundaries but
never raw values or identities; **every channel in the inventory is reachable
through the registry**, and the inventory gains its coverage-scope column so no
channel's trait value is invented at implementation time; **the rule-side onset
provider returns different boundaries for two symbols under one violation code**
(`LongParameterListRule`'s VO branch is the case, and it bypasses
`getEffectiveOptions()`, so an inline `@qmx-threshold` reaches ordinary methods
only — the provider must reproduce that, not an idealisation of it); **the
cutoff-on-identity channel fills its pre-cutoff snapshot, and the key it reports
is byte-identical to the one its own observation carries**; **every rule with an
allow-list reports what that list silenced, scoped to where it silenced it** —
a report covering the whole channel would make every entry of that channel
`suppressed` and is the defect §5.6's two-valued verdict exists to prevent;
`duplication.code-duplication`'s onset is `(min_lines, min_tokens)`, and a test
shows raising `min_lines` yields `policy`, never `fixed`; **each channel names
the configuration keys that feed its measurement digest** (§5.7), with tests
that editing `coupling.framework_namespaces` and excluding a health dimension
both yield `policy`; `architecture.coverage` declares its unmatched-end count as
an axis and its `mode` as a non-numeric onset, so the channel ratchets at all;
the silencing report is addressed by the observation's own identity, proven by a
test where one allowed entry in a file does **not** silence a second entry of
the same channel in that same file.

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

### P2 — Coverage
Files: `src/Analysis/Coverage/**`, `src/Analysis/Collection/**`,
`src/Analysis/Pipeline/**`, `src/Analysis/RuleExecution/**`, matching tests,
`src/Analysis/README.md`.
Dependencies: P1a′.
Also implements the three run-scoped queries whose contracts P1a′ defines: run
coverage (including the occurrence-addressed answer), the silencing query, and
the run-scoped onset facade.
DoD: partial, failed, and interrupted runs are distinguishable from complete
ones; the deviation list is empty on a clean full run; the coverage key includes
the violation code; the evaluation gate implements §5.6's first category before
rules execute; the pre-cutoff identity snapshot is taken **during** rule
execution and survives onto the analysis result, since `AnalysisContext` does
not (§7.4); the silencing query is **two-valued** and unions the centre's own
exclusions with the sparse silencing reports rules produce, with magnitude
cutoffs left to the band evidence; **removing a `computed_metrics` definition
from `qmx.yaml` leaves its entries `unobserved`, not `orphaned`** — asserted end
to end against a real configuration change, not on a stub with both registry
answers supplied by hand, since the stub is exactly what would pass while
production did the opposite.

### P3 — Baseline v7 domain and lifecycle
Files: `src/Baseline/**`, its tests, `src/Baseline/README.md`.
Dependencies: P1a′ only — genuinely parallel with P2 now that the coverage
contract is in Core.
DoD: the lifecycle policy §0.8 kept out of Core lands here and is asserted
against §5.6's table — which statuses an ordinary command may mutate, and which
resolution reasons `cleanup` may remove; comparison matrices pass for every kind, status, and attribute; the
migration plan schema is specified and versioned; malformed files fail closed;
writes are atomic with a real CAS guard; no-op operations preserve bytes; v5 is
rejected outside migration.

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

Dependencies: P2 and P3. P4c may run parallel to P4a; P4b depends on P4a.

DoD: the ratchet failure key exists as a real configuration option with all four
`ConfigSchema` steps present; one shared analysis-run service backs all
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
hiding growth beyond the new boundary; `@qmx-ignore` suppresses a regression
while `exclude_paths` neither resolves nor mutates; lifecycle commands succeed
on this project's own `qmx.yaml`; §14 limitations are pinned by tests so they
cannot be silently "fixed"; memory measured against the 2G ceiling on the
largest benchmark project.

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
3. P1a′; standard review. Only then is the base actually frozen.
4. P1b and P1c in parallel worktrees; verify each diff against its DoD.
5. P2 and P3 in parallel; integrate one at a time.
6. P4, then P5 without touching earlier packages' files.
7. Full validation and self-analysis, including lifecycle commands against this
   repository's own `qmx.yaml`.
8. Extended review with three independent reviewers.
9. Verify every finding, fix confirmed ones, re-validate.
10. P6, final validation, website build, seam-focused second round if round 1
    found contract or coverage issues.

## 13. Test Plan

- **Core contracts** — per-kind construction invariants; finite values; null
  axis values; epsilon; identity canonicalisation; version compared after
  identity match, never as part of it.
- **The four P1a′ seams, each asked from the consumer's side** — a registry
  answer for a channel with no violation this run; a silencing answer both for
  a scope with no finding and for one whose finding exceeds its allowance; the
  three band-evidence branches, with `unknown` and `absent` asserted to lead to
  *different* statuses; and a `resolutionReason` computed with no observation
  available, across all four of the onset provider's answers. A producer-side test that only builds the type and reads it back
  proves nothing about a seam — P1a passed exactly that kind of test with four
  seams open. Every stub asserts a **decision**, not a shape.
- **Reachability of the positive outcome, asserted directly.** One entry walks
  the whole §7.1 queue and arrives at `resolved`/`fixed`; one arrives at
  `regressed` on a channel whose allow-list silenced other scopes but not this
  one; and one inside `exclude_paths` arrives at `suppressed` despite carrying a
  finding worse than its allowance. Three
  revisions of this plan have now shipped a state that reads as correct and
  cannot occur, and each time the suite consisted only of tests that something
  is refused. A refusal-only suite cannot detect that nothing is ever allowed.
- **The allowance rule** — captured tighter than onset; onset tighter than
  captured; both directions; **the warning→error transition, asserted to be
  `regressed` and not `matched`**; inline `@qmx-threshold` changing the onset for
  one symbol only; rules with no numeric boundary; compound rules, where an
  inline override moves `minCriteria` and must not widen any axis allowance; a
  **magnitude cutoff**, where a cycle grown past `maxCycleSize` must not resolve
  its entry; the invariant that a regression always implies a current violation.
- **Channels and traits** — a registry-driven test enumerating every channel a
  rule can emit, including the four `architecture.*` diagnostic names that no
  class declares as its own, and asserting each answers every trait dimension;
  an emitted violation whose channel is undeclared fails the build; the
  invariant that a regression implies a current violation asserted per trait
  combination present in the codebase, and explicitly not claimed for
  conjunction, criteria-count, or cutoff channels; the onset provider queried
  for a
  symbol-conditioned rule (`LongParameterListRule` VO versus ordinary
  constructor) returning different onsets under one violation code; a computed
  metric configured `warning: 20, error: 10`, asserting the onset is 10.
- **Rule observations** — raw versus display-rounded values; inverted directions; `GodClassRule` axis stability
  when TCC is missing and when the LCOM veto engages; stable computed-metric
  contract ids; stable cycle and duplication identity; occurrence multiplicity.
- **Coverage** — complete, disabled, `only_rules`, discovery `exclude`,
  `exclude_paths`, parse failure, worker failure, interruption, incomplete
  aggregate and graph scope; the three categories of §5.6 producing their
  documented statuses; deleted versus unobserved versus orphaned.
- **Comparison** — every status and attribute for every kind, including
  both `resolutionReason` values, with `fixed` asserted **reachable** on an
  ordinary improved scalar and never produced for a compound entry; the
  reachability split of §7.1 asserted directly, including `matched` **at the
  boundary** of an inclusive rule in the widened-onset regime and `improved`
  unreachable there; the status precedence exercised by an entry that is
  simultaneously excluded, contract-changed, and rule-removed; manifest mismatch at an
  equal declared version; a forgotten version bump on a rule that emits **no**
  violation at all, asserted to yield `incompatible` rather than `resolved` —
  this is the sole scenario justifying the contract registry (§5.7), and
  comparing against contracts harvested from emitted observations would pass
  every other test; `unobserved` versus `orphaned` for a config-disabled rule
  versus a rule absent from the build; ratchet versus suppress.
- **Serialisation** — round trip including `occurrence_key` and stored onsets;
  byte stability; fixed-clock generation; no-op preservation; path portability;
  malformed values; NaN and infinity rejection; atomic write and failed-rename
  cleanup; concurrent writers under the CAS guard.
- **Lifecycle** — plan/apply fingerprint guards; ambiguous entries surfacing in
  the plan rather than aborting; unmatched v5 entries offered only drop-or-abort
  and the dropped count reported; per-entry guard leaving unproven entries
  untouched while writing the rest, and never writing a partially trusted file
  when a parse failure means the run could not see part of the tree; `update`
  debt-neutral re-pointing **and its monotonicity** — an attempt to widen an
  allowance must be refused, not merely absent from the happy path; `cleanup`
  refusing `policy`-resolved entries and pruning `orphaned` ones only behind its
  flag; `generate` capturing after evaluation-exclusion and before
  presentation-suppression, asserted by an `exclude_paths` finding being absent
  from a freshly generated baseline.
- **Reporting and exit** — summary-line-first ordering; expansion rules;
  `--explain`; SARIF properties; schema-valid Checkstyle and GitLab; a
  warning-severity regression failing the build under `fail_on: error`, and
  passing when the ratchet failure key is disabled.
- **Residual limitations** — §14.1, §14.2, and §14.3 each pinned by a test that
  asserts the documented behaviour.
- **Full validation** — `composer check`; `bin/qmx check src/`; strict MkDocs
  build; private-leak guard; benchmark regression suite; memory and wall-time
  measurement against the 2G ceiling. `RuleExecution` is 1–3% of runtime today,
  so the expected wall-time impact is small — but it is measured, not assumed.

## 14. Residual Limitations

Each limitation below must be pinned by a test (§13) that asserts the documented
behaviour, so that it cannot be silently "fixed" into a different behaviour.

1. **Compound rules** — per-axis worsening is invisible once `GodClassRule`
   stops firing (§5.9), and its axes carry no onset so an inline
   `@qmx-threshold` cannot widen them (§5.1). Coverage by single-metric rules is
   partial: WMC and LCOM have their own rules; TCC, class LOC, and WOC do not.
2. **Count fallback** — without a stable occurrence key, one removed plus one new
   occurrence at equal count is indistinguishable.
3. **Renames** — mitigated but not solved by `update`'s debt-neutral
   re-pointing (§8), which requires identical captured axes; a rename combined
   with a change still appears as resolved plus new. The mitigation is also
   order-dependent, which is why §8 forbids `cleanup` from removing a
   re-pointing candidate. A configuration change can rename symbols just as a
   code change can — the namespace strategy and the aggregation prefixes both
   do — and such an entry is indistinguishable from a deletion by the symbol
   inventory alone.
4. **Relaxing a threshold widens every allowance under it** in one move. This is
   the accepted cost of making policy the source of truth; it is visible in the
   `qmx.yaml` diff, and `cleanup` will not delete the affected captured values,
   which are retained as `resolved`/`policy` so that re-tightening restores
   them. Growth *inside* the widened range is not reported at all: the finding
   stops violating, so nothing is measured. That is what widening the policy
   means, and an attribute claiming to report it was removed as unreachable.
5. Ratchet is not historical trend analysis.
6. **Unmatched v5 entries are lost, not migrated** (§8). A v5 entry carries only
   a rule name and an opaque hash, so one with no current finding to match
   cannot be reconstructed into v7's structural identity. The user chooses
   between dropping it and aborting the migration; there is no third option, and
   no amount of tooling creates one.
7. **A rule with a magnitude cutoff cannot prove resolution by absence** (§5.1).
   The codebase has two banded channels, of two different shapes.
   `architecture.circular-dependency` cuts off by `maxCycleSize` on the
   identity: its entries resolve only on positive evidence that the identity is
   gone, so a cycle that grew past the cutoff stays in the baseline rather than
   silently resolving. `design.data-class` cuts off on an axis that is not the
   finding's leading one, and nothing survives that cutoff to enumerate, so
   §5.9 governs instead: its entries resolve as `policy` and are never removed
   automatically. An earlier revision claimed only the first channel existed,
   and described the second through the value it reports today rather than
   through the axes its v7 contract will carry.
8. **A line-addressed author tag silences its whole file for entries that carry
   no line** (§5.6). `@qmx-ignore` and `@qmx-ignore-next-line` key on a line
   number, and a baseline entry carries a symbol. Where the entry's symbol has
   no line span to test against, Baseline answers conservatively: a matching tag
   anywhere in the file marks the entry `suppressed`. That direction cannot
   delete real debt, but `cleanup` will leave a genuinely fixed finding in such
   a file, and removing it is a manual edit. The report says so rather than
   implying the entry is still debt.

   This is the whole residue. A draft of 7.9 also treated occurrence allow-lists
   as undecidable, which would have made `resolved` unreachable for entire
   channels — including two configured in this project's own `qmx.yaml`. They
   are decidable, from data the run already keeps, and §5.6 now says how.
9. **Values measured under different configuration are still compared.** An
   entry records a digest of the configuration its measurement depended on
   (§5.7), and a difference forces reason `policy` rather than `fixed` — but the
   allowance comparison itself proceeds as usual. Strictly, a value produced
   under different measurement inputs is about as comparable as one produced
   under a different contract, and the rigorous answer would be `incompatible`.
   That answer is not taken, because a routine edit to
   `coupling.framework_namespaces` would then invalidate every coupling entry in
   the file at once. The digest is stored, so the decision can be revisited
   without a file-format change.

## 15. Rejected Alternatives

**Absolute ratchet independent of policy (v6).** Rejected: it makes the baseline
and the threshold configuration two independent policies that drift apart with
no instrument to reconcile them, forcing every deliberate relaxation through
`generate --force`. Recorded dissent: one reviewer preferred keeping the
absolute ratchet plus a narrow `baseline:accept-regression` command. Not
adopted — it adds a second acceptance surface alongside `qmx.yaml`, and
duplicated acceptance paths are how policy drifts in the first place.

**Observations for every evaluated symbol.** Rejected: O(symbols × rules)
retention for data that, under §5.1, cannot change any outcome. The project
already made comparable retention opt-in for memory reasons in `RuleExecutor`.

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
   `Baseline`.
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
positive proof of repair.** Recorded here rather than applied, because applying
it rewrites half this document and that work deserves a session with room to
review it. Nothing below is provisional: the direction is settled, the open
items are enumerated, and the next session executes rather than re-derives.

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
