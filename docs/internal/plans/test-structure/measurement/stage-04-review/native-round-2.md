# Stage 04 — plan review, round 2 (native, narrow)

Scope as briefed: **(1)** the status of each of the 21 round-1 findings, **(2)** whether
the fixes introduced a defect of the class they cured. Not a fresh review of the plan.

Material read at `main` @ `e15c7f42` with the working tree's four modified and two
untracked plan files plus `measurement/stage-04/*`. Read-only: nothing in the tree was
edited; every number below comes from a command, and the two scripts are in Appendix A.

**Verdict.** 13 of 21 findings are closed, 8 closed partially, none unaddressed and none
rejected. The fixes did introduce defects of the class they cured — **ten new findings**,
three of them HIGH, and the three HIGH ones are each the cured defect reappearing one
step smaller:

- the invariant was weakened to a prefix test to make it statable, and the tree still
  holds **four** files the prefix test refuses that no package moves and no exception
  list holds (N1) — same class as F2/codex-02, a control red on day one;
- the measurement the weakening stands on reports the exception list as **23** where the
  plan says **19**, because it absorbed those four refusals into it (N2) — same class as
  F1, a number that validates the rule only under a weakening of the rule;
- the "each package retires what it emptied" rule was written and then applied to **4 of
  the 7** entries, leaving `tests/Functional` on the floor from P2 and `tests/Unit` /
  `tests/Integration` from P3 (N3) — same class as F4, and P2's and P3's own new DoD line
  "a fresh clone of this commit runs" is what makes it unarguable.

---

## Question 1 — status of the 21 round-1 findings

