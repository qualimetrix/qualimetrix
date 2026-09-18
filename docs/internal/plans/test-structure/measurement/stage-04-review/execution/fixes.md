# Stage 04 — execution review, fix round

Branch `x30-stage-04-subject-layout`, from `1338d5fa`. Fourteen findings across
two reviewers, worked by mechanism rather than by finding: several share one
cause and the cause is what was fixed.

Nothing is committed. The three exception lists in
`governance/TestSuiteHygiene/subject-path-exceptions.php` are **unchanged** —
`git status` never lists that file — and the six per-suite counts are unchanged
at **6705 / 383 / 152 / 1029 / 179 / 757**, measured by
`python3 scripts/phpunit-aggregate.py`.

---

## M1 — one owner/level rule, four implementations, two semantics

Closes **claude-08**, **codex-02**.

**The cause.** `parseOwnerFromTestPath()` in the generator returned the owner
before the *first* level segment. `TestSubjectPaths::judge()` requires the path
to name **exactly one**. The two are the same rule, and on a path naming two
levels the generator published a conforming, retained row while the control
refused the file outright.

**The cure.** The generator is aligned on the stricter, stated rule. A new
`testLevelSegments()` collects every level-naming *directory* segment (the
basename is dropped: a file called `UnitTest.php` is not a level), and
`parseOwnerFromTestPath()` answers only when there is exactly one. Two levels now
reach `failUnownedTestClass()`, which gained its own sentence for the case so the
refusal does not read "names no test level". The same one-line change was made to
`parse_owner()` in `move-oracle.py` and in `p0-oracle.py`, which are the third and
fourth copy: an oracle that parsed the lenient way would certify a row neither
the generator nor the control would publish.

**Rejected alternative.** Loosening `TestSubjectPaths::judge()` to first-match.
It would delete the only place the rule is stated as the invariant states it, and
`04-subject-layout.md` writes part 2 as "exactly one segment".

**Verification** — the probe on a two-level path, before and after:

```
$ php scripts/generate-modular-architecture-test-inventory.php \
    --classification-probe=tests/Analysis/Run/Unit/Integration/FooTest.php
Analysis/Run	permanent	Unit	tests/Analysis/Run/Unit/Integration/FooTest.php
exit=0
```

```
$ php scripts/generate-modular-architecture-test-inventory.php \
    --classification-probe=tests/Analysis/Run/Unit/Integration/FooTest.php
tests/Analysis/Run/Unit/Integration/FooTest.php names 2 of Unit, Integration, Functional,
and a test file names exactly one. A test class lives at
tests/{manifest owner}/{Unit|Integration|Functional}/...
exit=1
```

A one-level path still answers: `tests/Analysis/Run/Unit/Pipeline/FooTest.php` →
`Analysis/Run permanent Unit …`, exit 0. `composer architecture:check` exit 0
with the artifacts byte-identical.

---

## M2 — the suite-map reconciliation was bidirectional for 65 of 76 directories

Closes **claude-06**, **codex-03**.

**The cause.** `assertSuiteClassifierAgreesWithPhpunit()` walks the declared
`<directory>` entries forward and `testSuitePrefixTable()` backward. But
`currentSuite()` answered for eleven `Analysis/Evidence/*` directories through two
regexes sitting *above* the table walk. Those eleven were in no row, so the
backward half could not miss them: deleting one from `phpunit.xml.dist` left the
classifier still saying `Unit`, `validateInventory()`'s `current_suite === 'none'`
refusal never fired, and the tests silently stopped being run.

**The cure.** The two regex families are rows now — eight `…/Unit/` and three
`…/Integration/` — and `currentSuite()` is a pure table walk with no branch beside
it. Sole source is what makes the reconciliation symmetric; a family regular
enough to write as a pattern is regular enough to enumerate, and enumeration is
what the reverse direction can read. Both docblocks say so.

**Rejected alternative.** A second backward pass that asks which paths the regex
branches can classify. It keeps two sources and adds a third loop to reconcile
them; the table is the single source the existing docblock already claims it is.

**Verification** — plant: delete `<directory>tests/Analysis/Evidence/Cohesion/Unit</directory>`
from `phpunit.xml.dist`.

Before the fix, with the plant in place:

```
$ composer architecture:check
Checked modular-architecture governance: 955 declarations, 37 semantic-owner layers, …
Checked 926 artifacts, 120 fixture directories, 726 PHPUnit classes, and 9207 expanded cases.
exit=0
```

