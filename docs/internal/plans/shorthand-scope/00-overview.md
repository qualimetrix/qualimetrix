# A shorthand beside the block it is shorthand for

**Status:** planned. The semantics were decided in a previous round; the plan
built on them was withdrawn on seven HIGH findings before any code existed. This
round answers the five questions that withdrawal left open, and then cures.

**Base:** `main` at `c755fe00` (PR #68, the ledger's closed promise vocabulary).

## The question

Five rules implement `HierarchicalRuleOptionsInterface`. Each accepts a band at
the rule's own top level — `threshold`, or the graduated pair it is shorthand
for — and a nested per-level block beside it. Today the top-level band makes
`fromArray()` return before it reads the block: the block is validated at the
recognition seam and then discarded for effect.

Measured on this tree, with the product binary (`measurement/user-documents.md`,
`measurement/seams.md`):

- `coupling.cbo: {class: {warning: 1, error: 1}}` reports 27 findings. The same
  block with `threshold: 50` written beside it reports **none**.
- `complexity.ccn: {threshold: 3}`, alone, reports **no class-level finding**
  where the rule's own default reports one. The shorthand does not leave the
  class level at its default — it switches the level off.

## What was already decided, and stands

**Silence goes.** A written block that changes nothing is not configuration.
This rests on the programme's thesis (a recognised key does what its name
promises), on the website's own phrasing — which flags the discard down to the
word "silently" — and on the two measurements above.

**`threshold` beside `warning`/`error` at the SAME depth stays refused.** Two
spellings of one value in one slot is a contradiction. That refusal ships today
and this round does not touch it.

## What this round decides — the contract in one sentence

**A top-level band covers the levels it declares and nothing else; within one
layer the deeper key wins for what it names and the top-level band fills only
the levels whose band that layer left unwritten; across layers nothing composes
at all, because every layer is made unambiguous before the merge and the merge
is then plain key-for-key priority.**

The five questions the withdrawal left open are answered in
[`01-contract.md`](01-contract.md), each with the measurement that decides it and
the cost of the answer. Two of the answers are breaking changes and are named as
such there, not derived by analogy from ADR 0058 — which settled a different
axis (the integrity of one layer's value across layers, key by key).

## Where the cure lives, and why not where the withdrawn plan put it

The withdrawn plan put it in `fromArray()`. By then both merges have happened
(`FindingConfigurationResolver::mergeRuleOptions()` for preset↔config,
`RuleOptionsFactory::deepMerge()` for config↔CLI), so two documents with
opposite intent arrive as the same array and "the more specific key wins"
silently becomes "wins regardless of layer" — reversing the priority the product
already promises.

The cure therefore lives where ADR 0058 put its own: in the per-layer unfold
that runs before either merge. Measured (`measurement/seams.md`, Q1/Q4):

- `RuleOptionsFactory::deepMerge()` calls `RuleOptionThresholdShorthand::unfold()`
  on **every** layer unconditionally, including a run with no preset and no CLI
  layer, so the single-layer document does reach the seam;
- at `path=''` the array the seam receives carries the nested block as a value
  (`in={"threshold":3,"class":{…}}`), so the seam can see what it must now act
  on. Today's `unfold()` only inspects top-level keys — that is the gap, not a
  blindness of the seam;
- for `coupling.cbo`/`coupling.instability` the top-level `threshold` is already
  unfolded into the top-level graduated pair before `fromArray()` — and that
  pair is exactly what opens the flat branch that drowns the block. So the cure
  must remove the top-level band once it has been pushed down, not merely add a
  reader for the block.

The registry already describes the missing shape in prose: the
`LONE_THRESHOLD_SHAPE` comment says the shorthand of these rules "is cross-path
and disables the neighbouring level". That is a form the registry has no way to
declare. This round gives it one.

## Stages

| stage             | file                                       | produces                                                                                                    |
| ----------------- | ------------------------------------------ | ----------------------------------------------------------------------------------------------------------- |
| 1. Contract       | [`01-contract.md`](01-contract.md)         | the five answers, the per-rule reach table, the breaking changes named                                      |
| 2. Corpus witness | [`02-cure.md`](02-cure.md), package P1     | a finding-gate case that carries the subject, proved to redden against the pre-cure product                 |
| 3. Cure           | [`02-cure.md`](02-cure.md), packages P2-P4 | declared reach in the registry, cross-path unfold, five `fromArray()` implementations without early returns |
| 4. Accounting     | [`03-acceptance.md`](03-acceptance.md)     | ledger rows, floor, grid, declared gate delta, website in both languages, ADR, CHANGELOG                    |

Stage 1 goes to review before a line of stage 3 is written. On the two previous
rounds of this programme, review of the plan found two product defects that five
measurement axes had not, and stopped a round that would have been wrong three
independent ways. The price both times was one run of two reviewers.

## What is measured and must not be re-derived

`measurement/` holds: the branch enumeration by file and line
(`branches.tsv`); eight live observations of documents a user could write
(`user-documents.md`); what the website says today (`docs-today.md`); the three
candidate semantics with the cost of each (`candidates.md`); the `enabled: false`
question with the facts on both sides (`enabled-question.md`); the two review
files that withdrew the previous plan (`review-native.md`, `review-codex.md`);
this round's seam measurements (`seams.md`) and the population enumeration with
today's outcome per cell (`population.tsv`, `population.md`).

## Premises this round re-measured, and what changed

The brief's numbers were re-taken rather than inherited. What held: the grid
(122 defects — A 4, B 81, C 0, D 37, E 0), cell for cell; the controls (42
cases, 0 failures); the ledger's `kind=3-shorthand-vs-level-block` split (7
`refuse`, 11 `compose`, 18 cross-source `DEFERRED`). What did not:

1. **Axis B does not block.** `run-declaration.tsv` declares
   `blocking-axes = A,C,D,E`. The 81 cells this round cures redden nothing
   today; the run's exit 1 comes from axes A and D.
2. **Axis B needs no new `coexistence` value.** The brief states it will. Under
   the contract in `01-contract.md` every affected row lands on `compose`, which
   the vocabulary already carries — see `03-acceptance.md` for the seven rows
   that move and why.
3. **The floor holds 27 rows, not 26.** One is `withdrawn`
   (`form|yaml|computed_metrics.<name>.enabled|null`), a disposition mutually
   exclusive with `cure`.
4. **The four `pending:` rows cannot be converted on their own.** `floor.tsv`
   says so itself: the retake is not the fix, and converting them is that
   retake's first step. This round does not retake — see `03-acceptance.md`.
5. **`380 / 85 / 108` describes the `pair` population, not the ledger.** Over
   the whole file column 6 reads 380 / 88 / 271.
6. **`axis-b-mechanisms.tsv` is stale in two places.** It carries four
   mechanisms summing to 82 while the live axis is 81: M4 counts a cell that no
   longer exists, and M2 absorbs one `npath|class.enabled|enabled` cell its own
   text does not describe.
7. **The website is six locations, not three.** Each of the three pages has a
   `.ru.md` counterpart carrying the same claim, and the project requires both
   to move together.