| #        | Sev  | Title (short)                                    | Status                      | The edit, and whether it answers the finding                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                  |
| -------- | ---- | ------------------------------------------------ | --------------------------- | --------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| F1       | CRIT | self-check never exercised assertion 3           | **closed** → spawned N1, N2 | `04-subject-layout.md:34-43` now reads "**its owner and level parts** reproduce 504 of the 529 … That figure supports the owner and level parts and **nothing more**", and §"The invariant, and the shape measurement forced on it" restates part 3 as a prefix test backed by a new measurement. Answers the finding exactly. Its cure is what N1/N2 are about.                                                                                                                                                                              |
| F2       | HIGH | ceiling 8 wrong by ×10                           | **partial**                 | `04-subject-layout.md:156` and `04-packages.md:227` now carry ceiling **84**; I re-measured 84 over `tests/**/*Test.php` post-move — correct. The fix_direction's second half — "state which of the 84 are `#[CoversNothing]` (a deliberate claim) versus simply undeclared (debt)" — is nowhere in the plan.                                                                                                                                                                                                                                 |
| F3       | HIGH | "artifacts byte-identical" contradicts P0        | **partial**                 | `04-packages.md:69-79` replaces it with four enumerated diff clauses, and says in so many words that byte-identity "would be a DoD the package cannot meet". The `target_path` half is fully enumerated; the `disposition` half is licensed to change with no expected value stated → **N7**.                                                                                                                                                                                                                                                 |
| F4       | HIGH | P2/P3 commit a tree a fresh clone cannot run     | **partial**                 | `04-packages.md:16-21` adds the rule ("removes every `<directory>` its moves emptied … in its own commit"), P2 retires 3 and P3 retires 1. Four of the seven. The three bucket entries are still retired only at P4 → **N3**.                                                                                                                                                                                                                                                                                                                 |
| F5       | HIGH | P2/P3 never run the tests they move              | **closed**                  | `04-packages.md:152` and `:171` both carry `composer check:code` green, symmetrically with P1. P2's line even carries the reason ("a package that moves files without running them has checked nothing").                                                                                                                                                                                                                                                                                                                                     |
| F6       | MED  | stage DoD forbids exactly what P6 does           | **closed**                  | `04-subject-layout.md:213-217` admits the three files inside the clause itself; `04-packages.md:277-279` states the same from P6's side and says the two documents agree rather than contradict.                                                                                                                                                                                                                                                                                                                                              |
| F7       | MED  | P4's grep DoD cannot return what it says         | **partial**                 | Narrowed to the legacy **paths** (`04-packages.md:207-211`) and the namespace clause replaced by an allow-list statement (`04-subject-layout.md:230-235`). The narrowing is right; the narrowed grep was not measured → **N6**, and the replacement sentence carries the wrong population → **N5**.                                                                                                                                                                                                                                           |
| F8       | MED  | merged address list dropped a `{@see}` address   | **closed**                  | `addresses.md:87` adds the row with carrier, L/S verdict and command, plus §"Why they were missed". I re-derived it: exactly 5 `Qualimetrix\Tests\{Unit,Integration,Functional}\…` references to **moving** classes live in non-moving files, and the five files named are those five.                                                                                                                                                                                                                                                        |
| F9       | MED  | witness B's "verified with" line is false        | **closed**                  | `addresses.md:113-115` records it ("reports a file as absent which is present and is row 52 of the map") and separates the address verdict from the evidence cell.                                                                                                                                                                                                                                                                                                                                                                            |
| F10      | MED  | unstated fourth assertion, no planted breakage   | **closed**                  | Owner agreement is now explicit as **exception list B** (`04-subject-layout.md:157,165-172`) and planted breakage 4 of P5 is exactly the shape ("a file whose path owner differs from every `#[CoversClass]` owner, not on list B").                                                                                                                                                                                                                                                                                                          |
| F11      | LOW  | stale-namespace catch assigned to a path diff    | **closed**                  | `04-subject-layout.md:226-229` and `04-packages.md:299-301` both name `TestNamespacesFollowTheirPathTest` as the catcher and say why the path diff cannot be one. `composer check:code` in every move package reaches the Governance suite through the aggregate.                                                                                                                                                                                                                                                                             |
| F12      | LOW  | P5's control has no stated scope                 | **closed**                  | `04-subject-layout.md:129-132` states the population as `tests/**/*Test.php` and says why by omission would be wrong; `04-packages.md:222-224` repeats it at the control.                                                                                                                                                                                                                                                                                                                                                                     |
| F13      | LOW  | `Governance 748` stale the moment P5 lands       | **closed**                  | Stage DoD (`04-subject-layout.md:222-225`) scopes 748 to "After P4" and adds "**P5 moves the Governance figure** … `748 + N`"; `06-*.md:70-76` re-anchors to "whatever it is when this stage starts" and says why no literal is written.                                                                                                                                                                                                                                                                                                      |
| F14      | LOW  | `relocation-map.csv` is CRLF                     | **closed**                  | `grep -c $'\r' …/relocation-map.csv` → **0** (was 115).                                                                                                                                                                                                                                                                                                                                                                                                                                                                                       |
| codex-01 | HIGH | P6 omits the two generator guards                | **partial**                 | `04-packages.md:263-270` names both `P6_A_FINDING_TEST_PATHS` and `P6_C_BASELINE_PATHS_SHA256`, with the digest transition and the rule that the new value is a recorded decision. The fix_direction's closing clause — "after this P5 and P6 share one file and cannot be declared independently parallel" — is not applied; both are still declared parallel → **N9**.                                                                                                                                                                      |
| codex-02 | HIGH | exception ceiling 8 vs 84                        | **partial**                 | Same edit as F2, same residual gap.                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                           |
| codex-03 | MED  | multi-owner `#[CoversClass]` semantics undefined | **closed**                  | Part 3 now reads "for **at least one** `#[CoversClass]` whose owner equals the path owner" (`04-subject-layout.md:140-142`), and the residual case is list B ("covers **only** classes owned by someone else"). Both multi-owner directions are now decided.                                                                                                                                                                                                                                                                                  |
| codex-04 | MED  | P0 proves no positive `targetPath()` contract    | **closed**                  | Answered by a stronger mechanism than the requested probes: `04-packages.md:75-78` requires the regenerated `target_path` to equal `current_path` for **every** row outside the allowance. That population contains nested remainders (`tests/Analysis/Run/Unit/Pipeline/…`), Infrastructure owners and `Core.Neutral` (`tests/Core/Path/Unit/…`) — the three forms `generator-probe.md` showed breaking. The planted set staying negative-only no longer matters.                                                                            |
| codex-05 | MED  | `LEGACY_UNMOVED` monotonicity not planted        | **partial**                 | An immutable source is now named — "holds exactly the 114 rows of the map, compared key by key" (`04-packages.md:79`) — and planted breakage 2 covers a row added for a conforming on-disk file. Two gaps remain: the key-by-key comparison is stated as a **P0 DoD step, not a running control**, so it says nothing about P1–P3; and the refusal text of plant 2 ("the allowance may only shrink" for a file that *already conforms*) leaves codex-05's own scenario — a new **non-conforming** file plus a consistent new row — unplanted. |
| codex-06 | LOW  | "re-derive" 28/1 normalizes a regression         | **closed**                  | `04-packages.md:190-196` now reads "**Verify, do not re-derive**", gives the reason both counts cannot move (`P8_ORPHAN_DISPOSITIONS` 112 literals and `ORPHAN_CANDIDATE_PREFIXES` 10 intersect the move set in zero places) and makes any movement a signal to explain before editing.                                                                                                                                                                                                                                                       |
| codex-07 | LOW  | witness B population 91/23 vs 88/26              | **closed**                  | `addresses.md:110-112` records it under §"Known defects in the witness reports themselves", states the correct 88/26, and says the union of 114 is unaffected.                                                                                                                                                                                                                                                                                                                                                                                |

**Counts:** closed 13, partial 8, not closed 0, rejected-with-reason 0.

---

## Question 2 — new findings

Ten. Each carries the command that produced it.