After the fix, same plant:

```
$ composer architecture:check
Suite classifier disagrees with the PHPUnit suite map:
  tests/Analysis/Evidence/Cohesion/Unit is suite Unit in currentSuite() but is not declared
  under that <testsuite> in phpunit.xml.dist
exit=1
```

Plant reverted; `composer architecture:check` exit 0, `git status` shows
`phpunit.xml.dist` unmodified.

---

## M3 — branches that died, or lied, when a neighbour changed

Closes **claude-03**, **claude-04**, **codex-05**.

### claude-03 — two unreachable disjuncts in `targetPath()`

**Population proof, the discipline P4 used.** The two disjuncts — the eight
`tests/Analysis/Evidence/{CodeSmell…Size}/` roots and
`tests/Infrastructure/Logging/Unit/` — prescribe retention. The arm above them
retains every `tests/**/*Test.php`, so they can only answer for a non-test-class
file under those prefixes:

```
$ git ls-files 'tests/Analysis/Evidence/CodeSmell' … 'tests/Analysis/Evidence/Size' \
    | grep -vc 'Test\.php$'
0        # out of 119 files
$ git ls-files 'tests/Infrastructure/Logging/Unit' | grep -vc 'Test\.php$'
0        # out of 5
```

**The cure.** Both removed, with a comment saying what stood there and why the
general rule below is also the right answer for the file that would arrive next:
a support class under one of those roots belongs at `{owner}/Support/`, and a
retaining prefix would have held it where it fell. The three surviving disjuncts
(`P7_MEASUREMENT_PATHS`, `ComputedMetrics/`, `P6_D_PRIORITIZATION_TEST_PATHS`)
have live populations and stay.

**The asymmetry a reader would otherwise notice.** `classifyOwner()` carries the
*same* eight-root regex, and the same measurement says its population is empty
too. It **stays**, with a comment saying why: it is not redundant with anything.
For the first fixture or support file filed under one of those roots it is the
only branch that names an owner, and the owner it names is right; deleting it
would turn that file into an unclassified refusal for a question the manifest
already answers. The `targetPath()` pair was redundant *and* wrong; this one is
neither.

### claude-04 — a fixture prescribed into a root that is not a manifest owner

**The decision, named.** The owner vocabulary is the manifest's, for fixtures
exactly as for test classes. `Reporting/Sarif` is not one of the 37 owners, and
the inventory was prescribing a move into `tests/Reporting/Sarif/` — a root the
stage's own invariant forbids a test class to sit in, reached through the one
kind the invariant does not judge. The rejected alternative was to declare
fixtures a separate population with a vocabulary of their own; it is what the
code already did by accident, and it is how a wrong owner survives — the reader
has no way to tell a deliberate second vocabulary from a leftover.

**The cure.** `tests/Fixtures/Schema/` is owned by `Reporting`, so the target is
`tests/Reporting/Fixtures/Schema/sarif-2.1.0.schema.json`. And the code now
*states* the rule instead of leaving it to be inferred:
`assertTestOwnersAreManifestOwners()` refuses any row whose current or target
path lies under `tests/` and whose `subject_owner` is not a manifest owner.
Tooling roots are outside the rule by position, not by exemption — neither of
their paths is under `tests/`. Two names are allowed, each with its reason and
its **row count**, in `NON_MANIFEST_TEST_OWNERS`: `Reporting/HtmlTemplate` (10)
and `TestSupport/Logging` (1). The count makes the allowance closed in both
directions — a third name, an eleventh row, or the loss of one all refuse here.

**Artifact diff:** exactly one row in `test-ownership.tsv` and one in
`test-fixture-directories.tsv` (plus the `tests/Fixtures` summary row). Topology
counts unchanged: 926 / 120 / 726 / 9207. The row stays inside M5's fourteen —
its target moved, it is still a pending move labelled `permanent`.

**Verification** — plant: put `Reporting/Sarif` back.

```
$ composer architecture:check
A test artifact's owner is a manifest owner, and these are not:
  Reporting/Sarif is not one of the 37 manifest owners, and 1 row(s) under tests/ publish it:
  tests/Fixtures/Schema/sarif-2.1.0.schema.json
Either file the artifact under the owner that owns it, or name the exception in
NON_MANIFEST_TEST_OWNERS with the reason and the row count.
exit=1
```

