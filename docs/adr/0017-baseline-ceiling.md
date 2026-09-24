# 0017. Baseline Reported-Magnitude Ceiling

**Date:** 2026-08-07
**Status:** Accepted; file identity and the live format version were updated by [ADR 0026](0026-assigned-declaration-ordinal.md)

The ceiling semantics below remain current. The `v10` envelope described by
this historical decision has since been replaced by version 13; only version 13
is loadable on the current tree.

## Context

The version 5 baseline suppressed an identity merely because it had appeared
before. It could therefore hide an arbitrary worsening of an existing finding.
The earlier v7/v8 measurement-comparison design tried to infer whether a
non-firing finding had been repaired; absence can also result from a threshold,
configuration, topology, or formula change, so that inference could silently
delete accepted debt.

## Decision

Baseline format v10 stores a reported-magnitude ceiling for each complete
identity: symbol, channel, and dependency edge when present. It is applied only
to a **live group that currently fires**. A group is the current findings with
one identity; a baseline never makes a non-firing rule fire.

`magnitude` channels store a finite, six-decimal-normalized magnitude for every
member and declare a direction (`higher` or `lower`). `occurrence` channels
store only the group count. Marker-like channels map to `occurrence`;
configuration diagnostics are not baselineable. The computed-metric family is
open: its magnitude shape and direction resolve at runtime from the definition
and its `inverted` setting.

A magnitude group is accepted when, at every current severity level, it has no
more members at least as bad as that level than the stored group had. For a
`higher` direction “at least as bad” means `>=`; for `lower`, `<=`. An
occurrence group is accepted when its count does not grow. `mode: suppress` is
an explicit exception that accepts its identity regardless of count or
magnitude.

This cumulative rule deliberately does not pair ranked members. Best-end and
worst-end rank alignment both assume which members disappeared; best-end can
turn a pure repair into a breach. Counting at every level makes no identity
claim, subsumes the count check, and accepts a remaining worst member after a
less-severe member is repaired.

The baseline runs after source/configuration suppressions and path/namespace
exclusions, and before git report scoping. `generate`, `migrate`, `update`,
`cleanup`, and `check` use that same measured set. Configuration options
`--preset`, `--rule-opt`, `--only-rule`, `--disable-rule`,
`--include-generated` and `--include-autoload-dev` (the last two added by the
2026-09-24 amendment below) are available to
the lifecycle commands so their set can match `check`; CLI-only exclusions and
`--no-suppression-annotations` are not. The latter restores annotations only
for presentation after the baseline has measured the set, so it never widens a
capture or promotes an annotated finding.

A measurable, applicable breach reports every member at **Error**, ensuring the
default `fail-on=error` fails the run. An entry that cannot be applied
(malformed data, unknown channel, shape mismatch, absent/non-finite magnitude,
unknown mode, or renamed identity) is fail-safe: it suppresses nothing and the
finding keeps its configured severity. Stale entries are reported but never
fail a run, disable other entries, or delete themselves. `cleanup` removes only
selectors explicitly supplied by the user; `update` leaves absent identities
unchanged.

v10 files contain `version: 10`, normalized scope, deterministic entries, and
an optional `mode`. Version 5 is accepted only by `baseline:migrate`, which
performs a fresh capture because v5 has no magnitude boundary. Migration reports
carried and fresh counts, and names every dropped or unreadable v5 row; no v5
entry is silently merged. Its `--force` is only an explicit intent to replace a
destination that is not v5. Writes use an atomic temporary-file rename plus
compare-and-swap under lock: the expected content hash is checked in the same
critical section, and an expected-absent target is also checked. The path
identity is part of that guard; the token is not persisted in the baseline file.

This is intentionally not v7 measurement comparison. v7 asked whether an
absent finding was repaired and inferred deletion; v10 asks only whether a
currently reported group stays within an acceptance boundary. Any future change
that gives the baseline an opinion about a non-firing finding crosses the
rejected boundary.

## Rejected alternatives

- **Capture configuration to corroborate staleness.** Rule thresholds do not
  determine all firing conditions; a complete provenance fingerprint recreates
  v7 while an incomplete one gives false confidence.
- **Inject baseline values into rule thresholds.** Rule option shapes, inverted
  directions, shared option slots, rules without thresholds, and inclusive
  comparisons make rule inputs the wrong abstraction.
- **Rank-align magnitude vectors.** It assumes which member disappeared and
  either over-reports repairs or under-reports growth.
- **A second absolute-ratchet policy.** Two independent boundary sets drift
  without a safe reconciliation mechanism.
- **Per-axis predicate algebra.** Compound rules already reduce their axes to
  one reported magnitude; re-encoding their internals is less correct.
- **Diff-only gating.** Useful separately, but cannot cover aggregate values
  changed by another file.

## Consequences

- Existing v5 files require `baseline:migrate`; the removed inline generation
  option has no alias.
- A run with a baseline can become red where the same live warning is within the
  configured threshold but exceeds an accepted level; only measured breaches
  are promoted.
- Improvement never automatically removes debt acceptance. Users inspect and
  select entries with `baseline:cleanup`.
- The mechanism is deliberately conservative and retains these residual
  limitations; the numbered list is the canonical, machine-checked source.

