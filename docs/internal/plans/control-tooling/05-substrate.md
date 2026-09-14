# Stage 05 — consolidate the duplicated walkers

## Why last, and why separate

The duplication is measured: `generate-input-door-table.php` (week 1) and
`promise-effect.php` with `promise-effect/InProcess.php` (week 3) each walk Symfony
`InputDefinition` over every command with no shared code — while promise-effect *does*
reuse `input-doors-bootstrap.php` for container boot. Four scripts reflect over
`ConfigSchema` independently.

A substrate lowers the marginal cost of the next stand from thousands of lines to
hundreds. That is the argument for it and the reason it must land only after the
budget is enforced: a cheaper next stand is a reason to build more of them.

## The substrate is a family, not a root under the taxonomy

`assurance/` is a navigation taxonomy (stage 04), so the substrate cannot simply sit
under it. It is a family with its own name, its own README and its own ledger rows,
and it is the one place where Ledger B's `owners` list carries more than one entry —
which is why that column is a list.

Candidate surface, derived from what the existing callers already do rather than
designed ahead of them:

```
bootApplication(): Application
walkDoors(Application): iterable<Door>
walkConfigSchema(): iterable<ConfigPath>
readTsv(path) / writeTsv(path, rows)
runInProcess(argv): Observation
```

## The rule that keeps it from becoming a shared-utils bucket

Something enters only when **two families already contain their own implementation of
it** — two copies, not two callers. The first revision's DoD said "two pre-existing
callers", which is weaker than the rule it was meant to enforce: two callers of one
implementation is ordinary reuse and proves no duplication was removed.

Each entry names what would send it back: if one of the two owners stops using it, the
code returns to the remaining one.

## Edge cases

- The two `InputDefinition` walkers may not be the same walk.
  `generate-input-door-table.php` multiplies a door over every command declaring it;
  `InProcess.php` builds a definition for `CliOptionsParser`. If they answer different
  questions, the correct outcome is a recorded finding that the duplication is
  apparent, not real — and no substrate entry.
- `input-doors-bootstrap.php` is already shared de facto: the first entry, and the
  cheapest proof the mechanism works.
- Consolidation changes instrument behaviour by definition. Every affected family's
  artifacts are regenerated and the diff read — a substrate that silently moves a
  measurement is worse than the duplication it removes.

## Packages

| package                                                    | files                                   | depends on |
| ---------------------------------------------------------- | --------------------------------------- | ---------- |
| P1 decide whether the two walkers answer the same question | a finding note                          | stage 04   |
| P2 the substrate family + `bootApplication`                | substrate, its two callers, ledger rows | P1         |
| P3 the remaining entries passing the two-copies rule       | per entry                               | P2         |

P1 may conclude "no substrate for the walkers" and the stage still delivers P2.

## Definition of Done

- Every entry removed **two pre-existing implementations**, both named in its row.
  An entry with one implementation and two callers is rejected.
- The substrate family has its own ledger rows and its `owners` lists every family
  that uses it.
- Regenerated artifacts of every affected family are diffed, and a non-empty diff is
  explained in the commit, not merely committed.
- The measured footprint drops and the standing cap drops with it, in the same commit.
- `composer check` green; `composer gate` green against a pre-stage commit.