Second plant: the allowance claims 9 HtmlTemplate rows.

```
$ composer architecture:check
A test artifact's owner is a manifest owner, and these are not:
  Reporting/HtmlTemplate is allowed here for 9 row(s) and the tree now has 10 (the JS bundle
  tests and configs under src/Reporting/Template/. …)
exit=1
```

Both reverted; `composer architecture:check` exit 0.

### codex-05 — `--classification-probe` answered for a path it was not given

**The cure.** The probe derives the kind instead of hardcoding
`'phpunit-test-class'`: `isTestClassPath($path) ? 'phpunit-test-class' :
classifyKind($path, [])`. The proxy is the generator's own answer for the
pre-discovery case — `isTestClassPath()`'s docblock says so — and it is needed
because a probe has no PHPUnit discovery, so `classifyKind()` alone would read a
`Support/…Test.php` as support. `$targetSuite` now follows the same `'none'`
rule the main pass uses for non-test-class kinds.

**The proxy is the basename, and the first attempt got that wrong.** Using
`isTestClassPath()` looked right — its docblock is written for exactly the
pre-discovery case — but it is `tests/`-scoped, because it answers the *owner
parse*. A test class outside that root falls to `classifyKind()`, whose support
branch excludes `*Test.php` by name, and is refused as an unclassified kind. The
two probes that regressed are the ones AGENTS.md's "an unregistered governance
group reddens `architecture:check` by name" claim is checked with. Caught by
review, not by the first round of verification, which tested only `tests/` paths.

**Rejected alternative.** Printing the kind as a fifth column. It would make the
probe self-explaining, and it would silently stale every `pN-report.md` that
quotes a four-column line. Four columns kept.

**Verification** — the support path the finding names, against its real row
(line 616 of `test-ownership.tsv`):

```
before: Analysis/Policy/Baseline	P6-C	none	tests/Analysis/Policy/Baseline/none/FixedClock.php
after:  Analysis/Policy/Baseline	P6-C	none	tests/Analysis/Policy/Baseline/Support/FixedClock.php
row:    …	Analysis/Policy/Baseline	tests/Analysis/Policy/Baseline/Support/FixedClock.php	P6-C	"Retain…"
```

A fixture answers for itself too: `tests/Fixtures/Schema/sarif-2.1.0.schema.json`
→ `Reporting permanent none tests/Reporting/Fixtures/Schema/…`. And the two
non-`tests/` probes answer exactly as the pre-change generator did, checked
side by side against a stashed copy:

```
governance/Other/ProbeTest.php         Architecture.Governance	P8	none	governance/Other/ProbeTest.php
tools/phpstan/tests/Unit/FooTest.php   Tooling/PhpStan	P8	Tooling	tools/phpstan/tests/Unit/FooTest.php
```

---

## M4 — exception lists with escape hatches their definitions do not mention

Closes **claude-01**, **claude-02**, and — not named in any mechanism but among
the fourteen — **claude-05**.

### claude-01 — list C accepted a case its own definition excludes

**Measured first:** 0 of 759 `#[CoversClass]` claims in the tree fail to resolve
through the manifest, so the defect is latent, not live.

**The decision.** The *implementation* was wrong, not the definition. List C says
"the path owner is among the covered owners"; a file whose every claim resolved
to nothing had no covered owners at all and landed there anyway, under a detail
reading `(nothing the manifest declares)` and a refusal telling the reader to
rename a directory — which would not have cured it. And list C's ceiling is 4
with all four slots used, so the first such file was going to be red immediately,
with the wrong sentence.

**The cure.** An unresolvable claim is not a verdict: `judge()` throws, on
**any** unresolved claim rather than only when every claim is one — otherwise the
partial case keeps silently dropping a claim. The throw is the same shape as
`judge()`'s two existing throws (a path outside `tests/`, an owner that is not a
prefix of the class it owns): an input the rule cannot be asked about, not an
exception the tree may carry under a ceiling. Under `tests/` a `#[CoversClass]`
names production code, and production code is what the manifest declares.
Removing the case also removed the now-unreachable `(nothing the manifest
declares)` branch, so list C's entry condition is true by construction.

