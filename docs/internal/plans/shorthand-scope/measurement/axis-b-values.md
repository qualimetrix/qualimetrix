**Axis B after the cure, whole: 465 cells · 128 the cure can move · 337 it cannot ·
today 81 defects · under today's ledger values 52 (43 cured, 14 NEW regressions) ·
under the assigned values 0 defects, 15 NOT OBSERVABLE.** Assigned over the 124
judged movable cells: `compose` 27 · `deeper-wins:<key>` 80 · `one-wins:<key>` 17
(+4 `refuse` cells C4 leaves untouched). For the 18 rows of round 1 alone:
compose 3 · one-wins 3 · deeper-wins 12.

**The finding that outranks all of it: the stand writes ONE canonical literal
(`7331`) for every numeric key, so the two keys of a pair dispute their shared
pointer with the SAME VALUE — and no leaf comparison can then say which won.**
Measured, not argued: at those magnitudes `compose` goes green post-cure for all
18 subject rows, so the plan's "axis B → 0" comes true TAUTOLOGICALLY; and the new
branch goes green on 33 of 124 cells of TODAY'S UNCURED product. This is a stand
defect (`Stand::pairSide()`), not a vocabulary one, and until it is fixed no
axis-B number about this round's subject means anything.

# M4 — what value each of the 18 ledger rows must carry after the cure

Measurement and design only. The repository was not touched: `git status --short`
is empty at hand-off (checked, output below). Every patch lives in a `mktemp -d`
copy of the tracked tree at `<copy>`
(`tree/` = patched, `pristine/` = byte-identical to HEAD `47b940ec`).

## 0. Commands the orchestrator can repeat verbatim

```bash
D=<scratchpad>
TMP=$(cat "$D/TREE_COPY.txt")

# the 18 rows at their assigned values, on the contract's own outcomes
php "$D/probe.php" "$TMP/tree"                 # exit 0, "18 rows, 0 not COEXISTENCE_OK"

# eight negative controls + the dictionary half
php "$D/controls.php" "$TMP/tree"              # exit 0, "0 unexpected outcome(s)"

# claude-07: the one-wins hole, on the UNPATCHED tree
php "$D/one-wins-hole.php" "$TMP/pristine"

# does the ledger of today refuse the new value at all
php "$D/dict-negative.php" "$TMP/pristine"     # exit 1, REFUSED
php "$D/dict-negative.php" "$TMP/tree"         # exit 0, 12 rows carry it

# is the assignment robust to the one ambiguity 01-contract.md leaves open
php "$D/halfband-robustness.php" "$TMP/tree"

# rebuild the copy from scratch (rsync, because git archive honours export-ignore
# and drops docs/, scripts/, tests/, website/ — worth knowing, it cost a round)
cd <repo>
git ls-files > "$TMP/files.txt" && rsync -a --files-from="$TMP/files.txt" ./ "$TMP/tree/"
python3 "$D/patch.py" "$TMP/tree"
```

Artefacts: `rows.tsv` (the table), `assignment.php` (the same assignment, machine-readable),
`probe.php`/`probe.out`, `controls.php`/`controls.out`, `one-wins-hole.php`/`.out`,
`halfband-robustness.php`/`.out`, `patch.py`, `patch.diff` (the whole product-side cost as a diff).

## 1. The table — `rows.tsv`

18 rows, `kind=3-shorthand-vs-level-block`, `source_scope=same-source`, ledger lines
1577-1594. The count is confirmed, not assumed:

```bash
awk -F'\t' '$1=="pair" && $9 ~ /kind=3-shorthand-vs-level-block/ && $5=="same-source"' \
  promise-effect/promise-ledger.tsv | wc -l   # 18
```

The verdict on a row is decided by two facts and nothing else, both read out of
`01-contract.md`'s "Reach, per rule" table:

