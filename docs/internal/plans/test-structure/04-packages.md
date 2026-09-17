# Stage 04 — work packages

Order: **P0 → P1 → P2 → P3 → P4 → P5 → P6**, strictly sequential throughout. An
earlier draft ran P5 and P6 in parallel; they both rewrite
`scripts/generate-modular-architecture-test-inventory.php` — P5 adds a
`testSuitePrefixTable()` row, P6 edits `P6_A_FINDING_TEST_PATHS` and the P6-C digest —
so the stage's own isolation rule forbids it.

The move packages have disjoint test-file sets but all of them edit
`phpunit.xml.dist` and `scripts/generate-modular-architecture-test-inventory.php`.
Isolation here is temporal, not spatial: two move packages in parallel would rewrite
the same two files.

Every package commits before the next starts, and before any round of review fixes —
otherwise "it was red before the fix" can no longer be re-checked.

## Two rules that hold for every package

**A package leaves the tree runnable.** It removes every `<directory>` its moves
emptied and adds every one its moves filled, in its own commit. Deferring either to a
later package leaves an intermediate `main` worse than both its neighbours: git tracks
no empty directory, so a fresh clone of that commit hands PHPUnit a path that is not
there and PHPUnit exits 2 having run nothing. The first draft of this file deferred all
seven entries to P4. The second wrote this rule and then applied it to four of the
seven, keeping the three bucket entries for P4 out of habit — so the cure carried the
disease one step smaller. **The set is computed, never recalled:** a declared entry
belongs to the package after which nothing tracked remains beneath it, which puts
`tests/Functional` in P2 and `tests/Unit` and `tests/Integration` in P3.

**A named consumer set is a subset, and the package is told so.** Every one of
the three move packages was handed a list of the files that consume what it
moves, and every one of them found the list short: P1 by two, P2 by six, P3 by
two. None of the misses broke a build — they were docblock links and prose, the
references nothing resolves — which is exactly why enumeration missed them and
only a sweep finds them. The cure is not a better list. It is that a brief names
its list as the subset a sweep is expected to grow, and that the package's
Definition of Done is the sweep rather than the list.

**The sweep is six questions, not one.** Four spellings of a name — the declared
fully qualified name, its PHP-escaped form with every backslash doubled, the bare
basename word-anchored, and the current path literal — plus **two** prefix
sweeps, by the directories the package fills and by the ones it empties. P3
measured that only the emptied-prefix sweep reaches a reference that names a
*directory* in prose (`scripts/enumerate-rule-option-keys.php` names
`tests/Analysis/Finding/RuleConfiguration/Support/`): the path sweep matches whole
file paths and the name sweep matches namespaces, so a directory named in a
sentence falls between them.

A seventh question is asked by
[`measurement/stage-04/dangling-test-names.py`](measurement/stage-04/dangling-test-names.py),
which is tracked precisely because three packages in a row wrote it from scratch
in a scratchpad that then vanished. It asks the opposite of every sweep above —
which `Qualimetrix\Tests\…` names in the tree resolve to no file — and it is
the only thing that reaches a reference spelled with a name from an *earlier*
rename. At the end of this stage it reports **9**: four rename-record halves in
the generator, one namespace a refusal control plants on purpose, and four stale
references older than this stage. None was created here; all four of the last
group are stage 05's.

**A package sweeps the prefixes it fills, not only the names it moves.** Every
sweep in this stage is keyed on something that exists *before* a move — a class
name, a path, a namespace — so none of them can reach a claim about a directory
that exists only *after* it. P2 found the shape by having `composer check:code`
go red: the generator's own no-suite control planted its probe at
`tests/Reporting/Functional`, the directory P2 creates and declares, and started
failing on "this directory should not exist" before reaching the refusal it is
about. The rule is therefore a second sweep, by the target prefixes the package
creates, and P2 ran it: exactly one member. This is the same defect class as the
earlier-epoch reference channel — a claim whose spelling no sweep over the
current tree can produce.

**A package's file set is derived, not narrated.** Each Files section below states the
derivation from `relocation-map.csv` and from the generator's own constants. A
narrated file set is how P6 came to omit the two generator guards that would have
stopped it on its first run.

