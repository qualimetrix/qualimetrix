# Stage 3 — accounting

What each instrument must say, and what would falsify the round.

Revision 3. Revision 1's account was refuted in four places by review and in two
more by the row-by-row measurement it asked for. The largest correction is not to
a number but to the instrument: **the stand cannot today tell which of two keys
won, so any axis-B number about this cure would come out right for a reason that
has nothing to do with the cure.**

## P0 — the instrument, before anything else

**The defect.** `Stand::pairCandidates()`/`effectWritesFor()` write one canonical
magnitude per numeric key. When a pair's two keys contend for the same pointer —
which is exactly what "a top-level key beside the level key it reaches" means —
both sides write **the same value**, and the leaf-wise comparison that decides
`compose`, `one-wins:` and `deeper-wins:` cannot say who won. Measured on the
product's own frozen observations: at those magnitudes `compose` goes green after
the cure on all eighteen `3-shorthand-vs-level-block` rows. The prediction "axis B
→ 0" would therefore come true **tautologically**, and so would most of the
prediction below.

This is the sibling of a defect the stand already knows about. `effect-magnitudes.tsv`
exists because "a write indistinguishable from the key being omitted answers that
question vacuously" — its own words. A write indistinguishable from *the other
side's write* answers "which key won" vacuously in the same way, and nothing
declares a second magnitude for that.

**The second half of P0: one document is judged twice.** Nineteen triples
`(rule, key_a, key_b)` appear in the ledger under two kinds at once —
`2-same-name-top-vs-level` and `4-cross-level` — so ten of axis B's eighty-one
defects are second copies of a document already counted. `pair-kind-scope.tsv`
checks that every kind is covered; nothing checks that a document is counted
once. Visible directly in the table below: `complexity.ccn | callable.threshold ×
threshold` occurs under both kinds.

**DoD.** Distinguishable magnitudes per side, declared rather than computed, in
the file whose subject this already is; the duplicate documents counted once. The
proof is a stand run **on the unfixed product**, before P2 and before the cure,
whose axis-B number matches the number the row-by-row measurement predicts for
today's values on distinguishable magnitudes. A repair that moves the number in
an unpredicted direction is not accepted on the ground that the direction looks
better.

Until P0 lands, no axis-B number in this file is evidence about anything.

## The ledger, row by row

The assignment is not a formula but a table: `measurement/axis-b-rows.tsv`, one
row per axis-B cell, with today's verdict, the verdict today's value would give
after the cure, the assigned value, and the verdict that value gives. Read it
rather than this summary.

Of 465 axis-B cells, 128 are within the cure's radius and all 81 of today's
defects lie inside those 128. The 124 judged cells take:

| value               | cells | when                                                                         |
| ------------------- | ----- | ---------------------------------------------------------------------------- |
| `deeper-wins:<key>` | 80    | the top key loses the level the other key names and keeps the sibling level  |
| `compose`           | 27    | the two keys name different groups, or the top key does not reach this level |
| `one-wins:<key>`    | 17    | the top key reaches only the level the other names, and lands nowhere        |
| `refuse`            | 4     | two spellings of one band at one depth — C4 leaves these alone               |

These counts stand **at canonical magnitudes** and may shift once P0 makes the
sides distinguishable: cells that are NOT OBSERVABLE today because the two sides
write the same value become judged. They are a prediction to check, not a fact.

