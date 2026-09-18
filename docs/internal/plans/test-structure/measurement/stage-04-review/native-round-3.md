# Stage 04 — plan review, round 3 (native, narrow)

Scope as briefed: **one question** — did any of the two cured defect classes come back a
third time, one step smaller? Not a fresh review. Material read at `main` @ `e15c7f42`
with the working tree's modified and untracked plan files. Read-only: nothing in the tree
was edited, no git state was touched. Every number below comes from a command; the
commands are inline, not in an appendix, so each is checkable where it is claimed.

**Verdict on the question: yes, once, and it is the largest of the three capped lists.**

The numbers are clean this round — all of them, re-measured independently. The
directory-retirement rule is clean — the set the plan computes is the set the tree
produces. What came back is the *rule-applied-to-a-subset* shape, in the one place round 2
did not look: **stage 04 hands stage 05 one of its three capped lists, and it is not the
one stage 05 will collide with.** List B overlaps stage 05's ledger in 5 files; list A
overlaps it in **30 files / 38 rows**, and the handover section does not mention list A.

Six findings: 1 HIGH, 2 MEDIUM, 3 LOW.

| #   | Sev  | Title                                                                                                                                       | Anchor                                                                       | Status                                                   |
| --- | ---- | ------------------------------------------------------------------------------------------------------------------------------------------- | ---------------------------------------------------------------------------- | -------------------------------------------------------- |
| R1  | HIGH | the stage-05 handover names 1 of the 3 capped lists, and not the overlapping one                                                            | `05-content-defects.md:118-135`                                              | fact, measured                                           |
| R2  | MED  | 10 non-moving files carry live `{@see}` FQCNs of moving classes; the enumeration says 5 and no package's derived file set holds any of them | `measurement/stage-04/addresses.md:87`, `04-packages.md:114-115,147,181-184` | fact, measured                                           |
| R3  | MED  | the one owner the plan singles out as special is exercised by nothing in P0                                                                 | `04-packages.md:54,91-100`, `04-subject-layout.md:143`                       | fact for the measurement, hypothesis for the consequence |
| R4  | LOW  | P0 says "Four planted breakages" over a table of 5; P5 says "six" over a table of 8                                                         | `04-packages.md:91`, `:274`                                                  | fact                                                     |
| R5  | LOW  | a hand-maintained fixture names a moving test path; no package owns it, no DoD grep reaches it                                              | `governance/Channel/Fixtures/declared.txt:58`                                | fact, measured                                           |
| R6  | LOW  | P6's two renames have no stated target name, and the map diff cannot verify them                                                            | `04-packages.md:294-298`                                                     | fact                                                     |

---

## Verdicts on the five briefed points

### 1. Numbers — **clean**

Every number named in the brief reproduces. The script in `invariant-shape.md` was
extracted verbatim and run:

```bash
python3 - <<'PY' > /tmp/inv.py
import re,sys
t=open('docs/internal/plans/test-structure/measurement/stage-04/invariant-shape.md').read()
sys.stdout.write(re.search(r'```python\n(.*?)```', t, re.S).group(1))
PY
python3 /tmp/inv.py; echo "EXIT=$?"
```

```
{'exact': 377, 'prefix': 132, 'A': 84, 'B': 19, 'C': 4} sum 616 of 616
EXIT=0
```

`377 + 132 + 84 + 19 + 4 = 616` — the sum is the population, which is the check the two
earlier drafts lacked, and the script enforces it with its exit code rather than asserting
it in prose. `377 + 132 = 509`. The three ceilings are the three list sizes.

The script does **not** check parts 1 and 2 (it takes the first level segment and never
validates the owner against the manifest), so the table's two "616 of 616" rows are not
covered by it. I measured them independently and they hold:

```
population 616 · part-1 violations 0 · files with no level segment 0 · files with two level segments 0
```