**Rejected alternatives.** (a) A fourth verdict in `SubjectPathExceptions::UNJUDGEABLE`
with its own `#[Test]` refusal — the natural shape, and it costs one Governance
case, which the DoD pins at 757; folding the assertion into a neighbour under a
broadened or renamed method either makes the method's name untrue or silently
stales `p5-report.md`, which quotes the name. (b) Routing the case to list A —
list A is "declares no coverage attribute", and this file does declare one, so
the definition would then be the wrong half.

**Verification** — plant: `#[CoversClass(\Qualimetrix\Nobody\Stranger::class)]`
on `tests/Core/Unit/VersionTest.php`.

Before the fix, the file lands on list C with the misleading cure:

```
1) …TestPathsNameTheirSubjectTest::itFindsNoPathDisagreeingWithItsSubjectThatTheListDoesNotCarry
1 test file(s) belong on remainder_is_not_a_prefix and are not on it.
… Rename the directory to the subject's own, or file the test flat.
tests/Core/Unit/VersionTest.php (the level itself) against (nothing the manifest declares)
```

After the fix, eight of the nine cases refuse with the reason (the ninth is the
probe case, which by design never touches the corpus):

```
LogicException: tests/Core/Unit/VersionTest.php claims to cover Qualimetrix\Nobody\Stranger,
which the manifest does not declare. Under tests/ a #[CoversClass] names production code and
production code is what the manifest declares, so this is a stale name, a typo, or a class
that belongs in src/ — none of which part 3 can be asked about.
exit=2
```

Plant reverted. The existing probe that pinned the old behaviour
(`Qualimetrix\Nobody\Stranger` → `not-a-prefix`) is rewritten to assert the
refusal, and a second probe covers the partial case — one resolvable claim beside
one that is not. Suite: 31 tests (unchanged), 1131 assertions (was 1128).

### claude-02 — a file leaves list B by gaining an annotation, and the ceiling drops for good

**Is it intended? Yes — and the measurement is what settles it.** 11 files today
carry verdict `exact` or `prefix` while covering their own owner *and* somebody
else's: a wiring test naming the container and the thing wired, a threshold test
naming the four metrics it thresholds. Requiring all claims, or a majority, to
name the path owner would refuse all 11; they would land on `covers_another_owner`
(ceiling 19, exactly 19 members) or `remainder_is_not_a_prefix` (ceiling 4,
exactly 4), so the stricter rule is red on its first run. "At least one" is
load-bearing.

**The cure is therefore in the record, not in the rule.** `TestSubjectPaths`'s
docblock now states the consequence rather than leaving it to be discovered: a
member leaves list B by *gaining* a claim on its own owner as legitimately as by
being refiled, the derive then lowers that ceiling for good, that is the intended
exit — a test that starts covering what it is filed under has stopped being the
exception — and a claim written to silence the control rather than to state what
the test does is a lie in the test, which no path rule can see and review can.
`SubjectPathExceptions`'s list-B bullet says the same and adds that the census
stage 05 adjudicates is the one this stage recorded, not the live count.

**Rejected alternative.** Counting list B by "how many claims lie outside the
owner" instead of by verdict. It would survive the annotation, and it would put
the list on a different axis from the other two, which are verdict-keyed — the
derive, the ceiling and both refusals all read verdicts.

**No guard planted:** this finding was answered by a decision and a measurement,
not by a new refusal. The measurement is reproducible from
`TestSubjectPaths::judge()` over the population; it is quoted above.

### claude-05 — the population's declared mechanism was not its actual one

**The cure.** The docblock said support and fixture files are excluded because
they carry no level segment; the actual mechanism is the `*Test.php` filename
predicate on disk, and four files under a `Support/` segment *are* judged and do
pass — because they are test classes and `Support` there is a segment of the
subject. The docblock now says that, names what the pattern cannot see (a test
class whose file is not named `*Test.php`), and points at the two places that do
assert it over the other population: `validateInventory()` and
`TestFilesAreExecutedTest`.

The floor rose from `assertGreaterThan(500, …)` to `600` against a population of
616: at 500 a sixth of the tree could stop being scanned without a word. Lowering
it is a hand edit, which is the admission. The boundary itself is not asserted
here and the docblock says where it is — widening this control to PHPUnit's own
discovery is a second corpus and a different claim.

---

## M5 — `closure_package` is incoherent, and the plan said something weaker

Closes **codex-01**. **The column is not touched**, by instruction and on the
merits.

**Measured at HEAD, both halves this time:**

