# Follow-ups of the rule-vocabulary pass

Deliberately accepted limitations and deferred work, kept here so `PLAN.md` stops
growing. Two neighbours with different jobs: `AUDIT.md` holds product defects
found along the way and out of scope; this file holds what a step *decided* to
leave open, with the measurement that made the decision defensible.

An entry earns a place here only if it names what is missing, what it costs, and
what would close it. "Could be nicer" belongs nowhere.

## Ш5e3-0 (2026-08-26) — the gate's key-map tooling

### A step that renames an aggregation strategy has no shape to declare it

`MetricVocabulary` reads the strategy list from both trees and refuses to run when
they disagree, because forward translation expands a `metric-keys.tsv` row over
the strategies of the tree it is applied to. That refusal is correct and it is
also a dead end: renaming `avg`, or removing a strategy, moves the published
spelling of *every* aggregated metric at once, and no row shape states that.

- **Cost:** such a step cannot be run through the gate at all — not "runs and
  proves less", which is why this is a refusal rather than a warning.
- **What would close it:** a declaration whose unit is the strategy rather than
  the key, applied to the suffix of every expanded spelling. Nothing needs it yet;
  the plan renames keys, not strategies.

### The overlap check cannot see a reference-only key shaped like an aggregation

The load-time check refuses a declared key whose `<key>.<strategy>` spelling is
already carried by another declared name, by a half the split leaves untranslated,
or by a base key the product declares. The last population is read from
`MetricName`'s constants — 71 of the 82 published keys; the other eleven are
collector-owned literals no single file declares, and Ш5e3 is the step that gives
them constants.

What is left uncovered is one arrangement: a key only the **reference** publishes,
shaped like an aggregation of a declared one, moved by the step without a row of
its own. Every other arrangement of that shape ends in a surface diff rather than
in silence.

- **Cost:** that one arrangement is absorbed silently. Measured 2026-08-26 across
  all 83 published base keys: no key of either tree has the shape at all.
- **What would close it:** after Ш5e3 the eleven literals become constants, so the
  same check then covers the whole published universe. Re-measure then, and this
  entry goes away rather than being restated.

### The corpus no longer proves a user formula that reads a metric key

The health case's user-defined computed metric reads no metric: a formula
addresses a key in a grammar, and Ш5e3 replaces that grammar, so the two trees
would spell the line differently and neither spelling could be handed to the
reference. The decision and its alternatives are in `PLAN.md` under Ш5e3-0.

- **Cost, two parts.** After Ш5e3 the `m['...']` grammar is exercised by the six
  built-in `health.*` formulas (whose values the gate compares at three levels) and
  by Ш5e3's own tests, but not by a user-supplied formula through the config path.
  And a constant is the same at every level, so that channel no longer
  distinguishes one level's value from another's — the six dimensions do, so the
  corpus keeps the property, but it belongs to them now.
- **What would close it:** once both trees understand `m['...']` — that is, from the
  step after Ш5e3 onwards — the corpus formula can read a metric key again with no
  translation needed at all.