---

## P0 — the owner derivation becomes a path parse

**Subject.** Replace the prefix ladder with a rule, and open a named, shrinking
allowance for the files that have not moved yet.

**Files.** `scripts/generate-modular-architecture-test-inventory.php`,
`scripts/modular-architecture/tests/**`, the regenerated artifacts under
`docs/internal/generated/modular-architecture/`. No test file moves.

**The population the parse answers for is `tests/**/*Test.php`** — measured, not
assumed: on `main` @ e15c7f42 that pattern and `kind === 'phpunit-test-class'`
under `tests/` name the same 616 rows, and every one of them has a level
segment. Every other artifact the generator inventories — fixtures, `Support/`
classes, the JS process files, the governance and tooling roots — is outside
the invariant and outside this rewrite: its owner, target and disposition come
from the surviving ladder unchanged. A rule applied to a population it was not
measured over is this stage's recurring defect, so the rewrite states its
population and the oracle checks the complement is untouched.

**What changes.**

```
classifyOwner(path):
  tooling root          -> TOOLING_TEST_ROOT_OWNERS      (unchanged)
  in LEGACY_UNMOVED     -> the owner that list records
  tests/**/*Test.php    -> segments before the level segment = owner path
                           validate against the 37 manifest owners
                           Core.Neutral is spelled `tests/Core`   // the one owner whose
                                                                 // name is not a namespace
                           refuse, naming path and segment, if unknown
  otherwise             -> the surviving ladder for fixtures and support
// ... implementation details
```

A path whose level segment is not immediately below the owner is refused by the
same check and needs no second one: `tests/Reporting/Formatter/Html/Unit/X.php`
parses to owner `Reporting/Formatter/Html`, which is not one of the 37.

`targetPath()` gains the remainder segment it has never had: today it builds
`tests/{owner}/{suite}/{basename}` and cannot express
`tests/Reporting/Unit/Formatter/Html/...` at all. A conforming path returns
itself; a `LEGACY_UNMOVED` path returns the target that list records.

`dispositionFor()` loses the `^tests/Infrastructure/(Unit|Integration)/` anchor
and every other "retain at the materialized path" case keyed on a bucket path,
and answers from the same parse.

**`closure_package` answers the same question as the disposition and must move
with it.** The plan said nothing about this column, and it is not free: the
value is what the ladder happens to return, so under an untouched ladder a moved
file would carry the closure package of whatever branch its *new* path falls
into — `tests/Infrastructure/Console/Functional/HookInstallCommandTest.php`
stops being `Infrastructure/GitHook, permanent` and starts being whatever
`tests/Infrastructure/` yields. The column means "which package still owes this
artifact a move". For the population above it is therefore derived, not
inherited: a conforming test class is `permanent`, an allowance row is
`stage-04`, and the value disappears with the allowance in P4. Every other row
keeps what it has, including the fixtures whose pending relocation is a
different subject's.