The population is closed over the manifest's 37 owners including the three that look like
counterexamples and are not: `tests/Core/Path/Unit` and `tests/Core/Symbol/Unit` are owners
`Core.Path` and `Core.Symbol`, and `tests/Reporting/GraphProjection/Unit` is
`Reporting.GraphProjection` — all three are in the manifest.

Sub-counts, each re-derived: list A splits **5 `#[CoversNothing]` / 79 undeclared**; list C
is exactly the four `tests/Analysis/Run/Unit/{Collection,Configuration,Pipeline}` files that
elide `Contract`; **5 of the 19** list-B files are rows in `defect-ledger.tsv`.

Partition: `Infrastructure.* → 52`, `Reporting → 51`, `Core.Neutral|Analysis.* → 11`,
total **114**, over the map's 114 rows.

Directory deltas (measurement under point 2): **P1 0/0, P2 4/2, P3 3/1, P4 0/0** — all four
correct.

The two figures round 2 corrected are now right and I re-measured both: **30** non-moving
test files keep a legacy-bucket namespace, and the allow-list's **55** is its row count and
its `ceiling` key (`namespace-path-allow-list.php:20`). `00-overview.md:30` now says **529**,
which is `616 − 87`.

`504 of 529` reproduces exactly, and so does the "25 it does not reproduce" — the 25
non-conforming non-bucket test files, which with `FromArrayKeyReader.php` make the map's 26
residue rows.

### 2. "A package retires what it emptied" — **clean**

Replayed package by package over `git ls-files tests` against the 47 `tests`-rooted
`<directory>` entries. Columns are tracked files remaining beneath each entry now / after
P1 / P2 / P3 / P6:

```
tests/Unit                                       [76, 48,  7,  0, 0]
tests/Integration                                [ 6,  2,  2,  0, 0]
tests/Functional                                 [ 6,  2,  0,  0, 0]
tests/Reporting/FindingProjection/Unit           [ 6,  6,  0,  0, 0]
tests/Reporting/Formatter/Suppressed/Unit        [ 1,  1,  0,  0, 0]
tests/Reporting/Formatter/Sarif/Integration      [ 1,  1,  0,  0, 0]
tests/Analysis/Finding/RuleConfiguration/Unit    [ 1,  1,  1,  0, 0]
```

Exactly seven entries ever reach zero, and no other declared entry does. **P2 empties four**
— the three Reporting roots plus `tests/Functional` — and **P3 empties three** —
`tests/Analysis/Finding/RuleConfiguration/Unit` plus `tests/Unit` and `tests/Integration`.
**P1 empties none; P4 empties none.** That is what `04-packages.md:140-146` and `:193-198`
now say, entry for entry. No entry is left unassigned, and no entry is assigned to a package
that does not empty it.

The adds check out too, and they are the half a "removes" sweep would miss. Every target of
every moved test file is covered by some surviving declared entry except:

```
P1:  (none)
P2:  tests/Reporting/Functional/...  ×2      tests/Reporting/Integration/...  ×1
P3:  tests/Core/Unit/...             ×6
```

which is `add 2` for P2 and `add 1` for P3, as written. The deeper targets
(`tests/Reporting/Functional/Formatter/`, `tests/Reporting/Integration/Formatter/Sarif/`,
`tests/Core/Unit/Util/`) are covered by those three roots, and the three non-test targets
(`tests/Reporting/Support/`, `tests/Analysis/Finding/Support/`, and P6's
`tests/Analysis/Policy/Baseline/Support/`) need no declaration.

### 3. Consumers of moving files — **not clean** → R2, R5

The rule now reads "a consumer belongs to the package that moves what it consumes, always".
It is satisfied for the channel that breaks the build (`use` imports — the N4 case), and
not for the channel that does not. See R2 and R5. All three P6 cases were swept, not only
`FindingFactory`: the two renames have no consumers at all (R6 is a different defect in
them), and `FindingFactory`'s eight consumers are in P6 as stated.