### N1 — HIGH — four files fail part 3 as a prefix test; P5 is red on day one and the plan's own table sums to 612 of 616

**Anchor.** `04-subject-layout.md:152-157` (the Part-3 table), `04-packages.md:235`
("Green over `tests/**/*Test.php`").

**Status: fact, measured.**

The table adds up to 612:

```
377 + 132 + 84 + 19 = 612        # the four rows of 04-subject-layout.md:152-157
```

over a population the same section fixes at 616 (`:137`, `:139` — "616 of 616" twice).
Four files are missing, and they are not a rounding artefact: they are the files part 3
**refuses**. Appendix A, script 1 (an independent implementation of the three parts as
written, run over every `tests/**/*Test.php` with the map applied):

```
$ python3 inv.py
{'exact': 377, 'prefix': 132, 'listA-no-covers': 84, 'listB-other-owner': 19,
 'part3-fail': 4} TOTAL 616
```

The `exact`, `prefix`, list-A and list-B counts reproduce the plan's four numbers
exactly — 377, 132, 84, 19. The fifth bucket is the finding:

```
tests/Analysis/Run/Unit/Collection/FileProcessingResultTest.php    remainder 'Collection'    covers remainder 'Contract/Collection'
tests/Analysis/Run/Unit/Configuration/RunConfigurationScopeTest.php remainder 'Configuration' covers remainder 'Contract/Configuration'
tests/Analysis/Run/Unit/Pipeline/AnalysisCoverageTest.php           remainder 'Pipeline'      covers remainder 'Contract/Pipeline'
tests/Analysis/Run/Unit/Pipeline/AnalysisResultTest.php             remainder 'Pipeline'      covers remainder 'Contract/Pipeline'
```

All four elide a `Contract/` segment: the path says `…/Unit/Pipeline/`, the covered class
is `Analysis\Run\Contract\Pipeline\AnalysisCoverage`. `Pipeline` is not a segment-wise
prefix of `Contract/Pipeline`, so part 3 as stated refuses them. They are on neither
exception list (they have a `#[CoversClass]`, and its owner **is** their path owner), and
none of them is in the map:

```
$ grep -c 'tests/Analysis/Run/Unit' …/stage-04/relocation-map.csv
0
```

So no package moves them, no list holds them, and `04-packages.md:235` — "Green over
`tests/**/*Test.php`" — is false at the moment P5 is written. This is the shape F2 and
codex-02 named one round ago: a control whose expected numbers were measured over a
population that leaves it red on day one. The implementer will resolve it at the keyboard,
by widening part 3 until the tree is green, which is `04-subject-layout.md:131-133`'s own
named failure mode.

**Fix direction.** Decide the `Contract/` elision in the plan, not at the keyboard. Three
shapes, all cheap: state the elision as part of the rule (a path remainder may drop a
leading `Contract` segment — it is this tree's convention, the same argument the prefix
form already rests on); or add the four as a third capped exception list with its own
ceiling; or move the four, which makes them four more map rows. Whichever is chosen, the
Part-3 table must sum to 616.

---

### N2 — HIGH — the measurement the weakening rests on reports 23 where the plan says 19, and it reached 23 by calling the four refusals an exception

**Anchor.** `measurement/stage-04/invariant-shape.md:35,42-53,62-70` versus
`04-subject-layout.md:157,165-172`, `04-packages.md:227`, `05-content-defects.md:117-119`.

**Status: fact, measured.**

The plan says 19 in three files. The measurement it cites for exactly this number says 23,
in a table and in a section heading:

```
$ grep -n '23\|19' measurement/stage-04/invariant-shape.md | head
35:| covers only classes owned by someone else — exception list, ceiling **23** | 23 |
42:## The 23 are a real signal, not noise
```

`377 + 132 + 84 + 23 = 616`. So the measurement's total is right and the plan's is not —
because the measurement put the four N1 refusals into list B. That is a false description
of those four: `invariant-shape.md:44-45` defines the bucket as "test classes filed under
one owner whose only coverage claim names a class owned by another", and all four cover
`Analysis\Run\…` classes while sitting under `Analysis/Run`. Their owner agrees; only
their remainder does not.

19 is the correct count of the bucket as described — independently corroborated by the
plan's own downstream claim, which I re-measured:

```
$ python3 -c "…"   # 19 list-B paths against measurement/defect-ledger.tsv
5   # ChannelDeclarationCompilerPassTest, BaselineCommandOptionSurfaceTest,
    # ConfigurationErrorChannelRejectionTest, ThresholdOverrideIntegrationTest,
    # RuleOptionKeyDoorSymmetryTest
```