| predicate                                                            | rows                                                                                                                                                    |
| -------------------------------------------------------------------- | ------------------------------------------------------------------------------------------------------------------------------------------------------: |
| settled (`target_path == current_path`) and carrying a package label | **277** — 156 outside `tests/` (132 `governance/`, 18 `scripts/`, 6 `tools/`), 121 under it; by label P3 4, P4 57, P5 1, P6-C 25, P6-D 1, P7 10, P8 179 |
| pending (`target_path != current_path`) and labelled `permanent`     | **14**                                                                                                                                                  |
| pending and labelled `P3`                                            | 19                                                                                                                                                      |

The 277 refute "which package still owes a move". The 14 refute "which package
settled it, `permanent` meaning none was needed" — a row cannot mean no move was
needed while prescribing one. Only the 19 survive either reading. The column is
**incoherent, not ambiguous**, and the paragraph in `04-packages.md` is rewritten
to say so, including that it was written after measuring the first half only,
which is this stage's own recurring defect. Two stale numbers in the same
paragraph were re-measured rather than carried forward: it said "151 of them
governance and tooling roots", a figure measured when the total was 272 and
never re-taken; it is 156, and the paragraph now says both the old number and
why it was wrong.

**The 14 are recorded as an owner's question**, in a block quote in the same
section: ten JS artifacts under `src/Reporting/Template/`, three
`tests/Fixtures/Ast/` fixtures and one `tests/Fixtures/Schema/` fixture, all
predating this stage, none of them named to any package. Deriving the column
would relabel them as owing a move to a package that does not exist — turning a
question into an assertion. Ten of the fourteen also target
`tests/Reporting/HtmlTemplate/`, which is why they appear again in
`NON_MANIFEST_TEST_OWNERS` under M3.

One further text defect corrected in the same pass: "the column is read by
nothing but the artifact" was false. `move-oracle.py` arm 3 requires `permanent`
on a moved test class, `p0-oracle.py` expects the same value, and
`inventorySummary()` publishes `closure_package_counts` — all three under the
second reading, so no consumer breaks either way, but the column is read.

**Verification.** `awk` over the regenerated
`docs/internal/generated/modular-architecture/test-ownership.tsv`; the three
predicates above reproduce the three counts. No guard: the finding is about text.

---

## M6 — two sweep channels executed by no tracked instrument

Closes **claude-07**, **codex-06**, **codex-04**.

### claude-07 (first half) — the nine dangling names are pinned

**The cure.** `dangling-test-names.py` carries `KNOWN`, the nine names with a
reason each: four `P6_RENAMED_TEST_IDS` halves, one namespace a refusal control
plants on purpose, four stale references older than this stage. Exit codes are
now 0 when the tree carries exactly the pinned nine, 1 when it carries a name
nobody pinned (printed under its own `NEW` heading), 2 when an input cannot be
read, and 3 when a pinned name has stopped dangling (`GONE` — the repository's
stale-declaration shape: a pin describing nothing hides the next name that would
need one). A filtered run (`--names-like`, `--include-history`) is a query over
another population and says `filtered run: the pinned census was not compared`
instead of judging.

**What changed about what it counts: nothing.** It still reports the same nine
names across the same nine carriers. What changed is the exit code on a clean
tree, 1 → 0, and that is the point — a tenth name used to be a number one greater
than a number in a report.

**Verification** — three plants.

```
$ # a tenth name, appended to a tracked governance file
10 name(s) across 10 carrier(s)
NEW  Qualimetrix\Tests\Nobody\NewStrayTest — not in the pinned census; adjudicate it,
     do not pin it to make this quiet
exit=1
```

```
$ # a pinned name removed from its only carrier
8 name(s) across 8 carrier(s)
GONE Qualimetrix\Tests\Integration\Configuration\YamlKeyReachabilityTest — pinned as
     a stale reference from an epoch before stage 04 …, and no longer dangles; drop the pin
exit=3
```

Clean tree: `9 name(s), exactly the pinned census`, exit 0. All plants reverted.

**Pinned is not guarded, and the docstring now says so.** Nothing in
`composer check` runs this script, so the census can rot the way
`P6_RENAMED_TEST_IDS` did. Making it a `composer check` step would mean deciding
the nine first, which is stage 05's; the alternative to saying this is a reader
who takes "pinned" for "enforced".

