# 54. An Oracle for Effect Diverging from Promise

**Date:** 2026-09-11
**Status:** Accepted

## Context

[ADR 0053](0053-door-enumeration-as-an-oracle.md) built an oracle for silent
acceptance: it measures whether a submitted value bound to anything, and whether
the product **said** so. It cannot see the class this round treats, and not by
omission — by construction. Here the value binds, the product's silence is
lawful, and the defect is in the **effect**: the key is recognised, nothing is
refused, something happens, and what happens is not what the key's name and the
documentation promise. Measuring "did it speak" cannot distinguish a true answer
from a false one.

Naming that class needs a second thing the door oracle never needed: a statement
of what each key *should* do, written independently of the code that does it.
Without it, an oracle reads the behaviour, calls it the expectation, and reports
green forever.

## Decision

### 1. Asymmetric authorship, checked by ranges rather than by files

The expectation and the declaration are written by two authors who cannot read
each other's input.

| Artefact                                                                                  | Author              | Written from                             | Does not read                                |
| ----------------------------------------------------------------------------------------- | ------------------- | ---------------------------------------- | -------------------------------------------- |
| the promise ledger (`promise-ledger.tsv`)                                                 | the ledger package  | `website/docs/**` and contract docblocks | `fromArray()` bodies, the factory, resolvers |
| the shape declaration ([ADR 0055](0055-a-rule-option-declares-the-shape-of-its-value.md)) | a different package | the code                                 | the ledger                                   |

Checking that separation by **file paths** is red by construction: the richest
carriers of the promise are the docblocks of `RuleOptionKeySet`,
`RuleOptionsInterface::acceptedOptionKeys()` and `LevelOptionsInterface`, which
live in the very files the declaration package edits. Forbidding the ledger to
quote them would strip it of its best sources; leaving it alone would fail on the
first row.

**The unit of non-overlap is therefore a line range, not a file.** Every quoted
carrier is frozen as `(file, first line, last line, sha256)` at the round's base
commit, in `promise-ledger-frozen-ranges.tsv`; a ledger row cites a range. The
declaration package may change signatures and bodies freely and must not change
the text inside a frozen range. Changing a carrier is an *expected* event, not a
failure — the failure is changing one **silently**. The named order is: return to
the orchestrator, the ledger author re-derives the affected rows, the freeze
snapshot is re-taken with them.

What this method does not see is written down with it: it compares the **text**
of a range, not its meaning. An edit to a neighbouring line that changes the
sense of the paragraph leaves the hash untouched, and conversely inserting a line
above a range reddens it without changing anything in it. That is the
conservative side, and it is chosen deliberately.

### 2. Three probes, and seven verdicts about the form of a value

```
omitted     — the key is not written
value       — the key is written in the form under test
equivalent  — a spelling the LEDGER declares equivalent
```

| Verdict          | Condition                                                    |
| ---------------- | ------------------------------------------------------------ |
| `OK`             | matched the promise **and** a reachability witness exists    |
| `INERT`          | `value == omitted` while the ledger promised an effect       |
| `COLLAPSED`      | `value == equivalent(a different magnitude)`                 |
| `REFUSES`        | exit 3 **and** the `Configuration error:` frame              |
| `MALFORMED`      | a refusal without the frame, `exit 1`, or a crash            |
| `NOT OBSERVABLE` | `omitted == equivalent`: the stand is not sensitive here     |
| `UNPROMISED`     | no ledger row; after the ledger closes it guards grid growth |

`REFUSES` requires the frame as well as the code, because a product that stops
with the right number and the wrong words is not distinguishable by a caller from
a crash — which is exactly what `MALFORMED` was measured to be.

### 3. A pair of keys gets its own probe and its own verdicts

The triple above asks about the **form** of one value. A promise about
**adjacency** — "these two keys may not both be written" — is not expressible in
it: "a refusal was promised, the product composed instead" is neither `INERT` nor
`COLLAPSED`. Stretching a form verdict over an adjacency observation was the
defect this decision prevents.

```
onlyA — only key A is written
onlyB — only key B is written
both  — both are written, in one document
```

| Verdict          | Condition                                                                                                |
| ---------------- | -------------------------------------------------------------------------------------------------------- |
| `COEXISTENCE-OK` | `both` matched the ledger's `coexistence` (`compose` / `refuse` / `one-wins:<key>`)                      |
| `MISCOMPOSED`    | `both` did something else — composed where a refusal was promised, or silently picked a different winner |

A ledger row is keyed by the pair **and** by the source coordinate
(`same-source` / `cross-source`), because one pair carries two different promises
at once: mutually exclusive within one source, upper layer displaces lower
between sources. Classifying the whole pair by one coordinate would have thrown
away a real `same-source` guarantee on 108 pairs.

### 4. The reachability witness is keyed by producer name, not by options class

`OK` is only `OK` if something read the value. The witness is the **producer
name** — 54 of them — and not the options class, because the mapping between the
two is many-to-many in both directions: `TypeCoverageOptions` serves three rules,
`ComputedMetricRuleOptions` serves eight, and six `health.*` producers have no
options class at all. The measured hole is on the rule side, so the witness has
to be on the rule side.

### 5. Four observation points

