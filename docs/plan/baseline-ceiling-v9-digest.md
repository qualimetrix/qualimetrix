# Baseline v9 — decision digest

**Status:** digest for review, not a plan. Written 2026-08-05 after v7/v8 was
abandoned. If these decisions hold, the full plan is written against them.

**Why a digest first.** The v7 plan reached revision 8.1 through nine review
rounds, and the last two each found a CRITICAL plus seven HIGH *inside the
previous round's corrections*. The cause was structural, not editorial: v7 made
the baseline a record of past measurements to be compared against present ones,
and every ambiguity in that comparison resolved toward deleting real debt. This
design removes the comparison. Checking the decisions before writing three
thousand lines against them is the cheap order.

---

## 1. What this solves, and what it does not

**Solves.** A legacy finding may be suppressed at the level it stands at today,
and may not get worse. If it worsens, the build fails, with no history, no diff,
and no regeneration.

**Explicitly does not solve**, and these are not oversights:

- *Where the debt came from.* No trend, no "was 25, now 40". Only "is within
  bounds" or "is not".
- *Whether a suppressed finding got better.* Improvement is invisible;
  §7 explains why that is affordable here.
- *Which occurrences.* An occurrence cap of 3 does not say which three, so one
  removed plus one added reads as unchanged. This is what PHPStan's `count` and
  Psalm's `occurrences` also do.

## 2. The governing change

**A baseline entry is a ceiling, not a record of a measurement.** It says: *for
this symbol, on this channel, the effective limit is X* — and the rule then
evaluates exactly as it always has, against X instead of the configured
threshold.

Everything else follows from this. There is no comparison of a past measurement
against a present one, so there is no need for: a policy-independent reader,
proof of repair, a configuration/provenance fingerprint, a coverage contract, a
status precedence, contract versioning with `incompatible`, or a two-phase
migration. All of those existed in v7 solely to make one comparison safe.

**The structural property that matters.** Under v7, an ambiguity (a drifted
identity, an empty metric list, incomplete coverage) resolved toward "repaired",
and `cleanup` deleted the entry — losing real debt. Under a ceiling, an
ambiguity means the ceiling does not apply, so the configured threshold governs
and the finding fires. **Every failure mode is fail-safe by construction**, and
the cost of being wrong drops from "debt silently deleted" to "something extra
reported; regenerate".

**It is not a new mechanism.** The project already resolves per-symbol
thresholds through `getEffectiveOptions()` and `@qmx-threshold`. The baseline
becomes a third source in that cascade, not a parallel subsystem.

## 3. Precedence

Strictness grows from the general to the specific:

```
qmx.yaml  →  baseline  →  annotations on the symbol
```

Each link **replaces** the previous one for that (symbol, channel, axis); a link
that specifies only part inherits the rest. Because each link replaces rather
than merges, *whether an override weakens or tightens is not a separate
question* — specificity decides, direction does not enter.

Consequences worth stating because they are load-bearing:

- Tightening `qmx.yaml` does not touch baselined symbols. That is the point:
  legacy stays pinned at its level, everything else tightens.
- An annotation may widen past a baseline ceiling. That is a deliberate, visible
  line of code that passes review and must carry a reason per `AGENTS.md`. It is
  no more a loophole than editing `qmx.yaml`.
- **`generate` captures only findings that actually fired under the full
  cascade.** Without this the design defeats itself: a method with
  `@qmx-threshold 40` and a current value of 35 would otherwise get a dead
  ceiling of 35, and growth to 38 would be silently permitted.

## 4. What an entry contains

```json
{
  "version": 9,
  "generated": "…",
  "scope": ["src"],
  "entries": {
    "method:App\\OrderService::calculate": [
      { "channel": "complexity.cyclomatic.method", "limits": { "ccn": 25 } }
    ],
    "class:App\\Legacy\\Report": [
      { "channel": "design.data-class", "limits": { "woc": 85, "wmc": 12 } }
    ],
    "file:src/Legacy/bootstrap.php": [
      { "channel": "code-smell.goto", "occurrences": 3 }
    ]
  }
}
```

- **Magnitude channels** store a ceiling per axis, addressed by axis name. The
  worse-direction comes from the channel declaration (§6), so one number per
  axis suffices for both higher-is-worse and lower-is-worse metrics.
- **Occurrence and presence channels** store a count cap. This is PHPStan's
  `count`, sitting beside magnitudes rather than instead of them.
- **Compound channels** need nothing special: writing the current values as the
  criterion thresholds makes the criteria stop matching, and growth makes them
  match again. The rule's own predicate is untouched.