### 4. Planted breakages — **not clean** → R3, R4

The two plants added this round do plant what they name: the list-C plant ("remainder drops
an interior segment") is refused by a prefix test — `Pipeline` is not a segment-wise prefix
of `Contract/Pipeline`, which is why the four real ones are list C rather than list B — and
the stale-row plant is the direction two drafts asserted in prose and neither planted. Both
correct. What is missing is a *positive* classification plant (R3), and the prose counts of
both plant tables are stale (R4).

### 5. Document against itself — **clean on every named grep**

`три названных случая` / `three named cases`, `параллельно` / `in parallel` (as a live
claim), `P4 retires`, `55 файлов` / `55 files`, `612` and `ceiling 8` all return either
nothing or only the sentence that records the retraction:

```bash
grep -rn '612\|ceiling 8\|in parallel\|P4 retires\|55 files' \
  docs/internal/plans/test-structure/0*.md docs/internal/plans/test-structure/measurement/stage-04/*.md
```

`612` survives only as "*Those sum to 612, not 616*" and "*published four buckets that summed
to 612 of 616*"; `in parallel` only as "*An earlier draft ran P5 and P6 in parallel*". `ceiling
8` is gone; every ceiling in the tree is 84, 19 or 4. R4 is a self-contradiction of a
different kind, found while checking this point.

---

## R1 — HIGH — the stage-05 handover names one of the three capped lists, and not the one stage 05 collides with

**Anchor.** `05-content-defects.md:118-135` (§"Handed over by stage 04: a capped exception
list this stage must not re-derive blind"), against `04-subject-layout.md:161-163` (three
lists) and `:177-182`.

**Status: fact, measured.**

The handover section exists for one reason, stated at `:127-129`:

> Re-deriving this stage's moving classes without reading list B will hit a ceiling it does
> not know exists, and the failure will read as an unrelated governance refusal.

Its evidence is the overlap with stage 05's own population (`:124`): "**Five of those 19 are
already rows in `measurement/defect-ledger.tsv`**". Measured against the ledger's `file`
column:

```
ledger: 219 files, 276 rows
list A ∩ ledger:  30 files,  38 rows
list B ∩ ledger:   5 files,   5 rows
list C ∩ ledger:   0 files,   0 rows
```

So the argument the section makes for list B holds **six times more strongly for list A**,
and list A is not mentioned anywhere in `05-content-defects.md`. Nor is list C — correctly,
since its overlap is zero, which is the point: the three lists were not examined, one was.

**The collision is not hypothetical, and it is not the mechanism the handover describes.**
Broken down by the ledger's own `class` column, the 38 rows are:

```
other 10 · dupe 9 · misplaced 7 · category-wrong 6 · stale-doc 4 · name-lies 1 · tautology 1
severity: 2 high, 16 medium, 20 low
```

The dominant repair is a **path change**, not an attribute change: `misplaced` (7) is "file
sits outside its owning subject" and `category-wrong` (6) is "labelled unit but does I/O", and
both are repaired by moving the file. That matters, because a move acts on list B and list A
in opposite directions. Moving a list-B file to the owner it covers *removes* it from B —
which is why `:129-131` can say "every file this stage moves out of list B lowers the ceiling"
and needs no decision. Moving a list-A file does **not** remove it from A: the file still
declares no coverage, so its row has to be rewritten to the new path at an unchanged ceiling.
Until it is, stage 05 holds a row naming a path that no longer exists — stale, refused by the
plant this round added — and a file at a new path on no list — missing, refused by the plant
before it. Two refusals out of one move, on a list stage 05 has never been told exists.

`dupe` (9) is the second mechanism: deleting a duplicate file orphans its list-A row the same
way. Attribute addition — `tautology`, `name-lies`, `stale-doc`, 6 rows — is the third and the
smallest, and is the only one I had assumed before measuring.

So the section's own sentence, "will hit a ceiling it does not know exists, and the failure
will read as an unrelated governance refusal", describes list A more exactly than it describes
list B, and list A is the list it does not name.

**This is the F4 → N3 shape a third time and one step smaller.** Round 1: a rule stated and
applied nowhere. Round 2: the same rule applied to 4 of 7 entries. Round 3: a handover
written for 1 of 3 lists. Each round the population is smaller and the omission is the same
omission — the rule is applied to the members that were in view when it was written, not to
the set it quantifies over.

**Fix direction.** State the handover over the three lists, not over one: give each its
ceiling, its measured ledger overlap (30/38, 5/5, 0/0) and the direction it moves under
stage 05's edits — a list-A row is *rewritten* when its file moves or deleted when its file
goes, at an unchanged ceiling, and retired only when the file gains a coverage claim; a list-B
row retires when its file moves to the owner it covers, lowering the ceiling; list C is
untouched by stage 05. The
derivation is one command and belongs in the section, so that the next rewrite cannot
re-narrow it to whichever list was on the desk.

---

## R2 — MEDIUM — ten non-moving files carry live `{@see}` FQCNs of moving classes; the enumeration says five, and no package's derived file set holds any of them

**Anchor.** `measurement/stage-04/addresses.md:87` (the `{@see}` row), `04-packages.md:114-115`
(P1 Files), `:147` (P2 Files), `:181-184` (P3 Files).

**Status: fact, measured.**

Derived independently — every tracked `*.php` file that is not itself a map row, scanned for
the declared FQCN of every map row:

| consumer (does not move)                                                                     | package that moves the class | namespace of the moved class |
| -------------------------------------------------------------------------------------------- | ---------------------------- | ---------------------------- |
| `governance/Channel/ChannelDeclarationFixtureDriftTest.php`                                  | P1                           | residue                      |
| `governance/Channel/ChannelPresentationCoverageTest.php`                                     | P2                           | residue                      |
| `governance/Channel/SarifRuleDescriptorCoverageTest.php`                                     | P2                           | residue                      |
| `governance/RuleOptionKeys/CliAliasKeyWalkAgreementTest.php`                                 | P3                           | residue                      |
| `tests/Infrastructure/Console/Functional/Command/CheckCommandConfigurationErrorGateTest.php` | P2                           | residue                      |
| `tests/Infrastructure/Console/Functional/Command/CheckCommandProjectScopedGateTest.php`      | P2                           | residue                      |
| `governance/ConfigurationVocabulary/YamlKeyReachabilityTest.php`                             | P3                           | legacy bucket                |
| `tests/Analysis/Run/Unit/Configuration/ProjectScopeCoverageTest.php`                         | P1                           | legacy bucket                |
| `tests/Infrastructure/Console/Functional/ApplicationRefusalTest.php`                         | P1                           | legacy bucket                |
| `tests/Infrastructure/Console/Functional/Command/CheckCommandInputValidationTest.php`        | P1                           | legacy bucket                |

**Ten, not five — and the reason is the recurring one.** `addresses.md:87` states its sweep as
`Qualimetrix\\+Tests\\+(Unit|Integration|Functional)`, which is the **88 legacy-bucket rows**.
The stage moves **114**, and the other 26 are the residue, whose namespaces are
`Tests\Infrastructure\…`, `Tests\Reporting\FindingProjection\…`,
`Tests\Analysis\Finding\RuleConfiguration\…` — outside the regex by construction. The six
residue rows above are exactly what that population excludes. The same narrowing is what round
2 recorded under "Checked and found clean", so the check inherited the population rather than
re-deriving it.

The fifth file `addresses.md` lists, `tests/Infrastructure/Console/Integration/RuleExclusionStatsWiringTest.php`,
is not one of the ten: its reference is
`Qualimetrix\Tests\Integration\Infrastructure\Console\RulesCommandWiringTest`, a class stage 02
or 03 already moved (it is now `Qualimetrix\Tests\Infrastructure\Integration\RulesCommandWiringTest`,
map row 10). So the declared sweep reaches 4 of the 10 live references and one stale one.

**Why this is a plan defect and not just a stale artifact.** Each move package's Files section
is "derived, not narrated" (`04-packages.md:24-27`) and the derivations are:

- P1 — "the 52 rows plus every generator constant naming one of those rows";
- P2 — "the 51 rows plus the constants naming them, which is `P6_D_REPORTING_TEST_PATHS` ×1";
- P3 — "the 11 rows plus the constants naming them, which is none, plus the two files that
  `use` one of the 11".

None of those derivations yields any of the ten, yet each package's own DoD — "`git grep -l`
for each moved file's old fully-qualified class name across the whole repository returns
nothing" — cannot be met without editing them: 4 for P1, 4 for P2, 2 for P3. This is N4 one
step smaller. N4 was the `use`-import channel and was cured for `FromArrayKeyReader` because a
missing import reddens the build; the docblock channel does not redden anything, so it was
left to a grep with no owning file set. The predictable outcome is that the DoD grep comes
back non-empty at the end of each move package and ten unplanned edits are made under a
"returns nothing" clause.

I record the generator half as **checked and correct**: 5 path literals in the generator name
moving rows — `P3_TEST_PATHS` ×2 (lines 189-190) and `P6_D_GIT_TEST_PATHS` ×2 (277-278) for
P1, `P6_D_REPORTING_TEST_PATHS` ×1 (262) for P2, none for P3 — plus
`validateP4Topology()`'s pinned `tests/Infrastructure/Console/Functional/LayerAssignmentCommandTest.php`
at line 1492, which the map retargets to `.../Functional/Command/Debug/`. Exactly what
`04-packages.md:114-120,147,181-182` claims.

**Fix direction.** Derive each move package's file set over *both* carriers, not one: the map
rows, and every tracked file outside the map whose text contains a declared FQCN of one of
that package's rows. Publish the ten in `addresses.md` with the population restated as the
114 rows rather than the legacy-bucket regex, and separate the one stale reference from the
live ones — a row that names a class an earlier stage already moved is a different defect and
belongs to whoever owns pre-existing drift.

---

## R3 — MEDIUM — the one owner the plan singles out as special is exercised by nothing in P0

**Anchor.** `04-packages.md:54` ("Core.Neutral is spelled `tests/Core` // the one owner whose
name is not a namespace"), `:69-79` (P0 DoD), `:91-100` (P0's plants);
`04-subject-layout.md:143`.

**Status: fact for the measurement; hypothesis for the consequence, stated as such.**

`Core.Neutral → tests/Core` is the single hand-written exception in the new path parse, called
out in both documents. Measured, it is the one branch P0 cannot exercise:

- On disk today there is **no** `tests/Core/Unit/` path. The Core roots that exist are
  `tests/Core/Path/Unit` and `tests/Core/Symbol/Unit`, and those parse to `Core.Path` and
  `Core.Symbol` — ordinary owners whose names *are* namespaces. They are not instances of the
  special case.
- The six `Core.Neutral` paths (`tests/Core/Unit/VersionTest.php`, `.../Util/*`,
  `.../Observation/*`) are map targets, arriving only with **P3**.
- P0's DoD reaches them only through the clause "for every row in `LEGACY_UNMOVED`, the
  regenerated `target_path` equals that row's `target` in `relocation-map.csv`". For an
  allowance row `targetPath()` is a table lookup (`:57` — "a `LEGACY_UNMOVED` path returns the
  target that list records"), not the parse. So the clause passes whether or not the parse can
  read `tests/Core/Unit`.
- P0's five plants are all negative, and the one probe among them
  (`--classification-probe=tests/Reporting/Formatter/Unit/X.php`) asserts a *refusal*. Nothing
  asserts an acceptance, and `generator-probe.md` is the measurement that says silent
  misclassification is this generator's failure mode.

Round 2 closed codex-04 ("P0 proves no positive `targetPath()` contract") on the argument that
P0's row population "contains nested remainders, Infrastructure owners and `Core.Neutral`
(`tests/Core/Path/Unit/…`)". The first two hold. The third does not: `tests/Core/Path/Unit` is
`Core.Path`. The closure of a round-1 finding rests on a misread population — which is the
same class again, this time in a review verdict rather than in the plan.

**Consequence, as hypothesis.** If the parse gets `tests/Core/Unit` wrong, P3's
`composer architecture:check` would most likely go red loudly (the regenerated owner would be
`Core`, which is not among the 37, and the rule as written refuses an unknown owner by name).
I did not construct the case, so I do not claim it is silent. What I do claim as fact is that
the plan places this branch in P0, states that it "cannot follow the moves", and then gives P0
no evidence that touches it.

**Fix direction.** Add one positive plant to P0's table —
`--classification-probe=tests/Core/Unit/VersionTest.php` must answer `Core.Neutral`, and the
probe must be run on a path that does not yet exist, which the probe mode already permits
since its existing row probes a path that does not exist either. One row, and it is the only
row in that table that proves the parse accepts rather than refuses.

---

## R4 — LOW — both plant tables state a count that is not their row count

**Anchor.** `04-packages.md:91` and `:274`.

**Status: fact.**

```
04-packages.md:91   "Four planted breakages, each reverted after its refusal is recorded"   → table has 5 rows
04-packages.md:274  "Observed to refuse under each of six planted breakages"                → table has 8 rows
```

P0's prose is otherwise internally consistent — ":101 The last is not optional" points at row
5 and ":103 The fourth is the one both drafts asserted twice in prose" points at row 4 — so
only the word "Four" is stale. P5's "six" is stale by two, and the two extra rows are exactly
the ones this round added: the list-C plant and the stale-row plant. A count that was not
re-derived when its population grew is the stage's own named failure mode, and here it is in
the sentence introducing the table that grew.

**Fix direction.** Delete the numerals. "Planted breakages, each reverted after its refusal is
recorded" needs no count; the table is the count, and a table cannot disagree with itself.

---

## R5 — LOW — a hand-maintained fixture names a moving test path, and nothing in the stage reaches it

**Anchor.** `governance/Channel/Fixtures/declared.txt:58`;
`04-packages.md:207-215` (P4's narrowed path grep); `measurement/stage-04/addresses.md:72-73`.

**Status: fact, measured.**

Sweeping every tracked non-`docs/` file for the 114 current paths returns four carriers, three
of them already owned (`test-ownership.tsv` regenerates itself, the generator's 5 literals are
P1's and P2's, `ApplicationRefusalTest.php:19` is N6's). The fourth is not:

```
governance/Channel/Fixtures/declared.txt:58:  # tests/Infrastructure/Unit/ChannelUniverseTest.php's
```

A comment line inside a hand-maintained fixture that four `governance/Channel/` controls and
two `scripts/` tools read. P4's path grep was narrowed this round to
`tests/(Unit|Integration|Functional)/`, and this is a residue path, so the narrowed grep cannot
see it; the move packages' greps are for FQCNs, not paths; and `addresses.md:72-73` lists
"hand-maintained data files were not swept" as a known gap — this is that gap with a member in
it. Nothing breaks: the line is a comment. It goes stale silently, which is the whole class of
thing this campaign is about.

A basename sweep over every tracked non-PHP file returns this one file and nothing else, so the
carrier is a single row, not a population.

**Fix direction.** Give it to P1 — the package that moves `ChannelUniverseTest` — as one more
line in its Files section, and widen the swept population in `addresses.md` from the legacy
bucket paths to the 114 map paths, which is the same widening R2 asks for on the FQCN side.

---

## R6 — LOW — P6's two renames have no stated target, and the stage's completeness check cannot see them

**Anchor.** `04-packages.md:294-298`, `04-subject-layout.md:107-111`, `:226-231` (the stage's
both-directions map diff).

**Status: fact.**

P6 renames two files "so that each name says which subject it guards". Neither new name is
written anywhere:

```bash
grep -rn 'UnmatchedExclude' docs/internal/plans/test-structure/0*.md \
  docs/internal/plans/test-structure/measurement/stage-04/*.md
```

returns three lines, all of which say *that* the rename happens and none of which says to
what. The two files are outside `relocation-map.csv`, so the stage's completeness check — "the
tree diffed against the map's `target` column ... in both directions" — cannot verify them
either; they are admitted as an exception to it. By this file's own rule at `:24-27` ("A
package's file set is derived, not narrated"), a rename with no stated target is a narrated
set, and P6 is the package that already paid for one: "the first draft narrated this set and
omitted both generator guards below."

Nothing else references them — I swept every tracked PHP file for both current FQCNs and the
only hits are the two class declarations themselves. So the rename is safe; it is merely
unverifiable as written.

**Fix direction.** Write the two target names into P6, and state the check that proves them:
the two old class names return nothing to `git grep`, and two files under the new names exist.
Two lines, and the stage stops carrying an exception it cannot check.

---


## Checked and found clean — recorded so the coverage is legible

- **The `invariant-shape.md` script.** Extracted verbatim and run; reproduces the published
  table and exits 0 on the sum. Its `sys.exit` does bite: the assertion is on
  `sum(b.values()) == len(files)`, which is the check both earlier drafts lacked.
- **Parts 1 and 2 of the invariant**, which the script does not cover, measured independently:
  616/616 both.
- **The three ceilings and their sub-counts**: A 84 = 5 `#[CoversNothing]` + 79 undeclared;
  B 19 with 5 in the ledger; C exactly the four `Contract`-eliding `Analysis/Run` files.
- **The package partition** 52 / 51 / 11 = 114, and the map's `group` split.
- **Directory retirement and creation**, both directions, per package.
- **The generator's path and FQCN literals** naming moving rows: 5 paths + 1 pinned target +
  the `FQCN::method` literals, distributed exactly as P1 and P2 claim, none for P3.
- **`30` legacy-namespace files and the allow-list's `55` rows / `ceiling => 55`** — N5's fix
  is correct in both halves.
- **`529` in `00-overview.md:30`** — N10's fix; `616 − 87 = 529`.
- **`504 of 529`** and the 25 residue non-conformers.
- **The two plants added this round** refuse what they name, checked against the prefix
  semantics the control uses.
- **The `use`-import consumer rule (N4)** — `FromArrayKeyReader`'s two non-moving consumers are
  in P3, the package that moves it, and the eight `FindingFactory` consumers are in P6.
- **P5/P6 sequencing (N9)** — both documents now state strict sequence and name the shared
  file.
- **The `disposition` clause (N7)** — P0's DoD now states the expected value in both
  directions.
- **P6-C's digest transition**, recomputed with the generator's own rule (every file under
  `tests/Analysis/Policy/Baseline`, sorted `SORT_STRING`, joined by newlines with a trailing
  newline): the tree today hashes to `6ec107b9...`, which is `P6_C_BASELINE_PATHS_SHA256` at
  generator line 17, and with `Support/FindingFactory.php` added it hashes to `818d94bd...`.
  Both values in P6's section are exact.
- **The two P6 renames have no non-moving consumers** — swept for both current FQCNs across
  every tracked PHP file; the only hits are their own class declarations.

**Not measured this round, and named so it is not read as checked:** the per-suite case counts
in `measurement/stage-04/prediction.md` (Unit 6705, Integration 383, Functional 152,
Infrastructure 1029, Tooling 179, Governance 748, total 9196). They need a full `--list-tests`
run per suite, they were outside the briefed five points, and nothing in this report depends
on them.