`warning: true → 1` happens *inside* `fromArray()`; before the factory the value
is still `true`. An oracle observing only the report cannot say where the
information was lost, and one observing only the object cannot say whether the
loss reached the user. The stand distinguishes **the door's output**, **the merged
document**, **the options object** and **the report**. The bulk is in-process;
out-of-process runs are a witness set — one per producer name for `OK`, and one
per axis for the implication "a collapse in the object implies a collapse in the
report".

### 6. Five reconciliation sets, because four are circular

Reconciling the ledger with the declaration gives four sets: promised and
declared; promised but not declared; declared but not promised (the dangerous
one); declared deeper than the ledger's denominator reaches (a known hole, named
by number). None of the four can see a key the code **reads** while
`acceptedOptionKeys()` does not declare it: such a key is absent from the
declaration *and* from the denominator, so it is invisible to a comparison
between them.

The fifth set is therefore printed from a third source — the inventory of sites
that decide a form:

```
consumer \ declaration — key literals read by fromArray() bodies
                         that no declaration lists
```

It is evidence only once every site in that inventory has a key resolved against
it; until then it is computed over a subset of the population and is printed with
that caveat rather than cited.

### 7. The cache switch is not in the trusted chain

`--no-cache` does not hold in this tree — measured inside this round, on the CLI
door, with no configuration file at all. An oracle that trusts a broken switch is
untrustworthy regardless of whether the defect was reported. Every probe
therefore: names its cache directory explicitly and uniquely; deletes it before
the run; asserts afterwards that the named directory is either absent or was
created by this run, and that no default directory appeared; and **fails** rather
than reports if the product ignored the named directory. Exit codes are taken
from the process, never through a pipe. A run that does not satisfy the
postcondition is not an observation.

### 8. Claim boundary, stated as what is covered and what is not

Covered: for every path `acceptedOptionKeys()` sees, the `yaml` and `rule-opt`
doors in full and the `cli-alias` door for the paths it declares; the three
framework-namespace keys through their **single application site**
(`FindingExclusionLedger::keeps()`) — they enter the boundary by that explicit
member, because no options class declares them and the formula
"`acceptedOptionKeys()`" does not reach them; the container form of the
positions outside `rules:`; and key pairs whose conflict is settled inside one
document.

Not covered, and named: source composition as a whole (axis C); the `inline`
door; pairs that conflict across layers; prose beyond form; the Russian half of
the website; values inside computed-metric formulas; baseline entry keys.

Six of the uncovered positions are the **element** forms of the simple list roots
(`paths`, `exclude`, `disabled_rules`, `only_rules`, `suppress_paths`,
`suppress_namespaces`). They are not derivable from anything and stay uncovered
by decision, not by oversight.

### 9. Axis C is carried out whole, with its denominator taken first

Source composition — twelve writer pairs, the second reader of
`RuleOptionsRegistry`, unfolding the `threshold` shorthand before the merge, the
fate of `RuleThresholdKeyGroupRegistry` — is deferred as one piece. The reason is
coupling and cost, not class: unfolding the shorthand is inseparable from
`RuleOptionsFactory`, which this round's declaration package owns, and doing both
at once would split the central cure between two owners. **The denominator for
the deferred work is measured and frozen** in `writer-applicability.tsv`, so the
next round starts from an enumeration instead of from reconnaissance.

A consequence is accepted openly: the adjacency axis is **measured only** in this
round. No package has a mandate over pair semantics, because the promise witness
for 90 of those rows is `RuleThresholdKeyGroupRegistry::groupsFor()`, frozen
whole. `MISCOMPOSED` therefore does not block acceptance here, and its count is
published as an input to the round that takes axis C. Red on the form axes still
blocks.

### 10. The population guard leans only on what the next round may not delete

The guard is computed from the code — implementations of `RuleOptionsInterface`,
the 54 producer names, the enumerated positions outside `rules:`, the
`same-source` pairs — and reddens on a population member the grid does not cover,
which is checked by planting one. It deliberately does **not** read
`RuleThresholdKeyGroupRegistry` or `RuleOptionsRegistry`, since the axis-C round
may delete either. The honest consequence: a missing or wrong row in the group
registry is not found by this guard, and that is written into the next round's
input rather than left implied.

## Consequences

- **A "before" snapshot is a first-class artefact.** The pair of measurements is
  classified by one classifier over both halves, so a change in the classifier
  cannot be mistaken for a change in the product. Re-freezing requires a reason,
  and editing the classifier is not one.
- **The independence claim covers a named fraction, not the grid.** Rows where no
  carrier exists and the round decides for itself are counted separately and
  **excluded** from the independence claim — for those the reconciliation
  compares a decision with itself. On the base commit that is 292 rows of 1308.
  Publishing that ratio is part of the result, not a caveat on it.
- **`NOT OBSERVABLE` is ratcheted, not merely reported.** Its share may not grow
  between the two snapshots, because a cure that lowers the stand's sensitivity
  would otherwise green the sheet.
- **The cost of the inverse default is paid in a named place.** "Unknown shape ⇒
  refuse" inverts the usual default, so its price is a refusal on a lawful
  configuration. Benchmarks cannot price it — they contain no configurations — so
  a corpus of lawful documents is an artefact of the round, and every package
  that introduces a refusal runs it and reports the number of lawful refusals.
- **The stand lives outside the aggregate.** Its cost is measured three times
  without a pipe; only the declaration reconciliation and the grid freshness
  check go into `check:artifacts`, beside the door enumeration of ADR 0053.