`LEGACY_UNMOVED` is a transitional allowance with a named owner and a named
closing condition, per ADR 0016's temporary-grant rule. It maps `current path =>
[owner, target]` and holds **112** of the map's 114 rows — its population is the
test classes, the same population the invariant is about. The two rows it does
not hold are the map's support files,
`tests/Analysis/Finding/RuleConfiguration/Support/FromArrayKeyReader.php` and
`tests/Unit/Reporting/Formatter/Sarif/Support/StubChannelPresentation.php`: the
surviving ladder already returns each one's map owner *and* its map target, so
an allowance row for them would refuse nothing and describe nothing — an inert
row is worse than an absent one, because it reads as coverage. They still move
with their package, P3 and P2 respectively, and the stage's completeness check
is against the map, not against the allowance.

**`validateP4Topology()` is deleted here, not retired in P1.** It selects rows
by `closure_package === 'P4'`, which the rule above stops producing, so under
P0 its two `foreach` loops would iterate an empty set and pass vacuously — the
silent shape this repository's own notes warn about — while its third assertion
("exactly one Console class, at this pinned path") becomes false the moment P1
lands. Its live content is subsumed: "every Architecture test is under
Architecture" is what the parse makes true by construction and what P5's control
asserts over the tree.

What replaces it is not a restatement of the parse — a generator checking its
own output against its own rule proves nothing. `assertLegacyUnmovedShrinksOnly()`
checks the allowance against **the disk and the manifest**:

1. every key is a file on disk — and when it is not, the refusal distinguishes
   "gone" from "already moved to its recorded target", because those are two
   different mistakes;
2. every key parses to an owner that is **not** among the 37 — a key that
   already conforms is a row that describes nothing;
3. every recorded target parses to an owner that **is** among the 37, with the
   level segment immediately below it — which is what refuses a target of the
   shape the rejected map proposed.

A fourth arm was specified and then measured out of existence: "the recorded
target differs from the key" is statically false, and PHPStan says so. It is not
a gap, because such a key cannot escape the other two — it either conforms, and
arm 2 names it, or it does not, and arm 3 names its target. The implication is
written into the function's docblock rather than left for the next reader to
re-derive.

**Definition of Done.**

P0 *is* the change to owner, target and disposition, so "the artifacts are
byte-identical" is a DoD the package cannot meet. What must hold instead is
stated cell by cell and executed, not read:

```
git show e15c7f42:docs/internal/generated/modular-architecture/test-ownership.tsv > /tmp/p0-baseline.tsv
php scripts/generate-modular-architecture-test-inventory.php
python3 docs/internal/plans/test-structure/measurement/stage-04/p0-oracle.py \
    --baseline=/tmp/p0-baseline.tsv \
    --actual=docs/internal/generated/modular-architecture/test-ownership.tsv