### claude-07 (second half) — the two hand-run sweep channels

**Path literals: closed.** `move-oracle.py` gains arm 6b, beside the old-FQCN
sweep it belongs with: every row's pre-move path literal, over the same corpus
(plans and ADRs already excluded as history). Measured over P3's eleven rows at
the end of the stage it returns **0**, so it is a floor being nailed down rather
than a backlog opened, and it reaches a workflow file or a `.gitattributes` line
that no sweep over class names touches.

Verification — plant a path literal into `README.md`:

```
  README.md:115: still names the path
  tests/Analysis/Finding/RuleConfiguration/Support/FromArrayKeyReader.php, which this
  package emptied
```

**Short names: deliberately not closed, on a measurement.** A *move* keeps the
class name, so a bare-basename sweep over a move package's rows matches every
legitimate reference. Measured over the same eleven P3 rows, excluding the moved
file itself: **206 hits**. As an arm of a move judge that is 206 lines of noise
and a derived allow-list nobody would read. The channel belongs to *renames* —
`{@see UnmatchedExcludeIntegrationTest}`, written without its namespace — and no
instrument in this campaign reaches it. `04-packages.md` now says that in the
sweep section rather than leaving it to be discovered.

### codex-06 — a stale class name that is also a live namespace prefix

**Closed rather than documented, because the close is cheap.** The exculpation
"nothing dangles when something is declared beneath it" is right for a namespace
mention and wrong for a name written the way a class is written. The detector now
keeps a match that is followed by `::` or preceded by `new`, `extends`,
`implements`, `instanceof` or `use`, however many namespaces live beneath it. The
docstring says what remains invisible: a stale name of that shape mentioned in
prose or inside a bare string.

**No false positives introduced:** the tree still reports exactly nine.

Verification — plant `Qualimetrix\Tests\Analysis\Evidence\Size::class`, a live
namespace prefix written as a class:

```
before: 9 name(s) across 9 carrier(s)          # exculpated, invisible
after:  10 name(s) across 10 carrier(s)
        NEW  Qualimetrix\Tests\Analysis\Evidence\Size — not in the pinned census …
```

### codex-04 — the stray-move arm only saw git's renames

**The cure.** The same question is now asked of the tracked file sets as well:
`git ls-tree -r --name-only <base>` against the working tree's tracked set, with
the package's own `current`/`target` paths removed from both sides, and the
remaining deletions paired to the remaining additions **by basename**. That is
precisely the shape `-M` cannot see — a file moved *and* edited past the
similarity threshold arrives as a delete plus an add.

**Rejected alternative.** Requiring every path in `base − now` to be a row's
`current` and every path in `now − base` to be a row's `target`. Measured on the
three move packages' own commits, each adds files legitimately (P1 one, P2 one,
P3 two), so the strict form reports the work the package was asked to do; an arm
that prints legitimate work is an arm nobody reads. The docstring states what
stays invisible either way: a relocation that also renames the file.

**Verification** — plant: `tests/Analysis/Evidence/Size/Unit/LocCollectorTest.php`
moved to `…/Unit/Deep/` and its body rewritten. git's own view first:

```
$ git diff --name-status -M ef090d27 | grep LocCollectorTest
A	tests/Analysis/Evidence/Size/Unit/Deep/LocCollectorTest.php
D	tests/Analysis/Evidence/Size/Unit/LocCollectorTest.php
```

Before the fix, the oracle reports the three P6 exceptions and nothing else —
3 disagreements, the plant invisible. After:

```
5 disagreement(s):
  …
  tests/Analysis/Evidence/Size/Unit/LocCollectorTest.php ->
  tests/Analysis/Evidence/Size/Unit/Deep/LocCollectorTest.php: a move the map does not name.
  git reports it as a delete and an add, so rename detection does not see it
```

Plant reverted; `git status` clean for both paths.

Note for whoever runs the oracle next: at HEAD it reports 4 disagreements for
P3, all four naming P6's three admitted exceptions (the `FindingFactory` move
once per arm, and the two `UnmatchedExcludeIntegrationTest` renames). That is the
oracle judging a tree three packages later than the one it was written for, not a
regression.

---

## Disposition of all fourteen findings

