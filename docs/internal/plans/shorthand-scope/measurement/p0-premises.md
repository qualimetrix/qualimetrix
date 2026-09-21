# P0 — premise measurement, before any code

Tree: worktree at 1bda6f95 (== origin/main, fetched 2026-09-21).
Source of every number below: `docs/internal/generated/promise-effect/verdicts.tsv`
(7218 data rows), `observations-before/raw.tsv`, `promise-effect/promise-ledger.tsv`.
No stand run was taken; the frozen grid is read directly.

## Premises that HOLD

| #   | premise (plan)                                         | measured                                                                                                                                                                                                                                                                          |
| --- | ------------------------------------------------------ | --------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| 1   | grid = 122 defects, A 4 / B 81 / C 0 / D 37 / E 0      | exactly, cell for cell                                                                                                                                                                                                                                                            |
| 2   | axis B = 465 cells                                     | 465                                                                                                                                                                                                                                                                               |
| 3   | 19 triples `(rule,key_a,key_b)` judged under two kinds | 19, all `2-same-name-top-vs-level` + (`4-cross-level` x18 / `6-precedence-fill-in` x1)                                                                                                                                                                                            |
| 4   | 10 of axis B's 81 defects are second copies            | 20 defect cells over 10 duplicated triples -> exactly 10 copies                                                                                                                                                                                                                   |
| 5   | both sides of a contended pointer write the same value | confirmed verbatim on `pair|coupling.cbo|class:|threshold|same-source|3-shorthand-vs-level-block`: omitted class{14,20} ns{14,20}; onlyA class{7331,7331} ns{14,20}; onlyB class{7331,7331} ns{7331,7331}; both == onlyB byte for byte                                            |
| 6   | `axis-b-mechanisms.tsv` sums to 82 against a live 81   | 20+25+36+1; M4's cell (`architecture.layer-violation|enabled|severity`) now reads COEXISTENCE_OK, so M4 counts a cell that no longer exists. M2's npath share is 9 vs 8 for its two siblings -- the extra is `npath|class.enabled|enabled`, an M1 cell its text does not describe |
| 7   | all 81 defects lie in the five cured rules             | cbo 24, instability 23, npath 12, cognitive 11, ccn 11 = 81                                                                                                                                                                                                                       |
| 8   | 128 cells in the cure's radius                         | `axis-b-rows.tsv` affected=yes: 128 (of 253 cells belonging to the five rules)                                                                                                                                                                                                    |
| 9   | floor holds 27 rows                                    | 27 data rows                                                                                                                                                                                                                                                                      |

## Premises REFUTED

**R1. `finding-gate/declared-delta.tsv` is not empty.** 03-acceptance.md says
"empty today". It carries 12 rows, all added by #135 (ADR 0074, DIT external
ancestry), one per moved surface of `case:external-parent`. P6 must add rows to a
populated file, and P1's "the gate stays GREEN with the case present" has to be
read against a delta file that already declares moves.

**R2. `measurement/axis-b-rows.tsv` has no generator.** 465 data rows, and no
script in the tree names the file (`grep -rln axis-b-rows` over php/py/sh/json:
zero hits). P0's DoD requires re-deriving it at the new magnitudes; the plan does
not cost a generator, and four of its twelve columns
(`post_cure_under_today_value`, `assigned`, `post_cure_under_assigned`,
`assigned_on_todays_product`) are predictions no run on the unfixed product can
produce.

**R3. P2's file set names a path that does not exist.** It lists
`tests/Unit/PromiseEffect/LedgerVocabularyTest.php`; the file lives at
`scripts/promise-effect/tests/LedgerVocabularyTest.php`, and `tests/Unit/PromiseEffect`
does not exist.

**R4. "cells that are NOT OBSERVABLE today become judged" has an empty subject.**
Zero axis-B cells read NOT OBSERVABLE today -- all 465 are COEXISTENCE_OK (384) or
MISCOMPOSED (81). Identical sides do not produce NOT OBSERVABLE here; they produce
a green COEXISTENCE_OK. That is the tautology, and it is worse than the plan's
phrasing: the instrument does not report its blindness, it reports agreement.

**R5. The duplicate cannot be deduplicated in the ledger.** `PairRow::key()` puts
`kind` in the key *deliberately* -- its docblock says removing it collapses
nineteen rows onto thirteen and that a divergence "would have been silently
averaged". So "count the document once" cannot be an edit to
`promise-ledger.tsv` (a P2 file); it has to be a second, explicit count in the
stand (a P0 file). The plan does not say which, and the two packages have one
owner across two strictly sequential steps.

## The fallback, measured

`Stand::pairSide()` writes the canonical magnitude and falls back to a declared
alternate only when the canonical does not move the object **on the build under
test**.