```

[`measurement/stage-04/p0-oracle.py`](measurement/stage-04/p0-oracle.py) is the
DoD; this prose describes it. It asserts, for all 921 rows: no row added, no row
dropped; `kind`, `classes`, `discovered_classes`, `discovered_test_cases`,
`current_suite` and `target_suite` unchanged everywhere, because P0 moves no
file; for an allowance row, `subject_owner` and `target_path` equal what
`relocation-map.csv` records — **the map, not the parse**, so the judge does not
share a derivation with the thing judged — with disposition `Move atomically…`
and closure package `stage-04`; for every other `tests/**/*Test.php` row,
`subject_owner` is the parse owner, `target_path` is the row's own
`current_path`, disposition is `Retain at the materialized subject-owned path.`
and closure package is `permanent`; and for every row outside that population,
all four columns unchanged.

Run against the pre-P0 artifact it reports **1162** disagreements in four
columns — `closure_package` 560, `disposition` 244, `target_path` 242,
`subject_owner` 116 — which is the size of the change P0 is expected to make.
Those numbers are the measurement, not the assertion: the assertion is cell by
cell, and a package that changes 1161 cells correctly and one wrongly is red.

The `subject_owner` figure is the one an earlier draft of this section got
wrong, by asserting that only `target_path` and `disposition` would move. It
moves on 116 rows: **61** of them are moving files whose coarse ladder owner
(`Infrastructure`, `Core`, `Infrastructure/GitHook`, `Reporting/FindingProjection`)
becomes the map's manifest owner, and **55** are files this stage never touches —
39 of them `tests/Infrastructure/Console/**` — whose coarse owner the parse
simply reads more precisely. Two of the 55 are the ladder being *wrong* rather
than coarse, and both were adjudicated against the file's own `#[CoversClass]`:
`tests/Infrastructure/Rule/Unit/ComputedMetricChannelPresentationTest.php`
covers `Infrastructure\Rule\ComputedMetricChannelPresentation` (the ladder said
ComputedMetrics, by a `str_contains` on the basename) and
`tests/Reporting/GraphProjection/Unit/NamespaceFilterTest.php` covers
`Reporting\GraphProjection\NamespaceFilter` (the ladder said `Reporting`). The
parse is right in both.

Then the guard is **observed to refuse**. Every row of the table below is
planted, reverted after its refusal is recorded, each quoted in the package
report:

| Planted                                                                      | Expected refusal                                  |
| ---------------------------------------------------------------------------- | ------------------------------------------------- |
| add a row to `LEGACY_UNMOVED` naming a file that is not on disk              | the path is allowed but absent                    |
| add a row for a file that is on disk and already conforms                    | the allowance may only shrink                     |
| delete a row without moving its file                                         | on disk, in no allowance, does not conform        |
| move a file to its target but leave its row in place                         | the row no longer describes anything unmoved      |
| change a row's recorded target to `tests/Reporting/Formatter/Unit/XTest.php` | the target itself is not at a manifest owner      |
| `--classification-probe=tests/Reporting/Formatter/Unit/XTest.php`            | `Reporting/Formatter` is not one of the 37 owners |

The probe row is not optional: `tests/Reporting/Formatter/Unit` is exactly the
layout the rejected map proposed, and a guard that accepts it has not understood
the rule. Its literal ends in `Test.php` because the parse's population is
`tests/**/*Test.php` and nothing else: an earlier draft wrote `X.php`, which the
parse correctly never sees, so the row would have been satisfied by the ladder
answering about a file the rule does not claim. A probe outside the population
under test proves nothing about the population. The stale-row row is the one two drafts asserted in prose — "a stale
row is refused as loudly as a missing one" — and planted zero times.

**What the guard does not refuse, recorded rather than papered over.** The
"delete a row without moving its file" refusal comes from the parse, so it
reaches only the 112 test classes. The two support files are not in the
allowance and have no parse to refuse them; nothing would notice their rows
being deleted, which is precisely why they have none. Their own protection is
the map: P2's and P3's Definition of Done checks the tree against
`relocation-map.csv` in both directions, and a support file left behind is red
there. The alternative — asserting "every support file whose target differs from
its path is in the allowance" — was measured and refused: **5** support files
outside the map already carry a pending target
(`tests/Infrastructure/Console/Support/*`, `tests/Analysis/Run/Support/Pipeline/*`),
so the rule would need an exception list for a subject this stage does not own.

**Positive probes, because every row above is a refusal.** A parse that refuses
correctly and resolves nothing is still broken, and one branch of it is
exercised by no existing file: `Core.Neutral` spelled as `tests/Core`. There is
no `tests/Core/Unit/...` on disk today — `tests/Core/Path/Unit` and
`tests/Core/Symbol/Unit` belong to the *other* Core owners — and its six targets
arrive only with P3, reaching `targetPath()` through the `LEGACY_UNMOVED` table
rather than through the parse. So P0 also probes and records the returned owner
and target for three paths that do not exist yet:

| `--classification-probe=`                                   | must return                                                 |
| ----------------------------------------------------------- | ----------------------------------------------------------- |
| `tests/Core/Unit/VersionTest.php`                           | owner `Core`, target identical to the probed path           |
| `tests/Reporting/Unit/Formatter/Html/HtmlFormatterTest.php` | owner `Reporting`, remainder `Formatter/Html` preserved     |
| `tests/Infrastructure/Git/Unit/GitClientTest.php`           | owner `Infrastructure/Git`, not the coarse `Infrastructure` |

Each is a shape the pre-P0 generator gets wrong, measured in
[`measurement/stage-04/generator-probe.md`](measurement/stage-04/generator-probe.md).

**The artifact this package rewrites wholesale is judged by a test the aggregate
does not run.** `ModularArchitectureGovernanceIntegrationTest` is
`#[Group('live-freshness')]` and `scripts/phpunit-aggregate.py` excludes that
group, so `composer check` is silent about it. The plan asked this only of P4;
P0 is the package that rewrites every row of `test-ownership.tsv`, so it runs it
by hand and quotes the result:

```
vendor/bin/phpunit --testsuite=Governance --no-coverage \
  --filter=ModularArchitectureGovernanceIntegrationTest
```

Also: `composer check:code` green, `composer architecture:check` green, and the
six per-suite counts identical to the baseline row of
[`measurement/stage-04/prediction.md`](measurement/stage-04/prediction.md) —
6987 / 419 / 203 / 660 / 179 / 748 — because P0 moves no file.

**Leaves uncompensated.** Five pinned literals still name old paths. They are
correct until the files move; each move package retires its own.

---

## P1 — Infrastructure-owned files

**Files.** Derived: rows of `relocation-map.csv` whose `owner` starts with
`Infrastructure.` — **52** — plus every generator constant naming one of those rows,
found by matching each constant's literals against the 52 paths *and* against the 52
declared FQCNs. That derivation yields `P3_TEST_PATHS` ×2, `P6_D_GIT_TEST_PATHS` ×2, and the
`FQCN::method` literals in `P6_LIVE_ADDED_TEST_IDS` / `P6_RENAMED_TEST_IDS`.
`validateP4Topology()`, which the address enumeration found pinning this
package's `LayerAssignmentCommandTest` target, is deliberately **not** in this
set: P0 deletes that function, for the reason stated there. This list was
derived on `main` @ e15c7f42, before P0 rewrote the file — re-derive it against
the committed P0 rather than trusting it.

The derivation runs over **every tracked file, not only PHP**: it also yields
`governance/Channel/Fixtures/declared.txt`, a hand-written fixture naming
`tests/Infrastructure/Unit/ChannelUniverseTest.php` in a comment. A `.txt` fixture is
reached by no PHP sweep and by no guard; it is in this package's set because the
derivation is by content over `git ls-files`, not by file type.

**What changes.** Move; rewrite each namespace to match its new path; delete the rows
from `LEGACY_UNMOVED`; retire the constants above.

No `<directory>` is added or removed: `<directory>tests/Infrastructure</directory>`
already covers every level beneath it, and this package empties nothing that is
declared. Measured, not assumed.

**Definition of Done.**

- Per-suite counts, population `--testsuite=<S>` with the runner's exclusions:
  Unit 6705, Integration 383, Functional 152, Infrastructure 1029, Tooling 179,
  Governance 748. **This package carries the entire suite delta of the stage**, so a
  wrong number can only appear here.
- **`git grep -l` for each moved file's old fully-qualified class name across the whole
  repository returns nothing.** This is the package that proves the reference channels
  the address enumeration missed are closed — docblock `{@see ...}`, test-ID literals
  of the form `FQCN::method`, and anything else that spells a class rather than a path.
  Both witnesses and the merge missed both spellings; a sweep by path and by namespace
  cannot reach either.
- `composer check:code` green. `composer architecture:check` green.
- No file outside the 52 rows moved.

---

## P2 — Reporting-owned files

**Files.** Derived: rows whose `owner` is `Reporting` — **51** — plus the constants
naming them, which is `P6_D_REPORTING_TEST_PATHS` ×1.

**What changes.** Move and rewrite namespaces; delete the rows from `LEGACY_UNMOVED`;
retire that literal.

`<directory>` in the same commit — **remove 4**, which this package empties:
`tests/Reporting/FindingProjection/Unit`, `tests/Reporting/Formatter/Suppressed/Unit`,
`tests/Reporting/Formatter/Sarif/Integration`, **and `tests/Functional` itself**, whose
last remaining files are this package's. **Add 2**, which it fills:
`tests/Reporting/Functional`, `tests/Reporting/Integration`. Each with its matching
`testSuitePrefixTable()` row — `assertSuiteClassifierAgreesWithPhpunit()` reconciles the
two in both directions.

The bucket entry is in this list and not in P4's because the rule is "whoever empties
it removes it", and a draft that kept the three buckets for P4 by convention put
`tests/Functional` on the floor for two commits. The set is computed, not recalled:
a declared entry belongs to the package after which nothing tracked remains beneath it.

**Definition of Done.**

- **Every one of the six suite counts identical to P1's row.** This package is
  suite-neutral by construction; any movement means a file landed under an owner it
  does not have, and nothing else produces that signature.
- `composer check:code` green — this is the largest package by cases, and a package
  that moves files without running them has checked nothing.
- `git grep -l` for each moved file's old FQCN returns nothing.
- A fresh clone of this commit runs: no declared `<directory>` is absent from disk.
- `composer architecture:check` green.

---

## P3 — Core- and Analysis-owned files

**Files.** Derived: rows whose `owner` is `Core.Neutral` or starts with `Analysis.` —
**11** — plus the constants naming them, which is none, **plus the two files that
`use` one of the 11 and do not themselves move**:
`governance/RuleOptionKeys/DeclaredOptionKeysCoverReadKeysTest.php` and
`scripts/enumerate-rule-option-keys.php`, both importing
`FromArrayKeyReader`, which is row 1 of this package.

Those two were assigned to P6 in an earlier draft — three packages later — which made
this package's `composer check:code` unachievable at its own commit. A consumer of a
moved file belongs to the package that moves it, always; deferring the import is
deferring a red build.

**What changes.** Move and rewrite namespaces; delete the rows; update the two imports.

`<directory>` in the same commit — **remove 3**, all emptied here:
`tests/Analysis/Finding/RuleConfiguration/Unit`, **`tests/Unit` and
`tests/Integration`**, whose last remaining files are this package's. **Add 1**, filled
here: `tests/Core/Unit`. All with their `testSuitePrefixTable()` rows.

**Definition of Done.** Six suite counts identical to P1's row; `composer check:code`
green; `git grep -l` for each moved file's old FQCN returns nothing; a fresh clone of
this commit runs, which is where the two bucket removals are proved;
`composer architecture:check` green.

---

## P4 — the buckets are retired and the allowance closed

Small, because P1–P3 each cleaned up after themselves.

**Files.** `phpunit.xml.dist`,
`scripts/generate-modular-architecture-test-inventory.php`,
`governance/ModularOwnership/ModularArchitectureGovernanceIntegrationTest.php`,
`docs/internal/generated/modular-architecture/**`.

**What changes.** Nothing in `phpunit.xml.dist` — the three bucket entries left with
the packages that emptied them, `tests/Functional` in P2 and `tests/Unit` plus
`tests/Integration` in P3. What remains here: assert `LEGACY_UNMOVED` is empty, then
delete the constant and its guard, and refresh the generated artifacts.

**Verify, do not re-derive, the two hardcoded counts** in the governance integration
test: `assertCount(28, ...)` over `test-orphan-dispositions.tsv` and
`assertCount(1, ...)` over `test-system-support-owners.tsv`. Measured: neither depends
on this stage — `P8_ORPHAN_DISPOSITIONS` (112 literals) and `ORPHAN_CANDIDATE_PREFIXES`
(10) intersect the move set in zero places, and `test-system-support-owners.tsv` is
built from `tests/TestSupport`, which nothing here touches. So these numbers must come
out **unchanged**; if either moves, the cause is found before the number is edited.

**That test does not run under `composer check`.** It is `#[Group('live-freshness')]`
and the aggregate excludes that group, so the whole of `composer check` is silent about
it. P4 runs it by hand and quotes the result:

```
vendor/bin/phpunit --testsuite=Governance --no-coverage \
  --filter=ModularArchitectureGovernanceIntegrationTest
```

**What the move packages hand to P4, measured as they ran.** Each is a constant
whose literals still resolve — so `assertPathLiteralsResolve()` stays green — while
nothing can reach them any more. They are listed here rather than pruned piecemeal,
because "is this branch reachable" is answerable only once every move has landed:

- `P6_D_GIT_TEST_PATHS` became unreachable in all three of its consumers with P1: the
  test-class parse precedes them in `classifyOwner()`, `dispositionFor()` and
  `targetPath()`. `P6_D_REPORTING_TEST_PATHS` goes the same way with P2, and the
  `*Test.php` half of `P6_D_PRIORITIZATION_TEST_PATHS` and `P7_MEASUREMENT_PATHS`
  with them. One sweep retires them together.
- `P6_LIVE_ADDED_TEST_IDS` and `P6_RENAMED_TEST_IDS` are **read by nothing** —
  declarations with no consumer, which is why their literals rot unnoticed. One
  already has: row 3's value half names
  `Qualimetrix\Tests\Infrastructure\Integration\RuleExclusionStatsWiringTest`,
  a namespace the file has not had since stage 02 or 03, and no sweep reaches it
  because the name is PHP-escaped, carries no path, and is not the *current* name of
  anything. **Whether a record of the closed migration epoch should survive at all is
  the owner's call, not P4's** — P4 states the finding and leaves the decision named.
  Deleting it is not mechanical cleanup; it is discarding history.

**A support row lands with a disposition that contradicts its own target.**
P2 and P3 each move one support class. After the move the surviving ladder
answers about the new path and returns `target_path` equal to `current_path`
while `dispositionFor()` still falls through to "Move atomically…", because that
function decides from a list of prefixes rather than from the target it is
describing. Owner and target — the two columns anything downstream reads — are
right; the disposition is a sentence disagreeing with the row it sits in. The
cure is to derive the disposition from the target instead of from a prefix list,
which also retires the last "Move atomically" rows whose target is their own
path. It belongs here and not in P2 or P3 because it changes the answer for
fixtures those packages do not own.

**The earlier-epoch reference channel is this stage's new finding.** A reference
spelled with a name from *two* renames ago is invisible to every sweep the campaign
has used, including each move package's own old-FQCN grep, which by construction
searches for the name as of that package's base. Two members are known — the docblock
P1 repaired in `RuleExclusionStatsWiringTest.php` and the rename record above. Nothing
here proves there are only two.

**Definition of Done.** The three bucket directories do not exist and nothing declares
them. `LEGACY_UNMOVED` and its guard are gone. The tree diffed against the map's
`target` column matches row for row **in both directions**, which is the stage's
completeness check; no repository-wide grep stands in for it, because both the legacy
paths and the legacy namespaces still appear for reasons this stage does not create —
two ADRs, other plans, the generated inventory, two docblocks, and the 30 files whose
namespace the allow-list already guards. `composer check` green — the full aggregate,
once.

---

## P5 — the invariant becomes a control

**Files.** `governance/TestSuiteHygiene/` — an existing group, not a new one.

The plan said "a new group"; the three cohesion tests say otherwise, and the
deviation is recorded here rather than taken silently. **Name:** that directory
completes "this is about whether the test tree's files are addressable,
reachable and isolated the way the convention says", which the new control is an
instance of. **Co-change:** it already holds `TestNamespacesFollowTheirPathTest`
— "a test file's namespace says where the file is" — and the new control is the
sibling sentence, "a test file's path says which subject owns it". The two break
on the same event, a file at the wrong address, and the group already carries
the population helper (`TestTree.php`), the derive-script and the derived
allow-list with a ceiling that this control needs. **Counterfactual ownership:**
under independent development both move with the test tree, and neither is
duplicated anywhere else.

`governance/ModularOwnership/` was the other candidate and loses on co-change:
its controls judge the production dependency topology and the generated
architecture artifacts, and change for those reasons. The new control judges the
test tree and merely uses the manifest as its vocabulary.

Consequence, and the reason this is worth the paragraph: no `<directory>` and no
`testSuitePrefixTable()` row are added, so the two-edits-that-must-agree
registration hazard does not arise for this package at all. The Governance case
count still moves by N.

**What changes.** A control over the population `tests/**/*Test.php` — stated as a
pattern, so support and fixture files are excluded deliberately rather than by
oversight — asserting the three parts stated in
[`04-subject-layout.md`](04-subject-layout.md), with part 3 as a prefix test. Two
derived exception lists: **A**, files with no `#[CoversClass]`, ceiling 84 (its rows
distinguish the 5 that declare `#[CoversNothing]` from the 79 that declare nothing, so
a row can be retired for the right reason); **B**, files covering only another owner's
classes, ceiling 19; **C**, files whose remainder drops an interior segment, ceiling 4.

