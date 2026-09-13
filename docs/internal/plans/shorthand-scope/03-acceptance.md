# Stage 3 — accounting

What each instrument must say after the cure, and what would falsify the round.

## The ledger: twenty-five rows move, no new value is added

The brief states that axis B will need a new `coexistence` value for "the first
key legitimately makes the second inert". It will not — because that reading is
the one the round removes. Every affected row lands on `compose`, which the
vocabulary already carries and the classifier already awards.

**The seven `same-source` rows promising `refuse`** (`complexity.{ccn,cognitive,
npath} | callable: × threshold`; `coupling.{cbo,instability} | class: × threshold`
and `namespace: × threshold`) carry their own reason, verbatim:

> the carrier … says a top-level shorthand of THESE two rules applies to both
> levels at once, so a level key written beside it is disputed and the carrier
> does not say which wins; ADR 0052 row 4 -> refuse rather than pick silently

and, appended later:

> The winner this row recorded as unnamed is therefore named. The round decision
> is kept only because the coexistence column has no value meaning «the
> shorthand wins and the block is silently dropped»

Both grounds are removed by this round rather than worked around: the carrier is
rewritten to name composition, so row 4's "no winner named" no longer holds, and
the value nobody could write is no longer needed because nothing is silently
dropped any more. The rows move to `compose` and their notes are rewritten to
say that — not amended beside the old text, which would leave the file arguing
with itself.

Note the asymmetry the current rows encode and the contract keeps: for the
complexity family `callable: × threshold` promises `refuse` while
`class: × threshold` promises `compose`, because the top-level band reaches
`callable` and not `class`. Under C2 neither is a refusal any more — the deeper
key wins for what it names — so the seven move and the eleven stay.

**The eighteen `cross-source` rows** are `DEFERRED` with an empty `coexistence`,
on the ground that the cross-source coordinate is "routed through
RuleOptionThresholdModeResolver / RuleThresholdKeyGroupRegistry, which this
round freezes whole". `RuleOptionThresholdModeResolver` was deleted by X20 and
this round changes the registry, so the deferral's stated reason no longer
exists. They are decided as `compose`: C3 makes the cross-layer case plain
priority, and a layer whose keys all survive where the higher layer was silent
is composition by the ledger's own definition.

**Consequence to watch, not to assume away.** Rows leave `DEFERRED` and start
reaching the classifier, so axis B's cell count grows. A cell that was never
judged may land anywhere. The stand is run before the product change (P2's DoD)
precisely so this movement is attributable to the ledger edit rather than to the
cure.

## The grid

Axis B is **not** a blocking axis (`run-declaration.tsv`:
`blocking-axes = A,C,D,E`), so this cure cannot change the stand's exit code.
Exit 1 will still come from axes A (4) and D (37). The evidence is the number,
not the colour: the round is judged by axis B's defect count against the count
P2 records, and by no cell moving on the other four axes.

Prediction, to be checked rather than assumed: axis B's 81 defects — 20 from the
`enabled: false` gate, 25 from the complexity shorthand disabling the sibling
level, 36 from the coupling shorthand applying uniformly — all three mechanisms
are the subject of C1/C2/Q5, so the expectation is 0 on the grown population.
Any residual is this round's finding and is reported as such.

`axis-b-mechanisms.tsv` is corrected in P2 before it is used as a denominator:
it declares four mechanisms summing to 82 against a live axis of 81, because M4
counts a cell that no longer exists, and M2's count absorbs one
`npath | class.enabled × enabled` cell that M2's own text does not describe.

## The floor

The single standing row is the subject of this round:
`pair|complexity.ccn|class:|threshold|same-source|3-shorthand-vs-level-block`,
expected `MISCOMPOSED`.

It becomes `pending:`, not `cure`. The floor's own header settles this: a plain
`cure` "means the same thing on both halves", and the first cure a round lands
after the baseline `02a6ca66` is correctly a defect on the frozen half and
correctly repaired on the live grid. So this round **adds a fifth `pending:`
row** rather than converting the four that stand.

It follows that this round does **not** retake the snapshot and does not convert
the four `pending:` rows of axis C. The header rules that out directly: "Re-taking
the snapshot is NOT the fix — a fresh snapshot precedes every cure by
construction and would only move the problem to the next round's first cure."
`--freeze-before` is therefore not run, and its refusal while a `pending:` row
stands is not an obstacle this round has to clear.

Sequencing note: the floor row's text names a commit that does not exist until
the cure is committed, so the floor edit is the cure's follow-up commit, not
part of P2.

## The gate

The corpus carries no case writing a top-level band beside a level block, so the
gate would run GREEN across this cure while seeing nothing of it. P1 adds the
case and proves it reddens against the pre-cure product; only then does a GREEN
run mean anything.

After the cure, the case's surfaces move by construction — that is the point of
it — so `finding-gate/declared-delta.tsv` gains rows naming each moved surface
and the reason. The file is empty today, so these are its first rows: an
undeclared move is red, and a declared move that moved nothing is red too.

## The website, in both languages

Six locations, not three: each page has a `.ru.md` counterpart carrying the same
claim, verified by reading both.

| file                                       | what it says today                                       | what it must say                                      |
| ------------------------------------------ | -------------------------------------------------------- | ----------------------------------------------------- |
| `getting-started/configuration.md:185-210` | the bare `threshold` replaces the level blocks, silently | the contract of `01-contract.md`, C1-C4               |
| `getting-started/configuration.ru.md:188`  | the same, in Russian, down to "молча"                    | the same                                              |
| `rules/coupling.md:154-159, 425-430`       | two `!!! warning` boxes: "flat threshold wins"           | rewritten to composition and the reach of both levels |
| `rules/coupling.ru.md:170, 438`            | the same two boxes                                       | the same                                              |
| `rules/complexity.md`                      | silent about its own rules' top-level shorthand          | gains it, with the reach and with B1                  |
| `rules/complexity.ru.md`                   | the same silence                                         | the same                                              |

The workaround paragraph (`configuration.md:206-217`) is rewritten as the
canonical form, not deleted: it is the shape both shipped presets use and stays
recommended after the defect it worked around is gone.

## ADR and CHANGELOG

ADR 0059 records what is not derivable from the code: that depth-against-layer
was decided explicitly and not inherited from ADR 0058; that reach is declared
per rule because the registry's prose already described a form it could not
express; and the rejected alternative for B1 with the reason it was rejected.

`CHANGELOG.md` gets two `Breaking` entries, written from the consumer's side:
B1 (a bare `complexity.*` shorthand no longer switches the class level off, with
the per-rule difference — `ccn`/`cognitive` gain class findings, `npath` does
not) and B2 (a top-level band beside a level block now composes).

## What would falsify this round

- A document in `measurement/population.tsv` whose outcome under the cure is
  neither the contract's nor a named exception.
- Axis B's defect count not reaching the predicted number, with the residual
  unexplained by a named cell.
- Any cell moving on axes A, C, D or E.
- The gate case failing to redden against the pre-cure product — that would mean
  the corpus still does not carry the subject.
- A refusal whose message names a key the author did not write (K1).