| population                       | sides | canonical | declared alternate | exhausted |
| -------------------------------- | ----- | --------- | ------------------ | --------- |
| the five cured rules (253 cells) | 506   | 435       | 71                 | 0         |
| everything else (212 cells)      | 424   | 302       | 118                | 4         |
| whole axis B                     | 930   | 737       | 189                | 4         |

Distinct `(rule,key)` sides on axis B: 195, of which 51 need a non-canonical value
today. Those 51 span **9 leaves**: `enabled`, `direct-as-error`,
`exclude-readonly`, `exclude-promoted-only`, `exclude-exceptions`, `exclude-tests`,
`unreachable-layer-severity`, `empty-template-severity`, `potential-shadow-severity`.

**Inside the cure's radius the fallback fires on exactly one leaf: `enabled`.**
Every one of the 71 is an `enabled` key or a block key whose only non-band leaf is
`enabled`. Its two values are the form row's `true`/`false`, both already
declared. The product-dependence is visible in one row pair: `complexity.npath`
writes `false` at `callable.enabled` (level default `true`) and `true` at
`class.enabled` (level default `false`) -- the same key, two documents, chosen by
the product.

**R6. P0's file set omits the file the new selector has to be read in.** The
**Files:** line names `effect-magnitudes.tsv`, `Stand.php`, `pair-kind-scope.tsv`
and the stand's tests. That file is parsed in
`scripts/promise-effect/Declarations.php:199-224`, which is in nobody's set. P0's
own prose names it ("a column in the file, a read in `Declarations`") -- the
omission is in the set, which is what package parallelism is contracted on.
Same for the dedup if it goes into `pair-kind-scope.tsv`: that table is read by
`Declarations.php` and consumed only by `Population::pairScopeProblems()` from
`scripts/promise-effect-grid.php`, neither of which is in any package's set.

**R7. `composer check` never re-takes the grid.** `check:artifacts` runs
`promise-effect:p1-set:check` and `promise-effect:grid:check`, not
`promise-effect:check`. The stamp covers the stand's declaration files and
`scripts/promise-effect/*`, and **not `src/`** -- so a product change moves the
verdicts with nothing to notice. #135 (DIT) landed after the last hand-run stand,
so the frozen 122 is unverified on HEAD until run #0 completes.

## `Classifier::pair()`, read in full — why the tautology is structural

The `one-wins:` branch already carries a sensitivity gate:
`onlyA->text === onlyB->text || expected->text === omitted->text -> NOT OBSERVABLE`.
The `compose` branch carries none. It asks only (a) is each side inert against
`omitted`, (b) did each side's moved leaves survive into `both`. With identical
sides both questions answer yes, so the verdict is `COEXISTENCE_OK, "both effects
present"` -- green, not blind. That is R4's mechanism.

Traced on `cbo|class:|threshold` with distinguishable sides (A 7331, B 9137):
- today `both` = class{9137} ns{9137} -> A's moved leaf is 9137, not 7331 -> `lost=[A]`
  -> MISCOMPOSED "the effect of A is absent". The defect becomes visible.
- after the cure `both` = class{7331} ns{9137} -> A survives, B's class half does not
  -> `lost=[B]` -> MISCOMPOSED **under `compose`**. This is precisely why P2 needs
  `deeper-wins:`, and it confirms the plan's reasoning rather than refuting it.

Consequence for P0's requirement 3: the six cells do **not** need a classifier
branch. Two (`cbo|class.scope x scope`) reach NOT OBSERVABLE through the existing
`one-wins:` gate once P2 assigns that value; four (`npath|*.enabled x enabled`)
reach it through the `deeper-wins:` branch P2 writes, which carries its own gate.
P0 therefore does not have to touch `Classifier.php` (a P2 file) -- the six are
excluded from P0's DoD *statement*, not made to read a particular verdict.

## Run #0 — the baseline, verified on HEAD rather than read off the stamp

`composer promise-effect` on an untouched worktree at 1bda6f95, RC=1 (expected:
axes A and D block). Duration ~9 min wall.

    axis A  NOT OBSERVABLE 1697  OK 793   REFUSES 2790
    axis B  COEXISTENCE_OK  384  MISCOMPOSED 81
    axis C  COMPOSED_AS_PROMISED 18  NOT OBSERVABLE 3
    axis D  COLLAPSED 5  NOT OBSERVABLE 139  OK 47  REFUSES 353
    axis E  NOT OBSERVABLE 14  PRESENCE_NEUTRAL 894
    defects (all axes) 122
    defect floor  1 still defective, 21 cured as declared, 4 pending

`git status --porcelain` after the run: **empty**. The generated grid is
reproduced byte for byte, so #135 moved nothing the stand measures and R7's
exposure did not fire this time. The 122 is now a measurement on HEAD, not an
inherited number -- which is what makes run #1 comparable to anything.
