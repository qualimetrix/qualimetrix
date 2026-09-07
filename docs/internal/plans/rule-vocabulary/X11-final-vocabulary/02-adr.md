# P2 — the ADR that names the final vocabulary

One ADR under `docs/adr/`, written from the approved `decision-table.tsv` and
from nothing else. It is the deliverable of П3; П4 executes it.

## What the ADR must contain

- **The universe, stated as a closed set plus a named open one.** The four
  closed sets and their oracles, and the consumer-defined `computed.*` family
  which is open by construction. The ADR rules on the *shape* the product
  imposes on a user's name, never on the names.
- **Every name, with its decision and its reason** — `keep` reasons carry the
  same weight as `rename` reasons. The table is referenced by path and its
  contents are not pasted into the ADR; what the ADR states is the *rule* each
  group of rows follows, plus every exception by name.
- **The naming rule itself**, in a form a future reader can apply to a name that
  does not exist yet. This is the answer to Q1, whichever branch the owner
  picks, written so the next channel added to the product has one correct
  spelling and not two defensible ones.
- **The three names that do two jobs at once** — the six `health.*`, and the
  18 channel codes that coincide with a metric key — stated as a deliberate
  identity or as a collision to be removed, with the reason either way.
- **What the ADR deliberately does not decide**, each with the measurement that
  makes deferring it defensible: the aggregation suffixes (no declarable shape,
  `MetricVocabulary.php:91`), the internal camelCase/snake_case data-bag family
  (`codeSmell.{$type}` and its two siblings — unpublished, and entangled with
  the X10 freeze because `$type` is the frozen discriminator, while the
  `codeSmell.` prefix is not), and anything Q4 leaves open.

## What it must not do

- It must not restate the enumeration. Six artifacts hold it; the ADR cites
  paths.
- It must not describe how the rename is executed. That is П4's plan.
- It must not leave a name unmentioned. The completeness claim is checkable:
  the union of the four oracles minus the names the ADR accounts for must be
  empty, and the ADR says which command checks that.

## Definition of Done

- The ADR is committed under `docs/adr/` with its number, and its
  `documentationDisposition()` line lands in the **same commit**.
- Every name in all four oracles is accounted for, proved by the command the
  ADR names; the command is run and its output quoted in the package report.
- `composer check` is green on a quiet tree.
- `FOLLOWUPS.md` carries no entry that still leaves a naming question open —
  the ones that do are closed by this ADR or re-stated as deliberately deferred
  with their measurement. Texts go to `followups/` for the orchestrator to
  merge; the package does not edit `FOLLOWUPS.md` itself.
- Review of the plan (native + codex) has happened before this package starts,
  and review of the ADR text happens before Stage 2 is planned.