Registering a *new* governance group would mean two edits that must agree — a
`<directory>` under the `Governance` suite and a row in `testSuitePrefixTable()`,
with an unregistered group reddening `composer architecture:check` by name. This
package adds no group, so it owes neither edit; the sentence stays because the
next control that does need a group will need it.

**Definition of Done.**

- Green over `tests/**/*Test.php`.
- **Governance count is 748 + N**, where N is this group's own case count measured by
  `--list-tests` on the new directory. The stage's 748 was measured before this group
  existed and stops being the expected value the moment it lands.
- **Observed to refuse** under every row of the table below, each planted, each
  reverted, each refusal quoted:

| Planted                                                                          | Must be refused because                                                                     |
| -------------------------------------------------------------------------------- | ------------------------------------------------------------------------------------------- |
| a test file under `tests/Reporting/Formatter/Unit/`                              | owner not among the 37                                                                      |
| a test file with no level segment                                                | part 2                                                                                      |
| a file at a real owner and level whose remainder invents a segment               | part 3 — the one a two-part control misses                                                  |
| a file whose path owner differs from every `#[CoversClass]` owner, not on list B | what list B exists to bound                                                                 |
| a file whose remainder drops an interior segment, not on list C                  | what list C exists to bound — the shape a reader scanning the tree does not notice          |
| a file with no `#[CoversClass]` added beyond ceiling 84                          | list A is capped                                                                            |
| a row of any of the three lists deleted while its file still needs it            | a missing row is refused                                                                    |
| a row of any of the three lists kept after its file stopped needing it           | a stale row is refused just as loudly — asserted in prose by two drafts, planted by neither |