## Residual limitations

1. **Which member of a group changed is not tracked.** Removing one member and adding another at the same magnitude is accepted.
2. **A shrinking group is not resolved.** `--show-resolved` counts vanished identities, not a group that merely became smaller.
3. **Compound rules are bounded on their reported axis.** A non-reported criterion can worsen while a reported tally is unchanged.
4. **A breach reports the whole group.** The mechanism cannot identify the newly worsened member.
5. **A magnitude scale can change without a channel change.** CBO scope and computed formulas or direction may change what a stored value means, risking over-acceptance; project-normalized `coupling.class-rank` is deliberately an occurrence channel instead.
6. **`complexity.npath.*` saturates at 10^9.** An entry at saturation cannot breach.
7. **Renames strand entries.** The renamed finding reports as new and the old identity becomes stale.
8. **A duplicate copy's findings follow the blocks the detector finds, and its file.** A copy's identity is the block's whole normalized token sequence, its file and its place among the block's copies there. Lines added or removed outside the matched tokens re-key nothing while the detector finds the same block. An edit inside one copy, or code inserted between a copy and the context the copies share, takes that copy out of the block and stales its entry; the untouched copies keep theirs while two of them still agree on the whole block, and when none is left to agree with — two copies, one edited — the untouched copy's entry goes stale too. What the copies still agree on reports as fresh findings on every copy, in untouched files too. A partial copy leaves the accepted block in place and adds one over the part it agrees with: every copy, in untouched files too, gains a fresh finding, its accepted entry stays accepted, and nothing goes stale. A comment or blank line inside one copy re-keys nothing, unless it moves the block's longest copy across `min_lines`: that adds the block, a fresh finding on every copy, or removes it, a stale entry for every copy — in untouched files too. A copy moved to another file yields a stale entry and a fresh finding.
9. **Symbol keys are not unique per declaration.** Same-FQN declarations and trait consumers can share an identity.
10. **Aggregate magnitudes can move after another file changes.** A class CBO boundary can breach without an edit to that class.
11. **Three project-keyed architecture channels form multi-member groups.** `architecture.unreachable-layer`, `architecture.potential-shadow`, and `architecture.empty-template` have occurrence ceilings with no member-position information; single-result `architecture.coverage` is unaffected.
12. **A survivor can grow into a repaired member's slot.** Cumulative comparison accepts redistribution below the worst previously accepted magnitude; this is the cost of not tracking member identity.

## Amendment, 2026-09-24: the project-scope flags are configuration too

`--include-generated` and `--include-autoload-dev` are the command-line
spellings of `include_generated` and `include_autoload_dev`. Both decide what
the project is — which files a run with no paths analyses, and, for the second,
the scope a run is judged against — so they change the measured set exactly as
a preset does. Offered by `check` alone, they let `check --include-autoload-dev
--baseline=b.json` measure more than `baseline:generate b.json` captured, and
every finding the capture could not see read as a breach. The baseline
lifecycle commands now accept both, spelled as `check` spells them; the YAML
keys were already shared, since both sides resolve the same configuration.

## Amendment, 2026-09-24: there is no migration command

`baseline:migrate` has been removed, and three sentences above still name it:
`migrate` in the list of lifecycle commands that share the measured set, the
**Decision** paragraph that accepts version 5 "only by `baseline:migrate`", and
the first **Consequences** bullet that sends existing v5 files to it. They
record what was decided then and no longer describe the tool. No earlier
version is converted: a version 5 file is refused on load like versions 10, 11
and 12, with guidance to run a fresh analysis, map or split the accepted groups
deliberately, and write a current file — `baseline:generate --force` replaces
the old one once that review is done. Machine migration was dropped because it
is needed only where the analysed source is unavailable, while a regeneration
on the same commit is always possible.

## Amendment, 2026-09-24: a duplicate copy's findings follow its block and its file, not its first copy

Residual limitation 8 used to say that a duplicate block re-keys after its
first copy moves. No copy is first any more: ADR 0085 reports a finding on
each copy and keys each one by the block's whole normalized token sequence,
the project-relative path of the file holding the copy and the copy's place
among the block's copies in that file. No line number enters the key, so lines
added or removed outside the matched tokens re-key nothing while the detector
finds the same block, and a deleted copy leaves its own entry stale, as any
repaired finding does. The block is what the detector finds over all of its
copies at once — the longest token run they agree on, together with whatever
context they share around the copied code — so it is not fixed by the code
that was copied. An edit inside one copy, or code inserted between a copy and
the context the copies share, takes that copy out of the block: its entry goes
stale, and so does an untouched copy's once no other copy agrees with it on the
whole block, while what the copies still agree on reports fresh findings on
every copy, untouched files included. A partial copy takes nothing out: the
accepted block is still found, and a second block over the part the partial
copy agrees with gives every copy, in untouched files too, a fresh finding
beside its accepted entry, with no entry going stale. A comment or blank
line that moves the block's longest copy across `min_lines` adds or removes
the block as a whole, in untouched files too. A copy moved to another
file, or every copy in a renamed file, is a stale entry and a new finding, as
a renamed symbol is (limitation 7). Item 8 now states that limitation.
