# 0017. Baseline Reported-Magnitude Ceiling

**Date:** 2026-08-07
**Status:** Accepted

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
`--preset`, `--rule-opt`, `--only-rule`, and `--disable-rule` are available to
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
8. **Duplication can re-key after the first copy moves.** A reduction can yield one stale entry and one fresh finding.
9. **Symbol keys are not unique per declaration.** Same-FQN declarations and trait consumers can share an identity, and `__PROJECT__` is a legal PHP namespace name.
10. **Aggregate magnitudes can move after another file changes.** A class CBO boundary can breach without an edit to that class.
11. **Three project-keyed architecture channels form multi-member groups.** `architecture.unreachable-layer`, `architecture.potential-shadow`, and `architecture.empty-template` have occurrence ceilings with no member-position information; single-result `architecture.coverage` is unaffected.
12. **A survivor can grow into a repaired member's slot.** Cumulative comparison accepts redistribution below the worst previously accepted magnitude; this is the cost of not tracking member identity.