matching `04-subject-layout.md:169-170` and `05-content-defects.md:121` ("**Five of those
19**") exactly. So the plan's 19 is the measured number and `invariant-shape.md` is the
stale artifact — which means the document the plan cites as the evidence for the shape of
part 3 is the one document that hides part 3's counterexamples.

This could not be caught by re-reading, because the artifact is not reproducible. Its
§Reproducing is a comment block, not a command:

```python
# For each tests/**/*Test.php, take its post-stage path from relocation-map.csv
# (or its current path when the map does not name it), split on the level segment,
# and test the three parts. …
```

A number whose reproduction is prose is a number nobody will re-run — in a stage whose own
DoD preamble (`04-subject-layout.md:207-209`) adopts the opposite rule: "Every number below
names the population it was measured over **and the command that measures it**."

**Fix direction.** Re-run the measurement and publish the command that produced it (round
1's Appendix A and this file's Appendix A are both usable as-is); restate
`invariant-shape.md`'s fourth bucket as 19 with a fifth row for the four refusals, so the
document that justifies the prefix form is also the document that shows what the prefix
form still refuses.

---

### N3 — HIGH — P2 empties four declared directories and retires three; P3 empties three and retires one; both DoDs assert the opposite

**Anchor.** `04-packages.md:16-21` (the new rule), `:140-142` (P2 "remove 3"), `:155`
(P2 DoD "A fresh clone of this commit runs"), `:167-169` (P3 "remove 1"), `:172` (P3 DoD
"fresh clone runs"), `:186-187` (P4 removes the three buckets).

**Status: fact, measured.**

Replaying the map package by package against `git ls-files tests` and the 46
`tests`-rooted `<directory>` entries of `phpunit.xml.dist` (Appendix A, script 2):

```
after P1: []
after P2: tests/Reporting/FindingProjection/Unit, tests/Reporting/Formatter/Suppressed/Unit,
          tests/Reporting/Formatter/Sarif/Integration, tests/Functional
after P3: the four above, plus tests/Unit, tests/Integration,
          tests/Analysis/Finding/RuleConfiguration/Unit
```

P2 names the first three and not `tests/Functional`; P3 names
`tests/Analysis/Finding/RuleConfiguration/Unit` and not `tests/Unit` or `tests/Integration`.
The three it misses are the buckets, retired at P4 (`:186-187`). So of the seven entries F4
enumerated, the cure retires four in the package that empties them and still defers three —
and the three deferred are exactly the ones whose deferral produces F4's failure: from P2's
commit through P3's, a fresh clone carries `<directory>tests/Functional</directory>` naming
a path git does not track; from P3's commit through P4's, three such entries. PHPUnit exits
2 and runs nothing (round 1 measured this in a scratch project; I did not re-run it).

What makes this unarguable rather than a judgement call is that both packages assert the
absence of the condition they create. `04-packages.md:155`: "**A fresh clone of this commit
runs: no declared `<directory>` is absent from disk.**" `:172`: "fresh clone runs". Both are
false as the packages are written, and the rule they violate is stated 140 lines above them
in the same file, complete with the count of what the first draft left "on the floor".

The three other measured directory claims are **correct** and I record them as checked: P1
"empties nothing that is declared" (measured: `[]`); P2 "add 2" (`tests/Reporting/Functional`,
`tests/Reporting/Integration` — the only two new level roots its targets need, the deeper
`Formatter/…` and `Formatter/Sarif/…` being covered by them, and `tests/Reporting/Support`
holding no test); P3 "add 1" (`tests/Core/Unit`, covering `Observation/` and `Util/`).

**Fix direction.** Either the three buckets are retired by the package that empties them —
`tests/Functional` in P2, `tests/Unit` and `tests/Integration` in P3, each with its
`testSuitePrefixTable()` row, leaving P4 with the allowance and the generated artifacts —
or P2's and P3's DoD drops the fresh-clone line and the stage records two commits of
`main` that CI cannot run. The first is what the rule at `:16-21` already says.

---

### N4 — MEDIUM — P3 moves `FromArrayKeyReader`; its two non-moving consumers are P6's, three packages later

**Anchor.** `04-subject-layout.md:200` (ownership table), `04-packages.md:162-164` (P3
Files: "plus the constants naming them, **which is none**"), `:171-173` (P3 DoD),
`:272-275` (P6 "Also in the set").

**Status: fact, measured.**

Row 1 of the map moves the support class, and its owner puts it in P3:

```
$ head -2 …/relocation-map.csv
tests/Analysis/Finding/RuleConfiguration/Support/FromArrayKeyReader.php,
  tests/Analysis/Finding/Support/FromArrayKeyReader.php,Analysis.Finding,none+decision,…
```

`Analysis.Finding` → P3's set ("rows whose `owner` is `Core.Neutral` or starts with
`Analysis.` — 11"). Two files `use` it and do not move:

```
$ git grep -n FromArrayKeyReader -- governance scripts
governance/RuleOptionKeys/DeclaredOptionKeysCoverReadKeysTest.php
scripts/enumerate-rule-option-keys.php
```

`04-subject-layout.md:200` gives both to **P6**, and `04-packages.md:272-275` accepts them
there — but P6 runs after P4 and P5, and the class moves in P3. Two consequences, both
inside P3's own DoD: `composer check:code` cannot be green with a governance test importing
a class that no longer exists at that FQCN, and "`git grep -l` for each moved file's old
FQCN returns nothing" is false by exactly these two files. P3's derived file set says the
constants naming its rows are "none", which is true of the generator (I re-derived it:
zero P3 hits) and silent about consumers that are neither constants nor moving files.

This is the "work deferred to a package that will not see it" shape, inverted: the work is
assigned to a later package, and the earlier package's DoD forces it anyway.

Unknown, and I say so rather than guess: whether the round-1 draft of `04-packages.md`
carried the same split. That file is untracked, so I cannot diff it, and the round-1 reports
do not quote its P3/P6 sections.

**Fix direction.** Move the two consumers into P3's file set — the package that moves the
class fixes its importers — and leave P6 with `FindingFactory` and its eight Baseline
consumers, which is a self-contained set. If the split is deliberate, P3's DoD must say
which of its two clauses is expected to be red and why, which is worse than moving two
`use` lines.

---

### N5 — MEDIUM — "55 files … keep a legacy namespace" is 30 files; 55 is the allow-list's row count

**Anchor.** `04-subject-layout.md:230-235`.

**Status: fact, measured.**

> 55 files that this stage does not move keep a `Qualimetrix\Tests\{Unit,Integration,Functional}`
> namespace and are recorded as such in `governance/TestSuiteHygiene/namespace-path-allow-list.php`

```
$ git grep -l -E "^namespace Qualimetrix\\\\Tests\\\\(Unit|Integration|Functional)(\\\\|;)" -- tests \
    | grep -v '^tests/\(Unit\|Integration\|Functional\)/' | wc -l
30
$ grep -c "=> 'Qualimetrix" governance/TestSuiteHygiene/namespace-path-allow-list.php
55
$ grep -c "=> 'Qualimetrix\\\\\\\\Tests\\\\\\\\\(Unit\|Integration\|Functional\)\\\\\\\\" \
    governance/TestSuiteHygiene/namespace-path-allow-list.php
30
```

55 is the allow-list's total rows — and its `ceiling` — over **all** namespace-versus-path
violations, of which the legacy-bucket namespaces are 30. The conclusion the sentence draws
(do not grep for namespaces; the allow-list guards that side) is right; the number attached
to it belongs to a different population. That is the defect this stage's DoD preamble was
written against, twelve lines below it: "A number without both is how the ceiling of 8 got
into the first draft: it was true of the 114-row map and false of the tree the control
judges." The bullet also carries no command, which the same preamble requires.

**Fix direction.** "30 files … and the allow-list records them among its 55 rows", with the
`git grep` above as the command.

---

### N6 — MEDIUM — the narrowed path grep still returns live hits that are not measurement documents

**Anchor.** `04-packages.md:207-211`.

**Status: fact, measured.**

> `git grep` for the legacy *paths* `tests/Unit`, `tests/Integration`, `tests/Functional`
> returns only historical measurement documents

Today, outside `docs/`, the grep returns 56 hits, 53 of them inside the generator's
`classifyOwner()` ladder — which P0 deletes, so they are answered. Three are not:

```
$ git grep -n -E "tests/(Unit|Integration|Functional)/" -- . ':!docs' \
    ':!scripts/generate-modular-architecture-test-inventory.php'
tests/Analysis/Finding/Unit/LocationTest.php:69:  RelativePath::fromString('tests/Unit/CoreTest.php')
tests/Analysis/Finding/Unit/LocationTest.php:70:  'tests/Unit/CoreTest.php:10'
tests/Infrastructure/Console/Functional/ApplicationRefusalTest.php:19:  … `tests/Unit/Infrastructure/Console/ApplicationTest.php`
```

and inside `docs/`, two ADRs name legacy paths as live references:

```
$ git grep -n -E "tests/(Unit|Integration|Functional)/" -- docs/adr
docs/adr/0009-yaml-loader-normalization-model.md:86,87
docs/adr/0014-deptrac-retirement.md:86,137
```

An ADR is not a historical measurement document, `LocationTest`'s two are a fixture string
(a plausible-looking path used as data — it never existed), and `ApplicationRefusalTest:19`
is the prose docblock round 1 flagged as F7's third population. No package owns any of the
five. The predictable outcome is the one F7 predicted for the un-narrowed form: the grep is
run, returns hits, and is waived by hand.

`ApplicationRefusalTest` is half-covered: `addresses.md:87` lists the file for its **line
156** `{@see}` reference, so an implementer editing it would probably see line 19 too. The
ADRs and the fixture string are covered by nothing.

**Fix direction.** State the exclusions in the clause — `':!docs/adr'`, the ledger/plan
trees, and the `LocationTest` fixture by name — or scope it to `-- src tests governance
scripts tools` minus those two files, and re-run it as written before it is written down.

---

### N7 — LOW — P0 enumerates every expected `target_path` and licenses `disposition` to change with no expected value

**Anchor.** `04-packages.md:69-79`.

**Status: fact.**

The cure for F3 asked for an enumerated expected diff. The `target_path` half is enumerated
per row and is strong (it is also what closes codex-04). The `disposition` half is one
clause: "no row added, no row dropped — **only the `target_path` and `disposition` columns
move**". That constrains which columns may change and never says what `disposition` must
become, although P0 rewrites `dispositionFor()` and drops its
`^tests/Infrastructure/(Unit|Integration)/` anchor (`:58-60`). A `dispositionFor()` rewrite
that is wrong in either direction — flipping Retain→Move for files that are in place, or
Move→Retain for files that must still move — satisfies every clause of P0's DoD.

**Fix direction.** Add the symmetric clause: for every row outside the allowance the
disposition is the "retain" value, for every row inside it the "move" value, and any other
combination is the refusal.

---

### N8 — LOW — "a stale row is refused as loudly as a missing one" is claimed twice and planted nowhere

**Anchor.** `04-subject-layout.md:162-163`, `measurement/stage-04/invariant-shape.md:58-60`,
`04-packages.md:239-249` (P5's six plants).

**Status: fact.**

Both ceilings are justified by the sentence "a row that no longer describes a real exception
is refused as loudly as a missing one". P5's sixth plant is the **inverse** — "a row of
either list deleted while its file still needs it". Nothing plants a row that survives after
its file gained a `#[CoversClass]` or changed owner, which is the direction the sentence
promises and the direction a real tree drifts in (a test gets an attribute; nobody re-derives
the list). The same asymmetry sits in P0's four plants: plant 2 covers a stale allowance row
for a **conforming** file only (see codex-05 above).

**Fix direction.** Add the seventh plant: give a file on list A a `#[CoversClass]` without
removing its row, and record the refusal.

---

### N9 — MEDIUM — P5 and P6 are declared parallel and both rewrite the inventory generator

**Anchor.** `04-packages.md:3-4`, `:6-9`, `:218-219`, `:263-270`;
`04-subject-layout.md:187-188`.

**Status: fact.**

`04-packages.md:6-9` states the isolation rule for this stage: "The move packages have
disjoint test-file sets but all of them edit `phpunit.xml.dist` and
`scripts/generate-modular-architecture-test-inventory.php`. Isolation here is temporal, not
spatial: two packages in parallel would rewrite the same two files."

P5's Files section names the generator explicitly (`:218-219`, the `testSuitePrefixTable()`
row for the new group). P6's edits are the two generator constants the round-1 fix added
(`:263-270`, `P6_A_FINDING_TEST_PATHS` and `P6_C_BASELINE_PATHS_SHA256`) — which is what made
codex-01 a finding in the first place, and its fix_direction closed with exactly this:
"after this, P5 and P6 share one file and cannot be declared independently parallel."
Both documents still declare them parallel.

**Fix direction.** Apply the file-set rule the stage states for itself: P5 → P6 sequentially,
or say which of the two owns the generator and have the other take its change through it.

---

### N10 — LOW — D1's "moves 504 files instead of 114" counts the wrong population

**Anchor.** `00-overview.md:30` (rewritten this round from "583 … instead of 96").

**Status: fact, measured.**

```
$ git ls-files tests | grep -c 'Test\.php$'                                    # 616
$ git ls-files tests | grep 'Test\.php$' | grep -c  '^tests/\(Unit\|Integration\|Functional\)/'   # 87
$ git ls-files tests | grep 'Test\.php$' | grep -vc '^tests/\(Unit\|Integration\|Functional\)/'   # 529
```

Under a `{level}/{subject}` layout the 87 bucket files already have the right shape and
every one of the other **529** moves. 504 is the count of files already sitting at their
manifest owner — the population of a different sentence three bullets above
(`00-overview.md:18-22`). The rejection of D1 stands either way; the number is 25 short and
was measured over the wrong set.

**Fix direction.** 529, with the command.

---

## Checked and found clean — no finding

Recorded so the coverage of question 2 is legible rather than implied.

- **"every generator constant naming one of those rows" (P1), and its P2/P3 counterparts.**
  The brief asked whether this is a set claimed without enumeration. It is enumerated, and I
  re-derived it independently by matching every single-quoted literal in the generator
  against the 114 current paths *and* against the declared FQCN of every moving file:
  P1 → `P3_TEST_PATHS` ×2, `P6_D_GIT_TEST_PATHS` ×2, `P6_LIVE_ADDED_TEST_IDS` ×1,
  `P6_RENAMED_TEST_IDS` ×3; P2 → `P6_D_REPORTING_TEST_PATHS` ×1; P3 → none. Exactly what
  `04-packages.md:101-106,134,162` claims, and the FQCN half (4 literals) matches
  `addresses.md:86`.
- **The package partition.** P1 = 52, P2 = 51, P3 = 11, total 114, and the map's `group`
  column splits 88 legacy-bucket / 26 residue — both as claimed.
- **The five `{@see}` references added to `addresses.md`.** Re-derived: exactly 5 references
  to **moving** classes in non-moving, non-docs files, in exactly the 5 files listed. (The
  sweep also surfaces 4 references to classes that stages 02/03 already moved — pre-existing
  drift, outside this stage.)
- **"Five of the 19 are already rows in `defect-ledger.tsv`."** Exactly 5, by full path.
- **84, 377, 132, 19, 616.** All reproduce (N1 is the fifth bucket the table omits, not a
  disagreement with any of these five).
- **The residue rationale.** Round 1's F1 noted that "leaving them would make the invariant
  unstatable" was wrong. Under the restated invariant it is now **right**: the residue paths
  (`tests/Reporting/FindingProjection/Unit`, …) fail part 1, so leaving them would take part
  1 below 616 of 616. The sentence survived the rewrite and is now true.
- **`relocation-map.csv` line endings**: 0 CR.

## Not checked

- `prediction.md`'s per-suite counts (6705/383/152/1029/179/748) — accepted from round 1,
  which re-derived them per file; I did not re-run PHPUnit.
- PHPUnit's exit-2-on-absent-`<directory>` behaviour — accepted from round 1's scratch-project
  measurement. N3 rests on the directory arithmetic, which I did measure.
- `48` versus `136` `<directory>` entries; `320` literals of which `103` dead; the P6-C digest
  transition `6ec107b9…` → `818d94bd…`; `assertCount(28/1)`. None re-derived.
- Whether the `Contract/` elision in N1 has other instances outside `Analysis.Run` — the four
  are all part 3 refuses today; I did not classify how many *conforming* files would newly
  depend on an elision rule if one were adopted.
- Level correctness of any target, and the interaction with stage 05's ledger — out of scope
  by the plan's own statement and by this round's brief.

## Assumptions

1. `*Test.php` ≈ "PHPUnit test class", as in round 1; the population is 616 either way.
2. Part 3 read as written — the *path* remainder must be a segment-wise prefix of the
   *covered class's* remainder, not the reverse. Under the reverse reading the four N1 files
   pass and 132 others fail, which is worse, so the reading is not what produces N1.
3. `Core.Neutral` → `tests/Core`, per `04-subject-layout.md:135`.
4. A `<directory>` "empties" when no tracked file remains under it after the package's moves;
   git tracking no empty directory is what makes that equivalent to "absent on a fresh clone".

---

## Appendix A — the two scripts

**Script 1 — the three parts, per file, over the post-move tree.** Written from
`04-subject-layout.md:129-150`, not from any generator. Resolves each `#[CoversClass]`
through the file's `use` statements and looks the FQCN up in the manifest; applies
`relocation-map.csv` to get each file's post-stage path.

```python
import json, re, subprocess, collections, csv, sys
M = json.load(open('docs/internal/modular-architecture-manifest.json'))
OWNERS, DECL = M['owners'], M['declarations']
FQ2O = {k: v['owner'] for k, v in DECL.items()}
LEVELS = {'Unit','Integration','Functional'}
opath = lambda o: 'Core' if o=='Core.Neutral' else o.replace('.','/')
ons   = lambda o: 'Qualimetrix\\' + ('Core' if o=='Core.Neutral' else o.replace('.','\\'))
OPATHS = {opath(o): o for o in OWNERS}

def parse(f):
    src=open(f,encoding='utf-8',errors='replace').read()
    ns=re.search(r'^namespace\s+([^;]+);',src,re.M); ns=ns.group(1).strip() if ns else None
    uses={}
    for m in re.finditer(r'^use\s+([A-Za-z0-9_\\]+)(?:\s+as\s+([A-Za-z0-9_]+))?\s*;',src,re.M):
        uses[m.group(2) or m.group(1).split('\\')[-1]]=m.group(1)
    covers=[]
    for m in re.finditer(r'#\[\s*CoversClass\s*\(\s*([^\)]*?)\s*\)\s*\]',src):
        a=m.group(1).strip()
        if not a.endswith('::class'): covers.append('STRING:'+a); continue
        n=a[:-7]
        if n.startswith('\\'): covers.append(n[1:]); continue
        head=n.split('\\')[0]
        covers.append(uses[head]+n[len(head):] if head in uses else (ns+'\\'+n if ns else n))
    return ns, sorted(set(covers))

files=[f for f in subprocess.check_output(['git','ls-files','tests']).decode().split()
       if f.endswith('.php')]
MAP={r['current']:r['target'] for r in csv.DictReader(
     open('docs/internal/plans/test-structure/measurement/stage-04/relocation-map.csv'))}
res=collections.Counter(); buckets=collections.defaultdict(list)
for f in files:
    p=MAP.get(f,f)
    if not p.endswith('Test.php'): continue
    segs=p.split('/')[1:-1]
    lv=[i for i,s in enumerate(segs) if s in LEVELS]
    if len(lv)!=1: res['part2-fail']+=1; buckets['part2-fail'].append(p); continue
    i=lv[0]; opart='/'.join(segs[:i]); rem='/'.join(segs[i+1:])
    if opart not in OPATHS: res['part1-fail']+=1; buckets['part1-fail'].append(p); continue
    o=OPATHS[opart]
    ns,covers=parse(f)
    if not covers: res['listA-no-covers']+=1; buckets['listA-no-covers'].append(p); continue
    mine=[c for c in covers if FQ2O.get(c)==o]
    if not mine:
        res['listB-other-owner']+=1
        buckets['listB-other-owner'].append((p,sorted({FQ2O.get(c) for c in covers}-{None})))
        continue
    prefix=ons(o); rs=set()
    for c in mine:
        cns=c.rsplit('\\',1)[0]
        rs.add('' if cns==prefix else cns[len(prefix)+1:].replace('\\','/'))
    if rem in rs: res['exact']+=1; buckets['exact'].append(p)
    elif any(r.startswith(rem+'/') for r in rs) or rem=='': res['prefix']+=1
    else: res['part3-fail']+=1; buckets['part3-fail'].append((p,sorted(rs),rem))
print(dict(res),'TOTAL',sum(res.values()))
for k in sys.argv[1:]:
    print('===',k)
    for x in buckets[k]: print('  ',x)
```

Output on `main` @ `e15c7f42` with the working tree's map:

```
{'exact': 377, 'prefix': 132, 'listA-no-covers': 84, 'listB-other-owner': 19,
 'part3-fail': 4} TOTAL 616
```

**Script 2 — which declared `<directory>` empties in which package.**

```python
import csv, re, subprocess
rows=list(csv.DictReader(open('docs/internal/plans/test-structure/measurement/'
                              'stage-04/relocation-map.csv')))
pkg=lambda o: 'P1' if o.startswith('Infrastructure.') else ('P2' if o=='Reporting' else 'P3')
cur=set(subprocess.check_output(['git','ls-files','tests']).decode().split())
decl=[d.strip() for d in re.findall(r'<directory>([^<]+)</directory>',
                                    open('phpunit.xml.dist').read()) if d.strip().startswith('tests')]
under=lambda d,fs: {f for f in fs if f==d or f.startswith(d.rstrip('/')+'/')}
for p in ('P1','P2','P3'):
    for r in (r for r in rows if pkg(r['owner'])==p):
        cur.discard(r['current']); cur.add(r['target'])
    print(p, [d for d in decl if not under(d,cur)])
```

Output:

```
P1 []
P2 ['tests/Reporting/FindingProjection/Unit', 'tests/Reporting/Formatter/Suppressed/Unit',
    'tests/Reporting/Formatter/Sarif/Integration', 'tests/Functional']
P3 [… the four above …, 'tests/Unit', 'tests/Analysis/Finding/RuleConfiguration/Unit',
    'tests/Integration']
```

---

## coverage

**Read in full:** `04-subject-layout.md`, `04-packages.md`, `06-governance-subject-groups.md`,
the working-tree diffs of `00-overview.md`, `01-suite-integrity.md`, `05-content-defects.md`;
`measurement/stage-04/{invariant-shape.md, addresses.md, relocation-map.csv, generator-probe.md}`;
`measurement/stage-04-review/{native.md, codex.md}` (all 21 findings);
`governance/TestSuiteHygiene/namespace-path-allow-list.php`; `phpunit.xml.dist`;
`docs/internal/modular-architecture-manifest.json`; the constant blocks and
`classifyOwner()` region of `scripts/generate-modular-architecture-test-inventory.php`.

**Re-measured independently:** the three invariant parts per file over the post-move tree
(377 / 132 / 84 / 19 / **4** = 616); the package partition (52 / 51 / 11) and the map's
88 / 26 group split; which declared `<directory>` empties in which package; the generator
constants naming each package's rows (P1 four constants, P2 one, P3 none) by literal and by
FQCN; the five `{@see}` rows of `addresses.md`; the five of nineteen in `defect-ledger.tsv`;
the allow-list (55 rows, 30 legacy-namespace) against the 30 files carrying one; the
repository-wide legacy-path grep; `relocation-map.csv` line endings; the 616 / 87 / 529
population split behind D1.

**Accepted from round 1 without re-running:** `prediction.md`'s six suite counts; PHPUnit's
exit 2 on an absent `<directory>`; 48/136; 320/103; the P6-C digest values; `assertCount(28/1)`.
