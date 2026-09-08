# P2 — the ADR that names the final vocabulary

One ADR under `docs/adr/`, written from the approved `decision-table.tsv` and
from nothing else. It is the deliverable of П3; П4 executes it.

## What the ADR must contain

- **The universe, as five closed sets plus one named open one.** The five sets
  with their oracles — and, for channels, the fact that the oracle is
  `ChannelIdentityInterface::channels()` rather than the static registry, with
  the reason. The open family is the consumer's own `computed_metrics:`
  entries; the ADR rules on the *shape* the product imposes on those names,
  never on the names.
- **Every name, with its decision and its reason.** `keep` reasons carry the
  same weight as `rename` reasons. The table is referenced by path, not pasted;
  what the ADR states is the *rule* each group of rows follows, plus every
  exception by name.
- **The naming rule itself, applicable to a name that does not exist yet.**
  Under Q1's answer: a channel that judges a magnitude names the magnitude, one
  that reports an occurrence names the occurrence. Written so the next channel
  added to the product has one correct spelling rather than two defensible ones.
  This is the ADR's most durable paragraph.
- **The names that do two jobs at once** — the six `health.*` (a metric key and
  a channel code) and the 18 channel codes spelled identically to a metric key —
  stated as a deliberate identity or as a collision to be removed, with the
  reason either way.
- **The decisions that are settled as `keep`, not deferred:** the aggregation
  suffixes (Q3, with the gate reason), the `health.<dimension>` form (Q2, citing
  the earlier ADR that settled it and stating that these channels are
  run-time-declared and absent from the static registry by construction), and
  the current spelling of every rule option key and CLI alias (Q5, with the
  spelling rule they follow from now on).
- **What the ADR knowingly leaves out of scope**, each with the measurement that
  makes it defensible: making the option-key refusal loud rather than
  `warn-and-default`; the internal camelCase/snake_case data-bag family
  (`codeSmell.{$type}` and its two siblings — unpublished, and entangled with
  the freeze because `$type` is the keyed discriminator while the `codeSmell.`
  prefix is not); and anything Q4 leaves open.

## What it must not do

- It must not restate the enumeration. Six artifacts hold it; the ADR cites
  paths.
- It must not describe how the rename is executed. That is Stage 2's plan.
- It must not leave a name unmentioned. The claim is checkable: the union of the
  five oracles minus the names the ADR accounts for must be empty, and the ADR
  names the command that checks it.

## Definition of Done

- The ADR is committed under `docs/adr/` with its number; its
  `documentationDisposition()` line and its entry in `docs/adr/README.md` land
  in the **same commit**.
- Every name in all five oracles is accounted for, proved by the command the ADR
  names; the command is run and its output quoted in the package report.
- `composer check` green on a quiet tree.
- `FOLLOWUPS.md` carries no entry still leaving a naming question open — each is
  closed by this ADR or re-stated as deliberately deferred with its measurement.
  Texts go to `followups/` for the orchestrator to merge; the package does not
  edit `FOLLOWUPS.md`.
- Review of this plan (native + codex) happened before P1 ran; review of the ADR
  text happens before Stage 2 is planned.