---

## P6 — the two remaining named cases

**Files.** Derived, and the derivation is the point — the first draft narrated this set
and omitted both generator guards below.

One file moves — `tests/Analysis/Finding/Support/FindingFactory.php` to
`tests/Analysis/Policy/Baseline/Support/` — and two are renamed in place, to targets
named here because the map's `target` column does not cover them and a rename without a
stated target is not checkable:

| From                                                                                 | To                                             | Because it guards                |
| ------------------------------------------------------------------------------------ | ---------------------------------------------- | -------------------------------- |
| `tests/Analysis/Policy/Architecture/Integration/UnmatchedExcludeIntegrationTest.php` | `UnmatchedLayerExcludeIntegrationTest.php`     | `architecture.unmatched-exclude` |
| `tests/Analysis/Run/Integration/ExcludeBinding/UnmatchedExcludeIntegrationTest.php`  | `UnmatchedDiscoveryExcludeIntegrationTest.php` | `discovery.unmatched-exclude`    |

Running all three against every generator constant and against
`assertPathLiteralsResolve()`'s guarded list yields:

- **`P6_A_FINDING_TEST_PATHS`** names
  `tests/Analysis/Finding/Support/FindingFactory.php` and is guarded — the move breaks
  it loudly.
- **`P6_C_BASELINE_PATHS_SHA256`** is the digest of *every* file under
  `tests/Analysis/Policy/Baseline`, which is where the file lands. Measured: the digest
  goes from `6ec107b9...` to `818d94bd...`. It freezes a reviewed set, so the new value
  is recorded as a decision with the move as its reason, not refreshed as a checksum of
  convenience.

