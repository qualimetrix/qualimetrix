# Plans: what is open, what is a record, what may be deleted

An index, not a plan. It answers one question — which of these documents still
governs work — so a session does not have to open all of them to find out.

A plan is deleted when its campaign is closed **and** nothing outside the plans
tree cites it. Both halves matter: a closed campaign whose file is cited by an
ADR, by production code or by a test is a record with live readers, and removing
it turns those citations into dangling addresses.

## Open — these govern work

| Campaign                                              | State                           | What is next                                                                                                                                                                                                                                                                  |
| ----------------------------------------------------- | ------------------------------- | ----------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| [`rule-vocabulary/`](rule-vocabulary/PLAN.md)         | active                          | [`X1-tail.md`](rule-vocabulary/X1-tail.md), packages П1–П5; then the `qmx` subcommand built from the detector prototype. Deferred items live in [`FOLLOWUPS.md`](rule-vocabulary/FOLLOWUPS.md), product defects found along the way in [`AUDIT.md`](rule-vocabulary/AUDIT.md) |
| [`modular-architecture/`](modular-architecture.md)    | P1–P7 landed, **P8 not landed** | P8. The phase records P0–P6 stay: the overview indexes them and P8's executor reads them                                                                                                                                                                                      |
| [`baseline-compaction/`](baseline-compaction/PLAN.md) | P0–P3 landed                    | the open materialisation question at `PLAN.md:197`, and the `VisitorFileEntryScope` split, which is a question to the owner rather than a decided step                                                                                                                        |

## Written and reviewed, not executed

Order for the graph channels is decided: PHPDoc → diff-mode → global functions.

| Plan                                                             | State                                                                                             |
| ---------------------------------------------------------------- | ------------------------------------------------------------------------------------------------- |
| [`phpdoc-dependencies.md`](phpdoc-dependencies.md)               | edition 3, reworked after external review (23 findings)                                           |
| [`diff-mode-new-only.md`](diff-mode-new-only.md)                 | edition 3, reworked after external review                                                         |
| [`global-functions-graph.md`](global-functions-graph.md)         | edition 1, awaiting review; the measurement that deferred it is in the project's memory, not here |
| [`channel-identity-substrate.md`](channel-identity-substrate.md) | proposal, ready for review before execution                                                       |

## Closed campaigns kept as records

| Plan                                                             | Why it is still here                                                                                                                                                                                                     |
| ---------------------------------------------------------------- | ------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------ |
| [`sarif-channel-descriptions.md`](sarif-channel-descriptions.md) | implemented (P0–P6), but seventeen production and test files cite it as the address of the decision. Deleting it needs a step that first moves those citations to ADR 0029 — the code should not be citing a plan at all |

## Deleted

- `client-requests/` — six requests, all implemented 2026-08-19
  (`abstractness-enum-exclusion`, `architecture-layer-pending`,
  `architecture-unassigned-class`, `debug-layer-assignment-json`,
  `severity-report-only`, `threshold-override-exact-matching`). Nothing outside
  the plans tree cited them; the single mention left is a frozen occurrence
  count in `baseline-compaction/enumeration-artifact.md`, which is a dated
  measurement rather than a link.

## When adding or removing a plan file

A `.md` here is classified by hand: add or remove its path in the list inside
`scripts/generate-modular-architecture-production-inventory.php`, then run
`composer architecture:generate`. An unlisted file fails
`composer architecture:check`, which is the intended behaviour — documentation
ownership is declared, not inferred.
