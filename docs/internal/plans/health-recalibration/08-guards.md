# P7 — the guards no earlier stage owns

P3 and P4 each regenerate the baselines and ratchet entries their own change
moves — that ownership moved here after review found P3's DoD depended on this
stage's output (c-03, x-05). What remains is what no single stage invalidates.

## The enumeration, and its standing

`measurement/04-consumers.md`, taken at `55297007` by grep across eight channels.
It is a draft, not an oracle: it reports one explicit project formula where the
source has three, and review found a second error in it. Two of its numbers were
wrong where this plan quoted them — `qmx-baseline.json` holds 17 `health.cohesion`
entries and is format v13, not 18 and v11.

**How it was obtained and what it misses**: grep plus targeted reading. It does
not see keys assembled at runtime, values reached through reflection or DI
aliases, or consumers outside the repository. Channel 2 produced ~1160 textual
matches and was not walked line by line. Unchecked and named as such: the
`*:check` composer scripts, 24 of 25 `finding-gate/cases/*/qmx.yaml`, the presets,
and CI configuration.

This is why the DoD below is a green validation run rather than "every row
handled".

## What remains here

| artefact                              | action                                                                                                                                                                                                                                          |
| ------------------------------------- | ----------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| class/namespace distribution snapshot | regenerate; introduced in P0                                                                                                                                                                                                                    |
| finding gate                          | `composer gate -- --reference=<the commit P3 starts from>`; health channels are expected RED by magnitude, everything else GREEN. A health finding whose *identity* changed rather than its magnitude is a different problem and is caught here |
| inline directives                     | `composer directives:audit`; a suppression whose subject vanished because a score moved is inert and is removed, not left                                                                                                                       |
| generated architecture docs           | name-only references; `composer architecture:check` proves it                                                                                                                                                                                   |

## Definition of Done

- `php scripts/health-calibration.php --verdicts` exits 0 — every criterion
  C1-C7 re-checked, not only the ones this stage set out to move. A criterion
  satisfied by an earlier stage must not be quietly undone here.
- `composer check` green — the aggregate, not a group.
- `composer benchmark:check` exit 0.
- The gate run, its verdict recorded, every red channel explained as an intended
  magnitude shift.
- `composer directives:audit` exit 0.
- `measurement/04-consumers.md` either corrected or replaced by a fresh
  enumeration; a draft with two known errors does not stay as the record.

## Files

`docs/internal/benchmark-class-distribution.json`, `measurement/04-consumers.md`,
and whatever validation turns up.