**The new value.** `deeper-wins:<key>` means: both keys act, their reaches
overlap, inside the overlap the deeper key wins, and outside it the top key's
effect survives whole. The classifier takes the deep key's region from the
pointers it moved on its own — the longest common prefix, backed off to a path
segment — rather than from the spelling of a name, and inside that region it
requires leaf-wise **equality** with the deep side. The weaker form ("the moved
leaves survived") was measured blind on exactly ten `6-gate` rows where a block
writes `enabled: true`, which equals the default.

Cost, named: the constant in `Ledger::COEXISTENCE_VALUES`, the branch in
`Classifier::pair()`, the key→side translation in `Stand.php` (two places —
without it the live path reads the new value as "the second key won", which is
the defect `claude-07` named for `one-wins:`), and a case in
`LedgerVocabularyTest`. The branch is proven on distinguishable magnitudes
(5/6 against level defaults of 10/20 and 30/50), with eight negative controls: a
block losing on its own level, a top key losing its sibling level, a swapped
winner, and the value on a row with no overlap all read MISCOMPOSED or NOT
OBSERVABLE. A branch that greens everything cannot pass those.

**The fourteen rows that today's values would turn red are not regressions of the
accounting.** They are `4-cross-level` rows of one shape — a level key writing
half a band against a top-level `threshold` — whose current value stops being
true under the contract. They are reassigned (`one-wins:` for the six complexity
rows, `deeper-wins:` for the eight coupling rows) and disappear from the count.
Naming them matters anyway: neither revision 1, nor the gate corpus, nor any
earlier measurement of this subject saw them.

**Fifteen cells read NOT OBSERVABLE after the cure, and that is the honest
reading.** Named in full rather than summarised, because a count without names is
what the last revision was caught doing:

| rule                               | pair                                                    | kind                                                              |
| ---------------------------------- | ------------------------------------------------------- | ----------------------------------------------------------------- |
| `complexity.{ccn,cognitive,npath}` | `callable: × threshold`                                 | 3-shorthand-vs-level-block                                        |
| `complexity.{ccn,cognitive,npath}` | `callable.threshold × threshold`                        | 2-same-name (and again under 4-cross-level — see P0's duplicates) |
| `complexity.npath`                 | `callable.enabled × enabled`, `class.enabled × enabled` | 2-same-name                                                       |
| `complexity.npath`                 | `callable × enabled`, `class × enabled`                 | 6-gate                                                            |
| `coupling.cbo`                     | `class.scope × scope`                                   | 2-same-name, 6-precedence-fill-in                                 |

For the three `callable: × threshold` rows this is a property of the contract, not
of the magnitudes: after the cure the top band does not touch a `callable` band
the block wrote, so the two sides become identical documents and no comparison
can distinguish them. `deeper-wins:` was measured and does not help. The row keeps
`one-wins:callable:`, which is the value that tells the truth; NOT OBSERVABLE then
says "the promise is right, the stand cannot see it", where `compose` would go
green for a reason unrelated to the contract.

**The eighteen `cross-source` rows stay `DEFERRED`.** Revision 1 proposed deciding
them, on the ground that the deferral's stated reason — a frozen
`RuleOptionThresholdModeResolver` — no longer exists. The reason is stale but the
deferral is not: `Stand::axisB()` skips every row whose `sourceScope` is not
`same-source`, in two places, and `PairRow` carries no orientation at all, so a
decided row would produce no cell and no expected key. Deciding them would move
eighteen unmeasurable promises into `DECIDED` and measure none of them — the
tautological mode `floor.tsv` warns about. Their notes are corrected to name the
real blocker instead: the axis-B probe is same-source only. Whether axis B should
grow a cross-source input is a separate subject with its own price, and it cannot
be a side effect of this round.

## The grid: three numbers, not one

Axis B is **not** blocking (`run-declaration.tsv`: `blocking-axes = A,C,D,E`), so
none of this changes the stand's exit code, which will keep coming from axes A
(4) and D (37). The evidence is the number, and each number must be attributable
to one movement:

| after | expected                                                                                                             |
| ----- | -------------------------------------------------------------------------------------------------------------------- |
| P0    | the count the row-by-row table predicts for **today's** values on distinguishable magnitudes, on the unfixed product |
| P2    | axis B **rises**: the ledger now states what the product ought to do and the product does not                        |
| cure  | 0 defects and 15 NOT OBSERVABLE, on the population as P0 left it                                                     |

A single run taken after two of these steps proves nothing about either.
`axis-b-mechanisms.tsv` is corrected before it is used as a denominator: it
declares four mechanisms summing to 82 against a live axis of 81, because M4
counts a cell that no longer exists and M2 absorbs an `npath | class.enabled ×
enabled` cell its own text does not describe.

## The floor

The single standing row — `pair|complexity.ccn|class:|threshold|same-source|
3-shorthand-vs-level-block`, expected `MISCOMPOSED` — becomes a fifth `pending:`
row, not a `cure`. The floor's own header settles it: a plain `cure` "means the
same thing on both halves", and the first cure a round lands after the baseline
`02a6ca66` is correctly a defect on the frozen half and correctly repaired on the
live grid.

This round therefore does **not** retake the snapshot and does not convert the
four standing `pending:` rows. The header rules that out directly: "Re-taking the
snapshot is NOT the fix — a fresh snapshot precedes every cure by construction and
would only move the problem to the next round's first cure."

## The gate

The corpus carries no case writing a top-level band beside a level block, so the
gate would run GREEN across this cure while seeing nothing of it. P1 adds the case
and proves it reddens against the pre-cure product; only then does a GREEN run
mean anything. After the cure, `finding-gate/declared-delta.tsv` — empty today —
gains one row per moved surface with its reason. An undeclared move is red; a
declared move that moved nothing is red too.

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

ADR 0059 records what is not derivable from the code: that depth-against-layer was
decided explicitly rather than inherited from ADR 0058; that reach is declared per
rule because the registry's prose already described a form it could not express;
that the push-down may only add; the rejected alternative for B1; and — separately,
because it is about the instrument and not the product — why a probe that writes
one magnitude per key cannot judge which of two keys won.

`CHANGELOG.md` gets `Breaking` entries for B1, B2 and B3, each with a migration
recipe written from the consumer's side: what a document relying on the old
behaviour looks like, what to write instead, and how to tell whether a given
`qmx.yaml` is affected. B1's entry must carry its cross-layer face by name —
`--preset=strict` plus a top-level shorthand now reports class-level findings at
the preset's thresholds.

## What would falsify this round

- Axis B's number after P0 differing from the row-by-row prediction for today's
  values — that would mean the instrument repair changed something else too.
- Axis B not rising after P2: the ledger would then not be saying anything the
  product fails.
- A cell moving on axes A, C, D or E at any of the three runs.
- A document in `measurement/population.tsv` or `population-gap.tsv` whose outcome
  under the cure is neither the contract's nor a named exception.
- The gate case failing to redden against the pre-cure product.
- A refusal naming a key the author did not write (K1), or a refusal that exists
  today and no longer fires (K5).