- `mode: suppress` remains available as "ceiling = unbounded".

## 5. Tiers

Applying the cascade in order yields exactly one `(warning, error)` pair per
channel per symbol — which is what `withOverride(?warning, ?error)` already
produces, so the mechanism exists.

**A baseline entry writes both elements to the captured ceiling.** Then a value
past the ceiling fires at the Error tier through the ordinary tier logic, and
"exceeding an accepted ceiling fails the build" needs no special rule at all.
The cost is that gradation *inside* accepted debt is lost — which is the point
of a ceiling.

**The positional `(W, E)` pair is not a universal abstraction**, and this is a
pre-existing wart rather than something v9 introduces: most rules read it as a
severity ladder on one metric, `DataClassRule` reads it as two different axes,
`GodClassRule` maps it onto `minCriteria`. v9 does not touch annotation
semantics — an annotation replaces whatever it means for its own rule, and the
rest is inherited. The baseline is the more expressive of the two, since it
addresses axes by name.

**Rejected, deliberately: a declarative per-axis predicate algebra** (axes with
their own warning/error levels and AND/OR between them). `design.god-class` is
"≥ 3 of 4 criteria" with a veto, which is neither AND nor OR; severity per axis
does not compose to one finding severity; thirty-odd single-axis channels would
pay for a structure they do not use; and it would break a live public surface
across 33 Options classes. The project already has a declarative composition
mechanism for the case that wants one — computed metrics with Expression
Language formulas. What v9 *does* take from that idea is the noun without the
verb: every channel declares its axes, which is what was actually missing.

Optional follow-up, independent of v9: allow the named form in annotations
(`@qmx-threshold design.data-class woc=85 wmc=12`), retiring the positional
ambiguity where it hurts without touching the engine.

## 6. The channel declaration, and one vocabulary for the whole design

Three naming systems already exist in the codebase, and a ceiling has to bind
all three. Writing them down is what stops a fourth from being invented:

| Entity                                | Named by                                             | Examples                                                                      |
| ------------------------------------- | ---------------------------------------------------- | ----------------------------------------------------------------------------- |
| **channel** — a kind of finding       | `(ruleName, violationCode)`, type `ViolationChannel` | `complexity.cyclomatic` / `complexity.cyclomatic.method`                      |
| **axis** — a bounded quantity         | its `MetricName` constant                            | `ccn`, `woc`, `wmc`, `cbo`, `typeCoverage.param`                              |
| **slot** — what a ceiling writes into | the rule's own Options property                      | `warning`/`error`, `wocThreshold`/`wmcThreshold`, `paramWarning`/`paramError` |

**An axis is named by its metric, not by a new word.** `MetricName` already
holds 69 constants and the quantities channels bound are among them — `ccn`,
`woc` (`STRUCTURE_WOC`), `wmc` (`STRUCTURE_WMC`). So the file's
`"limits": { "ccn": 25 }` uses the metric's own name, and "axis" stays a role
word ("the metric this channel bounds"), not a naming system. Two channels bound
quantities that are *not* repository metrics — `duplication.code-duplication`
(block length) and `architecture.coverage` (unmatched-end count) — and they
declare their own axis name and say so, rather than inventing a metric that does
not exist.

**The binding to slots is not derivable and must be declared.** The Options
classes name their thresholds in at least five ways — `warning`/`error` (18
rules), `maxWarning`/`maxError` (5), `wocThreshold`/`wmcThreshold`/
`tccThreshold`/`lcomThreshold`/`classLocThreshold`, `voWarning`/`voError`, and
`paramWarning`/`returnWarning`/`propertyWarning` — and `design.type-coverage.param`
binds axis `typeCoverage.param` to slots `paramWarning`/`paramError` by nothing
more than convention. No rule of naming recovers that.

So each channel declares, per axis: **the axis name, its worse-direction, and
the pair of Options slots a ceiling writes into.** A triple, not a pair.
Nothing else — no version, no manifest, no `incompatible`, no kinds, no traits.

An unknown axis name in the file is not an error state to model: the ceiling
simply does not apply, the configured threshold governs, and the finding fires.
Fail-safe, like everything else here.

The channel inventory (`channel-trait-inventory.md`, 41 rule classes,
52 channels) carries over from v7 and is the enumeration this declaration is
filled against.

## 7. Stale entries

Accepted as a cost — an entry whose debt was repaired keeps sitting there. But
it is cheaper to clean than it looks, and the mechanism needs no new contract:

