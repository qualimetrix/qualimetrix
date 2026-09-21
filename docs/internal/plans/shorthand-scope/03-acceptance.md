# Stage 3 — accounting

What each instrument must say, and what would falsify the round.

Revision 4. Revision 1's account was refuted in four places by review and in two
more by the row-by-row measurement it asked for; revision 3 was refuted again, in
its own correction. The largest finding is not a number but the instrument: **the
stand cannot today tell which of two keys won, so any axis-B number about this cure
would come out right for a reason unconnected to the cure** — and P0, which repairs
that, invalidates the very table this file predicts from, so the table is
re-derived as part of it.

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

**DoD, in two parts, because the second is what revision 3 got wrong.**

First: distinguishable magnitudes per side **wherever a third value exists and
a shared pointer makes it matter**, declared rather than computed, in the file
whose subject this already is.

Revision 4 said "wherever a third value exists" and gave every exception the
same reason. Measured, there are two. `bool` has two values and one of them is
the level default; the `scope` enum is `all`/`application` and `all` is the
default — for those no third value exists at all. But `severity` is
`info|warning|error` and `mode` is `ignore|warn|error`, and there a third word
is there for the taking; what those five cells lack is a pointer their two
sides share, and two of them are `refuse` promises where the value decides
nothing. Right outcome, and revision 4 had the wrong reason for half of it.

**"The duplicate documents counted once" was not delivered, and P0 says so
rather than passing off the substitute.** `PairRow::key()` carries the kind
deliberately — its docblock records that removing it collapses nineteen rows
onto thirteen and that a divergence between two kinds "would have been silently
averaged" — so a row's promise belongs to its kind and the count stays keyed by
cell. What P0 adds is the second number: the run reports axis B's documents
beside its cells, 446 over 465, 93 defective over 111. Wherever this file counts
documents it means the former.

**And the residue is not six cells.** Measured on run #1: of the 465 axis-B
cells, 50 took no per-side magnitude, and twelve of those sit at a kind this
round is about — eleven of them published as `COEXISTENCE_OK, "both effects
present"` on a question nothing could answer. The six named later in this file
are the cells that stay unobservable **after the cure under its assigned
values**, which is a different set answering a different question; running the
two together is what produced the number six.

P0 counts this residue in its run output — the `axis B vacuous` line — rather
than arguing it, and does not convert it. Turning a green verdict into a gate
means a sensitivity branch in `Classifier::pair()`, which also re-judges the
frozen half and therefore moves the floor; that is the obligation of the package
owning that file, and `02-cure.md`'s P2 carries it with the two properties the
gate must have.

Second: **P0 invalidates the table below and must re-derive it.** Every verdict in
`measurement/axis-b-rows.tsv` was measured at the canonical magnitudes P0 removes,
and the cell count treats 465 rows as 465 documents where 19 triples are one
document counted twice. So regenerating the assignment table at the new magnitudes
is part of P0's own deliverable, and only the regenerated table is an oracle. The
pre-P0 table is kept as the witness of what P0 changed, not as a prediction.

The proof P0 worked is a stand run **on the unfixed product**, before P2 and before
the cure, whose axis-B number matches the regenerated table's count for today's
ledger values. A repair that moves the number in an unpredicted direction is not
accepted on the ground that the direction looks better.

Until P0 lands, no axis-B number in this file is evidence about anything —
including the three in the grid section below.

## The ledger, row by row

The assignment is not a formula but a table: `measurement/axis-b-rows.tsv`, one
row per axis-B cell, with today's verdict, the verdict today's value would give
after the cure, the assigned value, and the verdict that value gives. Read it
rather than this summary.

Of 465 axis-B cells, 128 are within the cure's radius and all 81 of today's
defects lie inside those 128. The 128 cells in the radius take — 124 judged, plus the four `refuse` rows C4
leaves alone:

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

One clause is still missing from that definition and must be added before the
branch lands: the deep key must have **no effect outside its own region**. As
stated, the branch constrains the shallow key everywhere and the deep key only
inside the overlap, so a deep key that reached further than its region would pass.
Nothing in the population does that today, which is exactly why the gap is
invisible — and why it is written down instead of discovered later.

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

**Fifteen cells read NOT OBSERVABLE after the cure at today's magnitudes.** Named in full rather than summarised, because a count without names is
what the last revision was caught doing:

| rule                               | pair                                                    | kind                                                              |
| ---------------------------------- | ------------------------------------------------------- | ----------------------------------------------------------------- |
| `complexity.{ccn,cognitive,npath}` | `callable: × threshold`                                 | 3-shorthand-vs-level-block                                        |
| `complexity.{ccn,cognitive,npath}` | `callable.threshold × threshold`                        | 2-same-name (and again under 4-cross-level — see P0's duplicates) |
| `complexity.npath`                 | `callable.enabled × enabled`, `class.enabled × enabled` | 2-same-name                                                       |
| `complexity.npath`                 | `callable × enabled`, `class × enabled`                 | 6-gate                                                            |
| `coupling.cbo`                     | `class.scope × scope`                                   | 2-same-name, 6-precedence-fill-in                                 |

**Revision 3 gave these the wrong cause, and the correction matters more than the
number.** It said the three `callable: × threshold` rows are NOT OBSERVABLE "by a
property of the contract, not of the magnitudes". The measurement it cited says
the opposite in so many words: `Stand::effectWritesFor()` returns one canonical
literal per declared shape, so a block's band and a shorthand's unfolded band are
equal **by construction**, and the block's `enabled: true` equals the level default
and adds nothing. The two sides are identical because of the magnitudes,
systematically rather than by coincidence, and the cure lives where P0 lives.

The assignment stands on semantics and does not move: `one-wins:callable:` is the
value that says what the contract says, and `deeper-wins:` was measured and
declines rather than greens. What moves is the verdict it is given once the stand
can tell the two sides apart.

**Six of the fifteen survive P0.** Four `complexity.npath | *.enabled × enabled`
and two `coupling.cbo | class.scope × scope`: `bool` has two values and one is the
level default, and the `scope` enum's `all` is the default too, so no third
magnitude exists to make the sides differ. These six are NOT OBSERVABLE by the
shape of their values — the one case in this file where the verdict is permanent
rather than an artefact.

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

| after | expected                                                                                                    |
| ----- | ----------------------------------------------------------------------------------------------------------- |
| P0    | the count the **regenerated** table gives for today's ledger values, on the unfixed product                 |
| P2    | axis B rises to the count the regenerated table gives for the assigned values, still on the unfixed product |
| cure  | the count the regenerated table gives for the assigned values on the cured product                          |

Each of the three is a number the regenerated table produces BEFORE the run, not
a direction. "Rises" and "0" are not acceptance criteria; revision 3 wrote both,
and wrote "15 NOT OBSERVABLE" from a table P0 invalidates.

One row-wise exception, measured rather than assumed: on four
`complexity.npath | *.enabled × enabled` rows the stand's own choice of magnitude
moves with the product, because `pairSide()` takes the canonical value only when it
differs from the omitted case on the current build — and B3 is exactly what makes
`enabled: true` stop being inert there. Those four rows are about different
documents on run #1 and run #3, so their numbers are not compared row-wise. Named
here rather than discovered later as a discrepancy.

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

`CHANGELOG.md` gets `Breaking` entries for B1 through B4, each with a migration
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