| does the top key's reach cover the level the block names? | does the top key also reach the SIBLING level? | value                  | rows                                                      |
| --------------------------------------------------------- | ---------------------------------------------- | ---------------------- | --------------------------------------------------------- |
| no                                                        | — (the top key's only level *is* the sibling)  | `compose`              | 3 — complexity `class: × threshold` (1578, 1580, 1582)    |
| yes                                                       | no                                             | `one-wins:<block key>` | 3 — complexity `callable: × threshold` (1577, 1579, 1581) |
| yes                                                       | yes                                            | **new value**          | 12 — every coupling row (1583-1594)                       |

Two things the row list settles that a formula would hide:

- **The block is not always `key_a`.** In 1586, 1592 and 1593 the top-level key is
  `key_a` and the block is `key_b`. Any value that names a winner must name it by
  KEY (as `one-wins:` does) and be translated to a side by the stand; a branch that
  assumes "block = A" is wrong on three rows out of twelve.
- **The block writes the level WHOLE.** `Stand::pairCandidates()` (`Stand.php:1388-1423`)
  writes every leaf the ledger names under that level for that rule, minus `threshold`.
  Enumerated from the ledger: complexity `callable:` → {enabled, error, warning};
  complexity `class:` → {enabled, max-error, max-warning}; cbo `class:` →
  {enabled, error, scope, warning}; cbo `namespace:` → {enabled, error, min-class-count,
  warning}; instability `class:` → {enabled, max-error, max-warning, min-afferent};
  instability `namespace:` → the same plus min-class-count. So for all 18 rows the
  block names its level's band — which is what makes the contract's outcome unambiguous.

## 2. The missing value: `deeper-wins:<key>`

**Meaning.** Both keys are in effect; their reaches overlap; inside the overlap the
deeper key wins; outside it the shallower key's effect survives whole.

**Definition — what must survive from each side**, given the four observations
(`omitted`, `deeper alone`, `shallower alone`, `both`):

- **R (the deeper key's region)** = the longest common pointer prefix, at segment
  granularity, of the leaves the deeper side changed off `omitted`. For a level
  block that prefix is the level (`/class`), which is exactly "what it names".
- **(a)** every leaf the deeper side changed is present in `both` with the same
  value — the deeper key loses nothing;
- **(c)** every leaf the shallower side changed whose pointer lies OUTSIDE R is
  present in `both` with the same value — the shallower key keeps its sibling reach;
- inside R the shallower key is not required to survive, and that is the whole
  difference from `compose`;
- **(b) two sensitivity gates**, so the value does not silently absorb its
  neighbours: if the shallower side changes nothing inside R the row is `compose`
  and not a depth (NOT OBSERVABLE); if it changes nothing outside R the row is
  `one-wins` and not a depth (NOT OBSERVABLE).

**Why the key is carried in the value.** The classifier receives sides, not keys, and
the sides are not ordered by depth (see 1586/1592/1593). `deeper-wins:<key>` is
translated to `deeper-wins:a|b` by the stand at the same two places `one-wins:` is.

**Rejected alternatives.**

| rejected                                        | why                                                                                                                                                                                       |
| ----------------------------------------------- | ----------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| `compose`                                       | measured MISCOMPOSED on the contract's own outcome (controls NC6, and claude-01's own run)                                                                                                |
| `one-wins:<block>` for all 15                   | measured MISCOMPOSED on the twelve coupling rows (NC7): the top key keeps a real effect on the sibling level, so `both ≠ deeper alone`                                                    |
| loosening `effectSurvives()` / `compose` itself | `compose` is leaf-strict on purpose; loosening it would stop every other pair row reddening on a genuine drop. A third branch costs the same and keeps the old promise intact             |
| a bare `deeper-wins` with no key                | the classifier would have to infer the deeper side from a trailing colon in the key spelling — semantics re-derived from spelling, and wrong the moment a deeper key is not a level block |
| `overlap:deeper`, `specificity:<key>`, `nests`  | name a principle, not an outcome, and still do not say which side is deeper                                                                                                               |
| a new ledger COLUMN carrying the region         | an extra cell on 465 rows and an extra freshness surface, for information the observation already carries                                                                                 |
| `partial-compose`                               | says which part failed, not which key won; a reader cannot tell what was promised                                                                                                         |

**Superseded by round 3 — read §14 instead.** This section first described the
in-region test as "the deeper side's CHANGED leaves survive", with a declared
blind spot said to be unreachable because the stand writes the block whole. That
was measured wrong once the population grew from 18 rows to 124: on the ten
`6-gate` block rows the block writes `enabled: true`, which equals the level
default and therefore is not a CHANGED leaf, so the blind spot sat exactly on the
leaf those rows are about. The branch now tests EQUALITY with the deeper side
written alone, leaf for leaf, inside the region — which is K4 — and the concern
that motivated the weak form (a top-level `enabled` legitimately filling a level
that wrote only its band) does not apply, because that row is `compose`, not
`deeper-wins`: the two keys there name different groups. Numbers in §14.

Likewise the region is the LEVEL, not the longest common prefix: the prefix is
backed off by one segment when it is itself a leaf, without which the branch
reddens a correct product on all 16 `4-cross-level` rows whose deeper key writes
half a band.

## 3. Proof by run, not by argument

```
line  rule                   key_a       key_b       assigned                 verdict          decided_by
--------------------------------------------------------------------------------------------------------------------------------------------------------------------------
1577  complexity.ccn         callable:   threshold   one-wins:callable: -> one-wins:a COEXISTENCE_OK   the promised key won
1579  complexity.cognitive   callable:   threshold   one-wins:callable: -> one-wins:a COEXISTENCE_OK   the promised key won
1581  complexity.npath       callable:   threshold   one-wins:callable: -> one-wins:a COEXISTENCE_OK   the promised key won
1578  complexity.ccn         class:      threshold   compose -> compose       COEXISTENCE_OK   both effects present
1580  complexity.cognitive   class:      threshold   compose -> compose       COEXISTENCE_OK   both effects present
1582  complexity.npath       class:      threshold   compose -> compose       COEXISTENCE_OK   both effects present
1583  coupling.cbo           class:      error       deeper-wins:class: -> deeper-wins:a COEXISTENCE_OK   the deeper key won inside /class and the shallower key survived outside it
1584  coupling.cbo           class:      threshold   deeper-wins:class: -> deeper-wins:a COEXISTENCE_OK   the deeper key won inside /class and the shallower key survived outside it
1585  coupling.cbo           class:      warning     deeper-wins:class: -> deeper-wins:a COEXISTENCE_OK   the deeper key won inside /class and the shallower key survived outside it
1586  coupling.cbo           error       namespace:  deeper-wins:namespace: -> deeper-wins:b COEXISTENCE_OK   the deeper key won inside /namespace and the shallower key survived outside it
1587  coupling.cbo           namespace:  threshold   deeper-wins:namespace: -> deeper-wins:a COEXISTENCE_OK   the deeper key won inside /namespace and the shallower key survived outside it
1588  coupling.cbo           namespace:  warning     deeper-wins:namespace: -> deeper-wins:a COEXISTENCE_OK   the deeper key won inside /namespace and the shallower key survived outside it
1589  coupling.instability   class:      max-error   deeper-wins:class: -> deeper-wins:a COEXISTENCE_OK   the deeper key won inside /class and the shallower key survived outside it
1590  coupling.instability   class:      max-warning deeper-wins:class: -> deeper-wins:a COEXISTENCE_OK   the deeper key won inside /class and the shallower key survived outside it
1591  coupling.instability   class:      threshold   deeper-wins:class: -> deeper-wins:a COEXISTENCE_OK   the deeper key won inside /class and the shallower key survived outside it
1592  coupling.instability   max-error   namespace:  deeper-wins:namespace: -> deeper-wins:b COEXISTENCE_OK   the deeper key won inside /namespace and the shallower key survived outside it
1593  coupling.instability   max-warning namespace:  deeper-wins:namespace: -> deeper-wins:b COEXISTENCE_OK   the deeper key won inside /namespace and the shallower key survived outside it
1594  coupling.instability   namespace:  threshold   deeper-wins:namespace: -> deeper-wins:a COEXISTENCE_OK   the deeper key won inside /namespace and the shallower key survived outside it

18 rows, 0 not COEXISTENCE_OK
```

The probe does not re-implement the key→side translation: it takes it from the
patched `Stand::sideOfWinner()` by reflection, so it cannot agree with itself about
a translation the stand does differently.

### Negative controls — eight, plus the dictionary

```
NC1  block loses on its own level                    deeper-wins:class: -> deeper-wins:a MISCOMPOSED      (defect)
         the product let the top key win inside /class -- exactly what C2 forbids
         -> absent when both are written: the deeper key at /class/error

NC2  top key left no trace on the sibling            deeper-wins:class: -> deeper-wins:a MISCOMPOSED      (defect)
         the block swallowed the whole rule; the top key's namespace reach is gone
         -> absent when both are written: the shallower key at /namespace/error, outside the deeper key's /class

NC3  winner swapped in the ledger                    deeper-wins:error -> deeper-wins:b MISCOMPOSED      (defect)
         the SHALLOW key is named as the deeper one, on the correct outcome
         -> absent when both are written: the deeper key at /class/error

NC4  the value on a row whose reaches never meet     deeper-wins:class: -> deeper-wins:a NOT OBSERVABLE   (not a defect)
         complexity.ccn | class: x threshold -- this is `compose`, not a depth
         -> the shallower key writes nothing inside /class, so the two never dispute and this is `compose`, not a depth

NC5  the value on a row where the shallow key has nothing outside deeper-wins:callable: -> deeper-wins:a NOT OBSERVABLE   (not a defect)
         complexity.ccn | callable: x threshold -- this is `one-wins`, not a depth
         -> the shallower key writes nothing outside /callable, so nothing survives it and this is `one-wins`, not a depth

NC6  `compose` on a coupling row (claude-01, on this model) compose -> compose       MISCOMPOSED      (defect)
         the value the plan assigns; must stay red
         -> the effect of B is absent when both are written

NC7  `one-wins:<block>` on a coupling row (claude-01, on this model) one-wins:class: -> one-wins:a MISCOMPOSED      (defect)
         the value the review found insufficient; must stay red
         -> a different key won

NC8  positive control, for contrast                  deeper-wins:class: -> deeper-wins:a COEXISTENCE_OK   (not a defect)
         the contract's own outcome at the assigned value
         -> the deeper key won inside /class and the shallower key survived outside it

--- dictionary ---
the assigned values              LOADED
a suffix naming neither key      REFUSED: pair row "coupling.cbo" promises coexistence "deeper-wins:zzz", whose key is neither "class:" nor "error", so the stand would silently read it as a win for the second key

0 unexpected outcome(s)
```

NC8 is the positive control in the same run: the branch is not blanket-green.
NC1 is the case the task names (the block loses on its own level) — MISCOMPOSED, defect.
NC2 is its mirror (the top key loses the sibling level) — MISCOMPOSED, defect; without
it the branch would be satisfied by `both = the block alone`, i.e. by `one-wins`.
NC3 is a ledger-authoring error (the shallow key named as the deeper one) — MISCOMPOSED.
NC4/NC5 are the two sensitivity gates; they return NOT OBSERVABLE, not MISCOMPOSED,
deliberately: a wrong ROW is not a product composition defect, and the classifier's
own docblock warns against publishing one under the other's label. The cost of that
choice is that a misassigned row goes quiet rather than red — which is why the
dictionary guard below is part of the price, not an optional extra.

**The one place a wrong cell does not redden, named.** The dictionary guard catches
only a suffix naming neither key. `deeper-wins:<a key the row really carries>` written
on one of the three `compose` rows passes the dictionary and then lands on NC4's
NOT OBSERVABLE — visible in the grid, but not a defect. That is the exact price of
choosing NOT OBSERVABLE over MISCOMPOSED for the two sensitivity gates, and it is
the only cell in this assignment whose misassignment goes quiet.

### One condition this measurement could NOT take

The probe writes each side with magnitudes chosen by hand to be distinct. The live
stand does not: `Stand::pairSide()` takes canonical magnitudes from
`effectWritesFor()`. For the three `one-wins:callable:` rows, `one-wins`'s own
sensitivity gate (`$onlyA->text === $onlyB->text` → NOT OBSERVABLE) would fire if
the block's canonical `warning`/`error` happened to equal the canonical `threshold`
pushed down into the same level AND the block's canonical `enabled` equalled the
level's default. That cannot be measured without `composer promise-effect`, which
this task forbids: **the condition is unmeasured and is checked by the first stand
run after P2. If it fires, it is a property of the magnitude choice in
`pairCandidates()`, not an error in the assignment.** For the twelve
`deeper-wins:` rows a magnitude collision on the disputed pointer does not change
the verdict — the block's leaf survives either way and the top key's leaf inside
the region is unconstrained.

### The ambiguity `01-contract.md` leaves open

C2 says the fill unit is the band, but does not say what a HALF-written top-level
band pushes down: only the half written, or the whole band with the other half at
the TOP-LEVEL default. This bears on the eight `× warning | error | max-*` rows.
Measured both ways, the assignment holds:

```
cbo class: x warning, reading (i ) -> COEXISTENCE_OK   the deeper key won inside /class and the shallower key survived outside it
cbo class: x warning, reading (ii) -> COEXISTENCE_OK   the deeper key won inside /class and the shallower key survived outside it
```

So it is not a blocker for this table — but it IS an unsettled question about what
the stand writes, and it must be answered before the cure, because it changes the
measured object even where it does not change the verdict.

## 4. Price, item by item

Measured as a working patch: `patch.diff`, 300 lines of diff over three files.

| what                                                            | where (paths at HEAD `47b940ec`)                                                                                                                                                                                                                                                                                                                                                                                 |
| --------------------------------------------------------------- | ---------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| the branch in `pair()`                                          | `scripts/promise-effect/Classifier.php:284-298` (immediately after `one-wins:`)                                                                                                                                                                                                                                                                                                                                  |
| `depthResolved()` + `changed()` + `commonPrefix()` + `within()` | same file, beside `effectSurvives()` (`:666-685`) — 166 added lines with docblocks                                                                                                                                                                                                                                                                                                                               |
| the dictionary: a second accepted PREFIX                        | `scripts/promise-effect/Ledger.php:318-344` (`assertKnownCoexistence`), reading a new `Ledger::COEXISTENCE_PREFIXES` that `Stand` shares — one list, two consumers, because a form added to one call site and not the other is the defect class this programme keeps finding                                                                                                                                     |
| the dictionary: suffix validation                               | same method — it must be given `key_a`/`key_b`, so its signature grows and the call site `Ledger.php:239` passes `$cells[2], $cells[3]`                                                                                                                                                                                                                                                                          |
| the docblock that declares the closed set                       | `Ledger.php:284-303` — it currently states the set is "exactly what the classifier accepts", so it is wrong the moment the branch lands                                                                                                                                                                                                                                                                          |
| the refusal message                                             | `Ledger.php:338` — "expected one of `one-wins:<key>`, …" gains the second form                                                                                                                                                                                                                                                                                                                                   |
| the winner-key → side translation, BOTH sites                   | `Stand.php:316-318` (frozen half) and `:599-601` (live half). I folded them into one `Stand::sideOfWinner()`: adding a prefix to one and forgetting the other is precisely the defect class this programme keeps finding                                                                                                                                                                                         |
| the vocabulary test                                             | `tests/Unit/PromiseEffect/LedgerVocabularyTest.php` — one case mirroring `itAcceptsTheOneWinsFormWhichCarriesItsWinnerInTheValue` (`:94-106`), and a SECOND case for the suffix guard, which does not exist today for `one-wins:` either                                                                                                                                                                         |
| the stand's own controls                                        | `scripts/promise-effect-controls/Cases.php` — a `JudgementCase` for axis B with the new value beside PR1-PR3 (`:390-425`); without it the branch is not covered by `promise-effect:controls`. Note these call `Classifier::pair()` directly (`scripts/promise-effect-controls.php:1066-1071`), so they pass `a`/`b` literally and never exercise the translation — the translation stays uncontrolled either way |
| prose listing the vocabulary                                    | `promise-effect/README.md:94`; `promise-effect/README.md:55`; `promise-effect/README.md:47`; this round's `measurement/stand-and-ledger.md:229,235,248-252`                                                                                                                                                                                                                                                      |
| the 18 ledger cells                                             | `promise-ledger.tsv:1577-1594`, column 6, plus their notes: the notes of the seven `refuse` rows carry the reason "the coexistence column has no value meaning …", which stops being true                                                                                                                                                                                                                        |

Not touched by this assignment: `promise-effect/floor.tsv`. Its single standing row is
`pair|complexity.ccn|class:|threshold|same-source|3-shorthand-vs-level-block`, which
is one of the three `compose` rows and keeps its value.

## 5. claude-07 — the `one-wins:` hole, re-measured

```
=== 1. the three complexity rows at TODAY's code, value `one-wins:callable:` ===
  complexity.ccn         one-wins:callable: -> one-wins:a   COEXISTENCE_OK   the promised key won
  complexity.cognitive   one-wins:callable: -> one-wins:a   COEXISTENCE_OK   the promised key won
  complexity.npath       one-wins:callable: -> one-wins:a   COEXISTENCE_OK   the promised key won

=== 2. the hole as `stand-and-ledger.md:248` states it ===
  claim: "any `one-wins:<real key name>` silently means B won, whatever key is named"
  live:  one-wins:callable: with key_a=callable:  -> one-wins:a   (so the claim is false on the live path)
  live:  one-wins:namespace: with key_a=error     -> one-wins:b   (key_b named, read as b -- as documented)

=== 3. the hole that IS there: a suffix naming neither key ===
  live:  one-wins:zzz with key_a=callable:        -> one-wins:b
  verdict on a row whose winner is key_a          -> MISCOMPOSED      a different key won
  => on these three rows the typo is LOUD, not silent: it flips the expectation to the loser.
  same typo on a row whose winner is key_b        -> COEXISTENCE_OK   the promised key won
  => silent exactly there, and nowhere else.

=== 4. does TODAY's Ledger refuse the typo? ===
  one-wins:callable:       the value the three rows would carry     LOADED
  one-wins:zzz             a suffix naming neither key              LOADED
```

**Is the hole reachable on the live path?** As `stand-and-ledger.md:248` states it —
"any `one-wins:<real key name>` silently means «B won», whatever key is named" —
**no**. Both call sites translate the key to a side before the classifier sees it, so
`one-wins:callable:` on a row whose `key_a` is `callable:` becomes `one-wins:a`.
The review's narrowing is right and the measurement's paragraph is wrong as written.

The hole that IS reachable is narrower: a suffix matching NEITHER key (a typo, or a
key renamed out from under the row) passes `assertKnownCoexistence` on the prefix
alone and becomes `one-wins:b`. Measured on the unpatched tree: `one-wins:zzz` loads
without complaint. It is silent only on rows whose real winner IS `key_b`; on a row
whose winner is `key_a` it flips the expectation to the loser and reddens.

**Does it change the price of `one-wins:` for the three complexity rows?** **No.**
Their winner is `key_a` (`callable:`), so the documented behaviour is what they get,
and the hole's silent half does not touch them. Measured at TODAY's code, unpatched:
all three get COEXISTENCE_OK at `one-wins:callable:`. The price of those three rows
is therefore **zero product code** — three ledger cells and their notes. The
measurement's claim that "the price of the first such row is higher than a constant +
a branch + a test" is refuted for these three.

What the hole does change is the price of the NEW value, and that is where I put the
guard: the suffix check added to `assertKnownCoexistence` covers both prefixes at
once, because writing three `one-wins:` cells where there were zero makes an
unguarded suffix a live risk for the first time.

## 6. What this refutes in `03-acceptance.md`

| claim                                                                                                                  | verdict                                                                                                                                                                                                                                                                                              |
| ---------------------------------------------------------------------------------------------------------------------- | ---------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| "twenty-five rows move, **no new value is added**" (§ heading and first paragraph)                                     | **refuted.** Twelve of the eighteen `same-source` rows cannot be expressed by `compose`, `refuse` or `one-wins:`; measured MISCOMPOSED for each (controls NC6/NC7). A new value is added, and its price is §4 above                                                                                  |
| "Every affected row lands on `compose`, which the vocabulary already carries"                                          | **refuted.** Exactly 3 of 18 land on `compose`                                                                                                                                                                                                                                                       |
| "the seven move and **the eleven stay**"                                                                               | **refuted** for eight of the eleven: every coupling row that carries `compose` today (1583, 1585, 1586, 1588, 1589, 1590, 1592, 1593) must change. Only 1578/1580/1582 stay                                                                                                                          |
| the quoted ground "the coexistence column has no value meaning «the shorthand wins and the block is silently dropped»" | **refuted at the source.** `one-wins:<key>` means exactly that and is already accepted by the dictionary and awarded by the classifier; this round's own measurement records it at `stand-and-ledger.md:229,235`                                                                                     |
| "axis B's 81 defects … the expectation is 0 on the grown population"                                                   | **not reachable as stated.** With `compose` on all 18 rows the classifier awards MISCOMPOSED to 15 of them by construction (claude-01). With the assignment in `rows.tsv` the expectation of 0 becomes arguable again — but only once the value exists; until then the prediction has no denominator |
| "the seven `refuse` rows move to `compose`"                                                                            | **refuted per family.** The three complexity `callable: × threshold` rows move to `one-wins:callable:`, the four coupling `× threshold` rows to `deeper-wins:<block>`                                                                                                                                |
| the floor row "`pair|complexity.ccn|class:|threshold` … expected `MISCOMPOSED`" becomes `pending:`                     | **not refuted.** That row is one of the three `compose` rows, and `compose` is the right value for it                                                                                                                                                                                                |
| "The eighteen `cross-source` rows … are decided as `compose`"                                                          | **out of this measurement's scope** — they never reach the classifier (claude-02). Nothing here makes them measurable, so they carry an unmeasured promise whichever value they take                                                                                                                 |

## 7. Hand-off state

```
$ cd <repo> && git status --short

$ git log --oneline -1
47b940ec docs(plans): answer axis B's five questions before writing its cure
```

Empty. Every edit lives in `<copy>/tree`.

---

# Догрузка X24-2 — the whole of axis B, not a third of it

## 8. The population, and the feature that partitions it

465 `pair` rows carry `source_scope=same-source`, and `Stand::axisB()` judges
exactly those — 465 axis-B cells in `verdicts.tsv`, joined row-for-row with no
misses. Today: **384 `COEXISTENCE_OK`, 81 `MISCOMPOSED`**, by kind
`6-gate` 20 · `2-same-name-top-vs-level` 11 · `3-shorthand-vs-level-block` 18 ·
`4-cross-level` 32, which reproduces the coordinator's breakdown exactly.

**The feature is the cure's blast radius, and it is checkable, not asserted.**
The cure's file set (`02-cure.md`, P3/P4) is `RuleThresholdKeyGroupRegistry`,
`RuleOptionThresholdShorthand::unfold()` and five options classes. Enumerated
from the registry itself: **exactly five rules carry a level entry** —
`complexity.{ccn,cognitive,npath}`, `coupling.{cbo,instability}` — and exactly
those five implement `HierarchicalRuleOptionsInterface`. A document with no
top-level reach-carrying key never enters `unfold()`'s new step and never
reached the early return P4 deletes. So:

| feature                                        | rows    | today                 | can the cure move it                                                   |
| ---------------------------------------------- | ------- | --------------------- | ---------------------------------------------------------------------- |
| rule is not one of the five                    | 212     | all OK                | **no** — 49 other rules, no level entry, no hierarchical options class |
| one of the five, but both keys are level keys  | 125     | all OK                | **no** — the document carries no top-level key at all                  |
| one of the five, at least one key is top-level | **128** | 47 OK, **81 defects** | **yes**                                                                |

**Every one of the 81 defects is inside the 128.** That is the proof the feature
determines the verdict: the complement carries no defect to move and no code path
the cure touches. `rows-all.tsv` carries all 465 rows with this column filled in,
so the claim is checkable row by row rather than by sampling.

Of the 128, four are two top-level spellings of one band (`threshold × warning`
and friends) which C4 explicitly leaves refusing — unchanged. The other **124 are
judged by running the classifier**, not by reasoning: for each, the four
observations are the product's own frozen ones, with only the documents the cure
actually moves rebuilt from C1-C4.

```bash
php "$D/axis-b-all.php" "$TMP/tree" <repo> "$D/rows-all.tsv"   # writes rows-all.tsv, summary on stderr
```

## 9. What the contract does to the three kinds round 1 never looked at

**`6-gate`, 20 defects — M1, and the cure closes all 20.** The form is a
top-level `enabled` beside something else. Ten rows pair it with another
top-level key (`enabled × threshold`, `× warning`, `× scope`, …): different
groups, both unfold side by side, both survive — `compose` is correct and the
defect goes. Ten rows pair it with a level block (`class × enabled`,
`callable × enabled`, `enabled × namespace`): the block wrote its own `enabled`,
so under Q5 the top key does not fill that level but does fill the sibling —
that is `deeper-wins:<block>`, **not** `compose`. Note the ledger spells the
block `class` (no colon) on these rows and `class:` on the `3-shorthand` rows;
`Stand::pairSide()` `rtrim`s the colon, so the two spellings write the same
document and the difference is notation only.

**`2-same-name-top-vs-level`, 11 defects — `compose` does NOT hold.** The form is
a top-level key beside its own namesake inside a level. `compose` is leaf-strict,
and under C2 the level's namesake wins that level outright, so the top key's leaf
there does not survive. Correct value: `one-wins:<level key>` when the top key
reaches only that level (complexity's band, and `scope`), `deeper-wins:<level
key>` when it also reaches the sibling (all of coupling, and `enabled`
everywhere). the independent-key composition rule's "both apply at their own depth" is true of the
OUTCOME and false of the vocabulary word `compose` — the two had been treated as
the same thing.

**`4-cross-level`, 32 defects — the biggest kind, and the one the contract never
names.** Two forms share the label: 45 rows pair two LEVEL keys of different
levels (`class.warning × namespace.error`) — untouched by the cure, all OK; and
54 rows pair a level key with a top-level key of a DIFFERENT name
(`class.warning × error`, `callable.warning × threshold`, `class.threshold ×
threshold`). **Is the contract unambiguous for it? Yes — C2's last sentence is
exactly this case:** "The half it did not name takes that level's own default,
never the top-level value", restated as K4 in `02-cure.md`. So it is not a hole in
the contract. It IS a hole in the plan's account of itself: `01-contract.md` names
this shape nowhere, neither B1 nor B2 covers it, and **all 14 of the regressions
found below live in it.**

**The one key the contract genuinely does not settle is `scope`** (3 cells:
`class.scope × scope` twice, `enabled × scope` once). The reach table says
"already composes by key today" while C2 says the fill unit is the group; P3
never says whether `scope` moves to the seam. That is claude-05 with a count.

## 10. The reverse side: 14 regressions the cure creates

Cells that are `COEXISTENCE_OK` today and become `MISCOMPOSED` after the cure **if
the ledger keeps its current value**. All 14 are `4-cross-level`, all are a level
key holding HALF a band against a top-level key:

| rows | shape                                                                               | why it reddens                                                                                                                                                      | correct value              |
| ---- | ----------------------------------------------------------------------------------- | ------------------------------------------------------------------------------------------------------------------------------------------------------------------- | -------------------------- |
| 6    | `complexity.* \| callable.{warning,error} × threshold`                              | callable named its band, so the shorthand lands nowhere at all: `both` becomes the level key written alone and `compose`'s "the top key's effect survived" is false | `one-wins:callable.<leaf>` |
| 8    | `coupling.{cbo,instability} \| {class,namespace}.{warning,error,max-*} × threshold` | the named level keeps its own band; the shorthand survives only on the sibling                                                                                      | `deeper-wins:<level key>`  |

They are invisible to every instrument the plan names: axis B is not a blocking
axis, the gate's corpus has no case of this shape, and `03-acceptance.md`'s
prediction counts three mechanisms, none of which is this one.

## 11. The prediction, by kind

Under the values assigned in `rows-all.tsv`:

| kind                         | cells   | defects today | defects post-cure, today's values | defects post-cure, assigned values | NOT OBSERVABLE |
| ---------------------------- | ------- | ------------- | --------------------------------- | ---------------------------------- | -------------- |
| `6-gate`                     | 141     | 20            | 10                                | **0**                              | 2              |
| `2-same-name-top-vs-level`   | 29      | 11            | 11                                | **0**                              | 6              |
| `3-shorthand-vs-level-block` | 18      | 18            | 15                                | **0**                              | 3              |
| `4-cross-level`              | 99      | 32            | 16 + 14 new                       | **0**                              | 3              |
| `1-threshold-group-declared` | 90      | 0             | 0                                 | **0**                              | 0              |
| `6-precedence-fill-in`       | 1       | 0             | 0                                 | **0**                              | 1              |
| everything else              | 87      | 0             | 0                                 | **0**                              | 0              |
| **total**                    | **465** | **81**        | **52**                            | **0**                              | **15**         |

(The per-kind post-cure-under-today's-values column is the sum of the two
`affected|today=…|underTodayValue=…` tallies restricted to that kind; the totals
are the machine's, in `axis-b-all.summary`.)

## 12. Conditions under which this prediction does not hold

1. **Magnitude identity (the big one, §13).** If `Stand::pairSide()` starts
   writing distinguishable magnitudes, 15 NOT OBSERVABLE cells become judgeable
   and the numbers above move — in the assigned column to 0 defects still, but in
   the today's-values column upward.
2. **`scope` (3 cells).** Undecided by the plan; §9.
3. **B1's exact shape.** The post-cure top-key-alone document is modelled by
   restoring the class level's `enabled` (B1). If the round instead keeps
   disabling the class level, every complexity band row moves.
4. **Q5 against `npath | class` (claude-04).** Modelled as "top `enabled` fills
   every level". If the round excepts the one level whose own default is off, the
   two `npath` gate cells move.
5. **The four `refuse` cells.** Modelled as unchanged on C4's word. The unfold
   runs BEFORE recognition, and nothing in K1-K4 forbids DESTROYING a refusal
   (claude-06); if it destroys this one, four green cells turn red.
6. **`pairCandidates()` writing the block whole.** The region derivation's blind
   spot (§2) is unreachable only while that holds.

---

# Догрузка X24-3

## 13. `one-wins:callable:` is NOT OBSERVABLE — and the reason is systematic

**The coordinator's suspicion is confirmed; the mechanism is one step over from
the one stated.** The `one-wins` branch does not require `both` to differ from the
winner written alone — it requires the two SIDES to differ (`$onlyA->text ===
$onlyB->text` → NOT OBSERVABLE). Measured on the product's own frozen
observations:

```
complexity.ccn | callable: × threshold
  omitted  callable{enabled:true, warning:10,   error:20  }  class{enabled:true,  …}
  onlyA    callable{enabled:true, warning:7331, error:7331}  class{enabled:true,  …}   <- the block
  onlyB    callable{enabled:true, warning:7331, error:7331}  class{enabled:false, …}   <- the shorthand
```

Post-cure B1 removes the one leaf that differs (`class.enabled`), and the two
sides become **identical**. Not by coincidence: `Stand::effectWritesFor()` returns
one canonical literal per declared shape, so a block's band and a shorthand's
unfolded band are equal BY CONSTRUCTION for every numeric key — and the block's
`enabled: true` write equals the level default, so it adds nothing. `pairSide()`
only reaches its alternates when the canonical leaves the object unchanged, which
here it does not; there is no escape.

My round-1 attribution — "a property of the magnitude choice, not an error in the
assignment" — was **half right and must be corrected**: it is the magnitude
choice, but it is systematic rather than a coincidence, and it is therefore not a
risk to be watched but a condition that holds today with certainty.

```
                      compose        one-wins:<blk>   deeper-wins:<blk>
3 × complexity callable:   OK        NOT OBSERVABLE   NOT OBSERVABLE
3 × complexity class:      OK        MISCOMPOSED      NOT OBSERVABLE
12 × coupling              OK        MISCOMPOSED      COEXISTENCE_OK
```
(`php "$D/real-magnitudes.php" "$TMP/tree" <repo>`, output in `real-magnitudes.out`)

**Option (a) — give the three rows `deeper-wins:callable:` — was measured and does
not help:** `deeper-wins` also returns NOT OBSERVABLE, by its own gate "the
shallower key writes nothing outside the region", which is exactly true here
(the shorthand's reach IS the block's level). The branch handles the empty outside
region correctly; it declines rather than greens, which is the intended behaviour.

**Decision: the three rows keep `one-wins:callable:`.** Of the three candidates it
is the only one that states the truth (the shorthand lands nowhere), and its
verdict — NOT OBSERVABLE, a non-defect — says exactly what is true of the stand:
the promise is right and the stand cannot see it. `compose` would go green for a
reason unrelated to the contract, which is the tautology `floor.tsv`'s own header
warns against. `deeper-wins:callable:` is equally unobservable AND claims a
sibling survival that does not exist. **Two values would not be enough either way:
`compose` for three, `deeper-wins` for twelve leaves the three `callable:` rows
carrying a promise that is false under C1.**

The real fix belongs in the stand: `pairSide()` must choose the second side's
magnitude so it differs from the first's whenever the two keys can land on one
pointer. That is a `Stand` change with its own cost, and it is the precondition
for any axis-B number about this round meaning anything.

## 14. The other half of the range: the branch on TODAY's uncured product

Judged against today's four real observations (not the contract's outcome), the
assigned value must redden, because today's product violates the contract:

| assigned           | MISCOMPOSED on today's product | NOT OBSERVABLE | **green on today's product** |
| ------------------ | ------------------------------ | -------------- | ---------------------------- |
| `compose` (27)     | 25                             | 0              | 2                            |
| `deeper-wins` (80) | 46                             | 1              | **33**                       |
| `one-wins` (17)    | 12                             | 5              | 0                            |

Of the 18 subject rows, **16 redden on today's product**, one is NOT OBSERVABLE
(`npath | callable:`) and one is green (`cbo | class: × threshold`).

**33 green cells on a broken product is a finding, not an accident, and it has one
cause — the same as §13.** Where both keys write `7331`, today's discard and
tomorrow's composition produce the SAME object: the shorthand overwrote the level
key with the level key's own value. Worked example, `cbo | class: × threshold`:

```
onlyA (block)      class{w:7331,e:7331}  namespace{w:14,   e:20  }
onlyB (shorthand)  class{w:7331,e:7331}  namespace{w:7331, e:7331}
both TODAY         class{w:7331,e:7331}  namespace{w:7331, e:7331}   <- the block was discarded
both POST-CURE     class{w:7331,e:7331}  namespace{w:7331, e:7331}   <- identical
```

So the branch is not blind: the object is. No `coexistence` value, present or
future, can redden that cell, because the defect leaves no trace in the object the
stand compares. The 33 cells are the exact measure of how much of this round's
subject the stand cannot see.

**How much of the green carries evidence.** 113 cells are green post-cure under
the assigned values; **39 of them are green on the uncured product too**, so their
green says nothing about the cure. **74 carry proof.** That ratio, not the 0, is
the number to quote about this round.

**The in-region test is EQUALITY with the deeper side alone, and it had to be.**
The first cut of the branch required only that the deeper side's CHANGED leaves
survive. That is blind precisely on the ten `6-gate` block rows: a block writes
`enabled: true`, which equals the level default, so the leaf this round is about
is not among the changed ones, and a product that filled that level with the
top-level `enabled: false` would have passed. The strict form (K4: the level's
own default is the only fallback inside the region) costs five lines, moves
nothing on the contract side — 0 defects, the same 15 NOT OBSERVABLE — and
reddens **eight more cells of the uncured product** (38 → 46). Measured both ways;
the weak form's numbers are not kept, because they were wrong.

## 15. What this refutes — including round 1 of my own package

| claim                                                                                                               | verdict                                                                                                                                                                                                                                                                                    |
| ------------------------------------------------------------------------------------------------------------------- | ------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------ |
| claude-01: "`compose` gives MISCOMPOSED for 15 of the 18 rows after the cure"                                       | **refuted on the live stand.** True only at hand-picked distinct magnitudes, which the stand does not write. At the real magnitudes `compose` gives COEXISTENCE_OK for all 18 (`real-magnitudes.out`). The mechanism claude-01 names is real; the consequence it predicts is not reachable |
| claude-01: "the prediction «axis B → 0» is unreachable by construction"                                             | **refuted.** It is reachable — tautologically, which is worse than being wrong                                                                                                                                                                                                             |
| my §3 (round 1): the 18 rows get COEXISTENCE_OK at the assigned values                                              | **holds only at distinguishable magnitudes.** At the stand's own magnitudes, 3 of the 18 are NOT OBSERVABLE. `probe.php` and `real-magnitudes.php` differ in exactly this and nothing else                                                                                                 |
| my §"One condition this measurement could NOT take"                                                                 | **superseded.** The condition was measurable after all, out of the frozen observations, and it is not a coincidence but a construction                                                                                                                                                     |
| `03-acceptance.md`: "twenty-five rows move"                                                                         | **refuted.** 124 cells move; 128 are inside the cure's radius                                                                                                                                                                                                                              |
| `03-acceptance.md`: "axis B's 81 defects … the expectation is 0 on the grown population", counting three mechanisms | **refuted as an account.** Under today's ledger values the cure leaves **52**, and 14 of those are cells the cure itself reddens. 0 is reachable only with the values in `rows-all.tsv`                                                                                                    |
| `03-acceptance.md`: "Any residual is this round's finding"                                                          | **holds, and names 52 of them in advance**                                                                                                                                                                                                                                                 |
| `03-acceptance.md`: the floor's single standing row                                                                 | **unchanged** — it is one of the three `compose` rows                                                                                                                                                                                                                                      |

## 16. Hand-off state, round 2

```
$ cd <repo> && git status --short
```
(empty)

Artefacts added in rounds 2-3: `rows-all.tsv` (all 465 axis-B rows), `axis-b-all.php` + `axis-b-all.summary`, `real-magnitudes.php` + `.out`.

## 17. The tree moved under the measurement — and it does not move the result

At hand-off `git status --short` is **not** empty. It was empty when this package
started and at every check until the last: a CONCURRENT package of the same
session wrote revision 2 of the contract while this one was running.

```
 M docs/internal/generated/modular-architecture/documentation-ownership.tsv
 M docs/internal/plans/shorthand-scope/01-contract.md
 M docs/internal/plans/shorthand-scope/02-cure.md
 M scripts/generate-modular-architecture-production-inventory.php
?? docs/internal/plans/shorthand-scope/measurement/population-gap.md
?? docs/internal/plans/shorthand-scope/measurement/population-gap.tsv
```

**None of it is mine, and that is checkable:** every write this package made went
to its scratchpad or to the `mktemp -d` copy; the only repository commands it ran
are `cat`, `awk`, `grep`, `diff`, `git status`, `git ls-files` and `git archive`,
and `rsync` copying OUT of the tree. The `01-contract.md` and `02-cure.md` diffs
carry a "Revision 2, after review" note naming four HIGH findings, which is the
review-fix package, not this one.

**Does revision 2 move anything measured here? Read, compared, no.**

| revision 2 says                                                                                                                                                                   | effect here                                                                                                                                                                                                |
| --------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- | ---------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| the reach table is unchanged for the band and for `enabled` (`callable` for complexity, both levels for coupling, both levels for `enabled`)                                      | every assignment in `rows-all.tsv` stands                                                                                                                                                                  |
| "a top-level band that is itself only half written pushes down only the half that was written"                                                                                    | this is reading (i) of §"the ambiguity 01-contract.md leaves open" — now settled, in the direction already measured, and the half-band robustness run shows the other reading gave the same verdict anyway |
| the fill unit is stated as the band for the band and the KEY for `enabled`                                                                                                        | matches the model used here (`groupOf()` in `axis-b-all.php`)                                                                                                                                              |
| `scope` is **not** pushed down at the seam; it keeps composing by key in `CboOptions::fromArray()`, where the top-level value reaches `class` only when the block did not name it | resolves condition 2 of §12 in the direction the assignment already took: `class.scope × scope` → `one-wins:class.scope`, `enabled × scope` → `compose`. Three cells, unchanged                            |
| a top-level `enabled` is named as a third breaking change                                                                                                                         | agrees with §9's reading of the twenty `6-gate` cells; claude-04 is folded in                                                                                                                              |

The one thing to re-check when revision 2 settles: **`02-cure.md` changed too, and
this package's blast-radius feature (§8) is derived from P3/P4's file set.** The
feature was re-read against the new text for the registry and the five options
classes and is unchanged, but a later revision that widens P3 beyond
`RuleThresholdKeyGroupRegistry` + `RuleOptionThresholdShorthand` would widen the
128 and invalidate the 337.

**Re-read of revision 2's P2, which has already absorbed round 1 of this
package** (it names `deeper-wins:<key>`, its constant, its branch and its
vocabulary test, and puts `scripts/promise-effect/` in the file set). Three gaps
remain against what rounds 2-3 measured:

1. **`scripts/promise-effect/Stand.php` is not in P2's file set.** The value
   carries a KEY and the classifier reads a SIDE; the translation lives at
   `Stand.php:316-318` and `:599-601`, and both must learn the new prefix. A P2
   that edits only `Ledger.php` and `Classifier.php` ships a value the live path
   silently reads as "the second key won".
2. **P2's DoD does not name the magnitude precondition.** "The grid is re-taken
   and its numbers recorded" will record 0 defects whether or not the stand can
   see the subject (§13, §14). The DoD needs the 41-cell number beside it, or
   the re-take proves nothing.
3. **Neither P2 nor `03-acceptance.md` names the 14 regressions** (§10). They are
   `4-cross-level` cells, they are green today, and nothing in the plan looks at
   them.

**Proactive, outside this package's subject.** Nineteen `(rule, key_a, key_b)`
triples are carried TWICE in the ledger, once under `2-same-name-top-vs-level`
and once under `4-cross-level` — 38 cells probing one physical document each, so
**10 of the 81 defects are second copies of another defect**. `pair-kind-scope.tsv`
checks that every kind is accounted for; nothing checks that one document is
counted once. Any denominator built on "81" inherits the duplication.