Also in the set: the 8 consumers of `FindingFactory` under
`tests/Analysis/Policy/Baseline/Unit/`.

`FromArrayKeyReader` and its two non-moving consumers are **not** here. They belong to
P3, which is the package that moves that file; an earlier draft held them back to this
one and thereby made P3's own `composer check:code` unachievable.

**These three files are the stage's only admitted exception** to "nothing moved that the
map does not name"; the stage's Definition of Done names them there too, so the two
documents agree rather than contradict.

**Definition of Done.** `composer check` green; suite counts unchanged, since nothing
here crosses a suite; the P6-C digest change is the only digest change and its reason is
written down.

---

## Test plan

No test is written or deleted, which is what makes the case count an oracle. What is
tested is the *controls*:

- P0's allowance guard and P5's invariant control are each proved by planted breakage,
  never by their own green.
- Per-suite counts are taken with the runner's own command plus `--list-tests` and
  compared to the package's row in
  [`measurement/stage-04/prediction.md`](measurement/stage-04/prediction.md). The total
  is not the check — it reconciles even when two suites are wrong in opposite
  directions.
- A stale namespace is caught by `TestNamespacesFollowTheirPathTest` and its allow-list,
  not by the path diff: PHPUnit discovers by file, so a file whose namespace no longer
  matches its path runs and misleads rather than failing.
- A reference to a moved class that is neither a path nor a namespace — a docblock
  `{@see}`, a `FQCN::method` literal — is caught by each move package's old-FQCN grep,
  because nothing else in the repository resolves it.
