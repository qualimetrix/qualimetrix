# What this round measured before it planned

Every row here was produced by the orchestrator, not quoted from a brief or a
previous round. Where a number contradicts what was handed to the round, the
contradiction is stated rather than smoothed.

## 1. The base is what it was said to be

`git merge-base --is-ancestor 1210b037 origin/main` → yes; `HEAD` = `origin/main`
= `1210b037`, tree clean. (Two previous rounds were handed the same claim and one
of them was wrong, so it is checked rather than assumed.)

## 2. Call sites of `ThresholdParser::parse()` — 31 in 30 files

Counted a third way, by `token_get_all()`, which drops comments and docblocks by
construction. A raw `grep` reports 41 in 32 because it counts `{@see}` in
docblocks; a reviewer's "32 in 32" is wrong. Two independent witnesses agreed on
the same 30-file list: the tokenizer and Serena's `find_referencing_symbols`.

## 3. The defect reproduces on TWO layers, not three

| run                                                           | observation               |
| ------------------------------------------------------------- | ------------------------- |
| `qmx.yaml {callable.threshold: 5}` + CLI `callable.warning=2` | `warning@2`, **no error** |
| `qmx.yaml {callable.threshold: 5}` alone                      | `error@5`                 |

Subject: one method of cyclomatic complexity 6. The three-layer fixture ADR 0056
records was needed to tell *the middle layer's value* from *the constructor
default*, not because the mechanism needs a third layer. The regression test
therefore pins both shapes.

The three-layer fixture, reproduced: L1 preset `{warning: 2, error: 3}`,
L2 `qmx.yaml {threshold: 5}`, L3 CLI `warning=2`.

| run      | observation               |
| -------- | ------------------------- |
| L1+L2+L3 | `warning@2`, **no error** |
| L1+L2    | `error@5`                 |
| L1+L3    | `error@3`                 |
| L2+L3    | `warning@2`, **no error** |

The surviving half is neither 3 nor 5: it is the constructor's 20, and 6 < 20.
The competing explanations diverge in the RESULT, not in the argument — which is
the only thing that broke the tie the last time this measurement was read wrong.

## 4. `threshold: ~` across sources is worse than ADR 0056 records

| run                                                         | observation           |
| ----------------------------------------------------------- | --------------------- |
| preset `{warning: 2, error: 3}` + `qmx.yaml {threshold: ~}` | **no finding at all** |
| preset `{warning: 2, error: 3}` alone                       | `error@3`             |

ADR 0056 says "the result then defaults". It loses BOTH halves, not one: an
overlay writing a key that selects no mode silences the lower layer's entire
band, and the rule falls to 10/20 where the subject scores 6. Unfolding does not
close this; the presence test must itself become a writtenness test.

## 5. A `~` above an explicit value erases it — the mirror of §4

Raised by both reviewers of this plan, reproduced here before it was accepted.

| run                                                 | observation           |
| --------------------------------------------------- | --------------------- |
| preset `callable {warning: 2, error: 100}` alone    | `warning@2`           |
| the same, under `qmx.yaml {callable: {warning: ~}}` | **no finding at all** |

Both merge sites assign unconditionally, so the overlay's `null` lands in the
merged array; `firstWrittenKey()` asks `isset()`, for which a written `null` and
an absent key are one thing; the slot falls to the compiled default of 10, and the
subject scores 6.

This is NOT the same cell as §4. There the overlay wrote `threshold: ~` — a
shorthand KEY, not a value slot — and the band was lost to eviction. Here the
overlay writes `warning: ~` — a value slot — and the value is lost to the merge's
own write. Two mechanisms, one class, and curing the first does not touch the
second.

## 6. The floor reports a row it stopped observing as cured

`Floor::judge()` returns `held = false` for a row the grid does not carry;
`cureMisses()` reads `!$held` on a row with a `cure` as success. A row that left
the population is therefore proof of repair. `judge()`'s own docblock says the
opposite — "neither held nor cured: it is a stale declaration" — so this is the
third docblock in this subtree found disagreeing with its code.

Reachable through ordinary flags, too: `--axis=` narrows every run including
`--freeze-before`, so a snapshot can be taken over a subset of axes; `shot.txt`
records the subset and `--before` never compares it against the canonical list.

## 7. The `cure` column cannot be checked by git ancestry

`7fe879f6`, `b9fd87d3`, `f20bd708` — the three commits standing in filled `cure`
cells — are **not ancestors of `origin/main`**: they are pre-squash branch commits
living only in one clone. An ancestry check would redden twenty-one standing rows
here and refuse every row on a fresh clone.

Separately: `scripts/promise-effect.php`'s floor block promises that "a cure that
names a commit the snapshot does not contain is the lie this catches".
`Floor::cureMisses()` contains no such check. Docblock and code disagree.

## 8. Only three of eight forms are reachable as a map KEY

`Symfony\Component\Yaml\Yaml::parse()` over the eight canonical forms in key
position: `~`, `true`, `7331.9`, `[7331]` and `{a: 7331}` are **refused by the
parser** before the product sees anything; `7331` and `"7331"` both arrive as the
**same** integer key; only `abc` arrives as a string. A population built as
position × 8 forms would record false collapses and blindness for five sixths of
its map side.

## 9. The three word-set readers already refuse, and fold case on purpose

`InlineDirectiveOptions:89`, `UnassignedClassOptions:183`,
`LayerViolationOptions:208` each `strtolower()` the value and each throws naming
the accepted set. Case folding is pinned by tests
(`LayerViolationOptionsTest::itParsesSeverityCaseInsensitively`,
`InlineDirectiveOptionsTest` with `'Warning'`). Every use of these keys tracked in
the tree is lowercase.

`RuleOptionWordSet`'s class docblock says "Matching ignores letter case"; its
`contains()` is strict, with a comment saying deliberately so. The class docblock
is stale, and it states the cure this round adopts.

## 10. The live grid, read off the committed artifact

A 12, B 82, C 4, D 37, E 0 — 135 defects over 7218 cells. Matches what the round
was handed.

## 11. Numbers the round was handed that do not survive measurement

- **axis B "blocked on channel boundary"** — 4 mechanisms, 81 of 82 cells one
  copy-pasted early-return pattern in 5 sibling options classes, 0 blocked.
  `channel-identity-substrate.md` is about finding identity and never mentions
  the axis.
- **"1202 blind cells on axis A"** — a retired snapshot's number on a
  6289-cell grid. Today's comparable figure is 155 rows / 226 cells.
- **"6 top-level bases are defective envelopes"** — 2 are (`patterns`, `suffix`),
  covering the 4 rows; the other four carry the same literal but promise only
  `list`, so today's refusal is already correct.
- **"the cure answers the first of ADR 0055's three questions"** — ADR 0055 says
  the three must be decided together. The third was already decided by ADR 0056;
  this round decides the second alongside the first, which is what closes the
  coupling.

## How this was obtained, and what it does not see

Product behaviour: `bin/qmx check` over a purpose-built fixture, `--workers=0`,
a unique `--cache-dir` per run deleted beforehand, exit code read off the process.
Call-site counts: PHP tokenizer plus Serena, two witnesses. YAML coercion: a
direct `Yaml::parse()` probe. Grid figures: the committed `verdicts.tsv`, not a
fresh run.

Not seen: no behaviour of the deferred axis F was observed (its probe does not
exist); the bool-blindness figure counts what reads `NOT OBSERVABLE` today, not
what would be blind if the refusal framing did not take priority; direct
`fromArray()` callers bypassing the factory were not swept.