| id        | disposition                                                                                                                                                                                                                                                                                    |
| --------- | ---------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| claude-01 | **fixed** (M4) — an unresolvable `#[CoversClass]` is refused by `judge()` instead of taking a slot on list C; the probe that pinned the old behaviour is rewritten and a partial case added                                                                                                    |
| claude-02 | **fixed as a decision** (M4) — the exit is intended and is now stated in both docblocks; the stricter rule is refuted by measurement (11 legitimately mixed files; two ceilings overflow on the first run)                                                                                     |
| claude-03 | **fixed** (M3) — both unreachable disjuncts removed over a population proof; the same regex in `classifyOwner()` kept, with the reason written where a reader would otherwise see an asymmetry                                                                                                 |
| claude-04 | **fixed** (M3) — one owner vocabulary; `Reporting/Sarif` → `Reporting`, and `assertTestOwnersAreManifestOwners()` now refuses a non-manifest owner under `tests/` with a closed, counted allowance                                                                                             |
| claude-05 | **fixed** (M4) — the docblock states the actual mechanism and what it cannot see; the population floor raised 500 → 600 against 616                                                                                                                                                            |
| claude-06 | **fixed** (M2) — the eleven regex-classified directories are table rows; `currentSuite()` has no branch beside the table                                                                                                                                                                       |
| claude-07 | **fixed** (M6) — the nine are pinned with reasons and three exit codes; the path-literal sweep is now arm 6b of `move-oracle.py`. The short-name channel is **explicitly rejected as a move-judge arm** on a measurement (206 hits over 11 rows) and recorded as uncovered in `04-packages.md` |
| claude-08 | **fixed** (M1) — all four copies answer "exactly one level segment"; the generator refuses where it used to publish                                                                                                                                                                            |
| codex-01  | **fixed as text** (M5) — the paragraph now says incoherent, not ambiguous, with both halves measured; the 14 rows are recorded as an owner's question; the column is untouched                                                                                                                 |
| codex-02  | **fixed** (M1) — same cure as claude-08                                                                                                                                                                                                                                                        |
| codex-03  | **fixed** (M2) — same cure as claude-06                                                                                                                                                                                                                                                        |
| codex-04  | **fixed** (M6) — tracked file sets compared as well as rename detection, paired by basename; the strict set-difference form rejected on a measurement of the three packages' own commits                                                                                                       |
| codex-05  | **fixed** (M3) — the probe derives the kind; the fifth-column variant rejected because it would stale the `pN-report.md` quotes                                                                                                                                                                |
| codex-06  | **fixed** (M6) — class-shaped usage defeats the namespace-prefix exculpation; what remains invisible is in the docstring                                                                                                                                                                       |

No finding was refuted by measurement. Two *cures* were: the stricter part-3 rule
(claude-02) and the strict set-difference stray-move arm (codex-04), each rejected
with the number that refutes it.

---

## Commands run

```
composer architecture:check                      # exit 0
composer phpstan                                 # exit 0, 1846 files
composer cs-check                                # exit 0
python3 …/stage-04/dangling-test-names.py        # exit 0, nine, exactly the pinned census
python3 …/stage-04/move-oracle.py --package=P3 --base=ef090d27
composer check                                   # exit 0, run last, tree otherwise quiet
```

`composer check`, the aggregate that is the evidence:

```
===== PHPUnit suite: Unit (exit 0)           =====  OK (6705 tests, 16633 assertions)
===== PHPUnit suite: Integration (exit 0)    =====  OK (383 tests, 2199 assertions)
===== PHPUnit suite: Functional (exit 0)     =====  OK (152 tests, 486 assertions)
===== PHPUnit suite: Infrastructure (exit 0) =====  Tests: 1029, Assertions: 3529, Skipped: 1
===== PHPUnit suite: Tooling (exit 0)        =====  OK (179 tests, 39112 assertions)
===== PHPUnit suite: Governance (exit 0)     =====  OK (757 tests, 15538 assertions)
📚 Building documentation with --strict (website/.venv/bin/mkdocs)...
Checked modular-architecture governance: 955 declarations, 37 semantic-owner layers,
0 seams, 73 exact internal grants -> 13 coarse edges.
Checked 926 artifacts, 120 fixture directories, 726 PHPUnit classes, and 9207 expanded cases.
exit=0
```

All six pinned counts hold. `git status --porcelain` lists ten modified files —
the generator, three governance files, three stage-04 measurement scripts,
`04-packages.md` and the two regenerated TSVs — plus this untracked review
directory, and nothing else.