**Evaluate the rule twice — with the entry's ceiling and without it.** If the
finding does not fire without the ceiling, the entry is no longer needed. The
metric is already computed and the rule already exists, so this costs one extra
predicate evaluation. It works for compound channels and for cycles too, because
the oracle is the rule itself rather than a parallel reader — which is precisely
what v7 could not achieve with an entire subsystem.

`cleanup` therefore becomes ordinary housekeeping rather than a
correctness-critical operation: removing an entry wrongly costs a re-added entry
on the next `generate`, not lost debt.

## 8. Seeing the effective limit

A command that prints the limit in force for a symbol and where each part of it
came from — "25, from baseline; `qmx.yaml` says 10; no annotation".

Small, and it is what makes a three-source cascade trustworthy. It is also the
answer to v6's old objection (§9 below), and the thing Sonar users complain
about not having.

## 9. What this retires from v7, and why the old objection does not transfer

Not carried over: the channel reader and its 45/1/6 split, the reader/observation
self-check, the configuration-provenance fingerprint, the coverage contract and
its deviations, `resolved`/`fixed`/`unproven`, the status precedence, the
contract manifest and `incompatible`, the two-phase migration with dispositions,
and the five Core contracts of P1a′.

**On "the baseline becomes a second policy"** — v7 §15 rejected an absolute
ratchet on the grounds that the file and `qmx.yaml` become two independent
policies that drift with no instrument to reconcile them. Policy here means *the
set of boundaries that decide what counts as a violation*. The objection does
not transfer, because the three sources compose into **one effective boundary**
per (symbol, channel, axis), by a stated precedence. There is one number in
force, it is computable, and §8's command prints it with its provenance. Drift
was a property of two unreconciled sources; there is now one resolved value.

## 10. Open items

1. **Migration from v5.** A v5 entry is a rule name plus an opaque hash, with no
   symbol and no value. Under v9 the natural migration is a single run: capture
   ceilings for everything currently firing, and report what the old file
   contained that no longer fires. No dispositions, no fingerprints, no
   two-phase flow — but the exact reporting shape needs deciding.
2. **Aggregation levels.** A ceiling on a namespace-level metric is keyed on the
   namespace symbol; confirm nothing in the aggregation prefixes makes that
   ambiguous.
3. **`--report=git:*` and exclusions** are unchanged — they filter after
   evaluation, and the ceiling is consumed during it.
4. **Effort.** Expected shape is one Core-side axis declaration, a cascade stage
   in the options resolution, the file format, four commands
   (`generate`/`update`/`cleanup`/`explain`), and documentation. Materially
   smaller than v7's six packages; the plan should still name packages, but the
   estimate to beat is weeks, not months.

## 11. Landed v7 code that v9 makes dead

P1a landed and was pushed, so this is not a paper decision — the types exist in
`main` and nothing else will remove them:

| Landed                                                                                               | Under v9                                                                                                                                      |
| ---------------------------------------------------------------------------------------------------- | --------------------------------------------------------------------------------------------------------------------------------------------- |
| `Core/Observation/{DebtObservation,AxisObservation,ObservationKind,ContractReference,OccurrenceKey}` | dead — nothing records a measurement any more                                                                                                 |
| `Core/Coverage/**` (4 types)                                                                         | dead — no coverage contract                                                                                                                   |
| `Core/Comparison/{ComparisonStatus,ResolutionReason}`                                                | dead — no statuses, no resolution reasons                                                                                                     |
| `Violation::$observation`                                                                            | removed; it also pushed `Violation` over the constructor-overinjection threshold, which is why the class carries an `@qmx-threshold error=16` |
| `Core/Observation/WorseDirection`                                                                    | **kept** — it is exactly the axis direction of §6                                                                                             |
| `Core/Violation/ViolationChannel`                                                                    | **kept** — three production consumers, and §6 names it                                                                                        |
| the commented-out `analysis-coverage` layer in `qmx.yaml` (`171aa72`)                                | reverted, along with its six inbound-edge markers                                                                                             |

Retiring them is a named work item, not a cleanup someone notices later. Dead
Core types are the most expensive kind: they read as contracts, and the next
person to touch the baseline will assume they are the ones to build on.

## 12. What happens to the v7 plan

It stays in git history — `ac23907` is revision 8.1 — and the file is deleted
from the tree once v9's plan exists. Its ADR-worthy content is the record of
*why* measurement-comparison was abandoned, which belongs in v9's ADR as the
rejected alternative, argued from the two review rounds rather than from taste.
`channel-trait-inventory.md` is kept and reused.
