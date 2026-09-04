# 0042. Declared Field Moves in the Finding Gate

**Date:** 2026-09-04
**Status:** Accepted

## Context

The equivalence gate compares two revisions of the product over a fixed corpus
and refuses any diff line that moves a compared field. A step may move a field
on purpose — a reworded message, a re-estimated remediation time — and the only
existing licence was `declared-delta`, whose `delta-overreach` check accepts a
moved field only when a declared `ChannelSplit` explains it. A split produces
moves of exactly three fields: `channel`, `rule`, `code`.

So `message`, `techDebtMinutes`, `file`, `line` and `subject` could be licensed
by nothing. Not because moving them is dangerous — because there was no list for
them. Measured on the step that removed the banned channel from the "did you
mean" suggestions: the finding set does not change at all, and one record's
`message` moves on nine surfaces, each of which `DeclaredDelta` covers. Exactly
one of the nine — `format:json` — produced `delta-overreach`, and had no shape
to be declared with. That step was dropped from the previous round for want of
this one row.

**Why only one of the nine, corrected in place.** The first reading of that
measurement was that the other eight publish the field in a syntax
`delta-overreach` cannot read. Half of that is right: `summary`, `text`,
`text-verbose` and `github` mark no field at all. The other half was a fact
about the reader, not about the surfaces — `sarif` publishes the same field as
`"text"`, `gitlab` as `"description"`, `checkstyle` as an XML attribute, and the
reader knew only the tuple's own spelling in the tuple's own syntax. Four of the
nine declarations were therefore accepted by a check that could not reach them.
The vocabulary is now stated per surface and per syntax
(`PublishedVocabulary`, pinned to the formatters that write it), and this step's
move is licensed on four surfaces rather than one.

## Decision

`finding-gate/declared-field-moves.tsv` is a second declaration form, beside
`declared-delta.tsv` rather than inside it.

- **One row licenses one exact `(surface, field, from, to)` quadruple.** It
  fires on equality of all four, not on containment, and carries a mandatory
  reason.
- **A row that no diff line performs is `field-move-stale`**, exactly as an
  unused delta row is `delta-stale`. A licence nobody exercises is a claim about
  the product that nothing checks.
- **The diff around the licensed line is still compared byte for byte**, and the
  diff is still refused past the size limit. This removes one wall inside a
  comparison, not the comparison.

Alternatives considered and rejected:

- **Extend `ChannelSplit::FIELDS` with the other five fields.** A split states
  that one channel became several; naming `message` in it would make the
  declaration mean two unrelated things, and a reader of a split row could no
  longer tell which one was claimed.
- **Match a licence by pattern rather than by the exact pair.** A pattern
  licenses moves nobody measured, which is precisely what `delta-overreach`
  exists to refuse. The pair is verbose on purpose: the declaration is written
  from a measured run, and its verbosity is what makes staleness detectable.
- **Let the gate accept the move because the finding count did not change.** The
  count is the weakest of the compared surfaces; a step that swaps two messages
  between records keeps it.

## Consequences

- A step that moves a compared field is provable again, with the same shape of
  evidence as a channel rename: declare, run, and let the comparator agree.
- Declarations are written against a specific reference and stay in the merge.
  The round that lands them cannot empty them — against its own reference a tree
  without them is red by construction — so the obligation to empty them belongs
  to the first step of the next round, whose reference already contains the
  change. Non-compliance shows up as `delta-stale` / `field-move-stale` on that
  round's first gate run.
- Two declaration forms now exist and answer different questions. Neither
  licenses a change to the *set* of findings; that form is still missing, and
  its two unmeasured forks (coverage when a case loses the last record of a
  channel; aggregates that move as a consequence and belong to no vanished
  identity) are recorded in the follow-ups.
- The self-test proves the mechanics on synthetic indexes — equality against
  near misses, direction, staleness, duplicate keys, missing reasons, a row
  declaring no movement — because the comparator is the instrument, and an
  instrument that proved itself before a rewrite says nothing about the
  rewritten one.
