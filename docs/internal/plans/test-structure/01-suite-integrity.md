# Stage 01 — the suite runs what it contains

This stage ships the instruments the rest of the plan is verified with, and
fixes the one defect that proves they are needed. Nothing else touches the tree
until this is green in CI.

## The defect that motivates the stage

`tests/Analysis/Evidence/Design/Unit/TypeCoverage/TypeCoverageRuleTest.php:143`
— `itAliasesItsOwnTwoBoundariesOnly` carries neither `#[Test]` nor
`#[DataProvider]`, while 10 of its 11 sibling `it*` methods carry `#[Test]` and 8 carry `#[DataProvider]`. PHPUnit never calls
it. The CLI-alias contract of the three type-coverage rules is asserted by
nothing, and has been since the method was written.

**Fix it before adding the guard, and run it alone first.** It has never
executed: it may be red. Red means either a stale test or a real defect in the
alias contract, and which one decides whether this stage also carries a product
fix. Do not assume green.

## D6 was adopted and is withdrawn

The previous draft replaced `phpunit.xml.dist`'s 52 enumerated test directories with
depth globs, on the strength of one measurement: a glob set plus transitional
entries reproduced the suite exactly (679 classes, 9098 methods). **That
measurement was too narrow and the decision was wrong.** It merged every glob
into a single suite, which is precisely the shape that hides the two costs:

- **Suite partitioning breaks.** `tests/*/*/Unit` matches
  `tests/Infrastructure/Console/Unit`, which the `Infrastructure` suite already
  claims as part of its whole root — 404 tests land in two suites. PHPUnit
  refuses (`Cannot add file … as it was already added to test suite`), and
  `scripts/phpunit-aggregate.py`'s partition assertion refuses earlier still.
  `--list-tests` does not show this: PHPUnit deduplicates the file, the count is
  unchanged, only suite membership moves.
- **`architecture:check` reddens.**
  `scripts/generate-modular-architecture-test-inventory.php` holds a *second*
  copy of the suite map — `testSuitePrefixTable()`, 42 prefix literals plus two regexes — and reconciles
  it with the config in both directions, probing each declared prefix and
  requiring each table prefix to be declared literally. A glob satisfies
  neither. This was invisible to `pinned-paths-impact.txt`, which counts
  `'tests/…'` literals: the classifier is not a path.

So globs are not a config edit; they are a rewrite of how two tools agree on the
suite map, with a suite redesign attached.

**And the problem they were introduced to solve does not exist.** Round one
established that the orphan check catches a file moved into an unlisted
directory — the file *is* there, and G2 reddens. The enumeration therefore stays,
each stage registers the directories it creates, and G2 is what makes a missed
registration loud. A cheaper change that was never needed is not a bargain.

Recorded here rather than deleted because the error is the plan's own subject
matter: a claim about a set, accepted from a measurement that did not cover the
set.

## Three guards, and what each refuses

### G1 — every test method is reachable

Refuses a method named `itXxx` without `#[Test]`, and a method with `#[Test]`
whose name is not `itXxx` (CLAUDE.md §9, both directions).

```
for each *Test.php:
    for each public function:
        refuse when (name matches ^it[A-Z]) xor (#[Test] in its attribute block)
        # ... implementation details
```

Measured today: one violation in the first direction (the defect above), zero in
the second.

### G2 — every test file runs, and the count is stated

Refuses a `*Test.php` reachable by no suite. This is the guard the whole plan
leans on: with the enumeration kept, every stage that creates a directory must
register it, and G2 is what makes a forgotten registration loud instead of
silent. It is also the X13 class — 110 tests once sat unexecuted for three runs
under a green `composer check`.

```
executed = tests PHPUnit actually lists for the configured suites
on_disk   = every *Test.php under the covered roots
refuse when on_disk \ executed is non-empty
```

**Take the executed set from PHPUnit's own `--list-tests`, not from a
reimplementation of its matching rules.** An earlier draft specified G2 as a
directory-coverage check and justified it with a claim that is false — that a
file moved into an unlisted directory escapes an orphan check "because that file
is then not there yet". It is there, and the orphan check catches it. The value
of G2 is the executed set, not a directory inventory.

### G3 — namespace agrees with path

```
expected = psr4_prefix + relative_dir with / -> \
refuse when declared_namespace != expected
```

**This is a migration, not a side effect.** The tree currently carries 60
`*Test.php` files whose namespace does not match their path (139 counting
fixtures). The previous draft claimed G3 would close these "as a side effect" of
stage 04 — it will not; they are unrelated to the 96 relocated files. G3 is
therefore delivered in two steps: the check first, with the existing violations
recorded as an explicit allow-list, and the allow-list emptied as its own piece
of work. Shipping G3 red is not an option; shipping it with a silent exemption
for 60 files would be a lie.

### What the guards do not catch — the fourth axis

`scripts/phpunit-aggregate.py:35-36` passes `--exclude-group=benchmark` and
`--exclude-group=live-freshness`. Two methods carry `live-freshness`
(`SuppressionSnapshotFreshnessTest:23`, `ModularArchitectureGovernanceIntegrationTest:19`):
they sit in listed directories, carry `#[Test]`, are named `itXxx`, have correct
namespaces — and do not run under `composer check`. No guard here sees that, so
the stage title is narrower than it sounds. Either G2 grows a fourth refusal for
groups excluded by the aggregate, or the limitation is written into the guards'
own docblocks. Decide at execution; do not leave it unstated.

(`--exclude-group=benchmark` matches nothing: zero methods carry that group.)

## The registration checklist

**A new test directory has four addresses in this repository, not one.** The
previous draft named only `phpunit.xml.dist`, and two of the other three fail
hard rather than quietly. Stage 04 alone creates 61 directories, so this is the
plan's most repeated step and it lives here, once; stages 02–04 reference it
rather than listing it from memory.

The four-address list below is for a *directory*. A new *root* costs more, and
the canonical list of those addresses lives in AGENTS.md, which is what the next
agent to register one will read.

For every directory created under `tests/`, the controls root, or a tool's
`tests/`:

1. **`phpunit.xml.dist`** — a `<directory>` entry under the right `<testsuite>`.
2. **`scripts/generate-modular-architecture-test-inventory.php`,
   `testSuitePrefixTable()` and `currentSuite()`** — the generator holds a second
   copy of the suite map and reconciles it with the config in both directions.
   A directory declared only in the config classifies as suite `none`, and
   `validateInventory()` fails with "add its directory to a `<testsuite>` **and
   to the matching branch of `currentSuite()`**".
3. **`classifyOwner()` in the same generator** — an unrecognised path shape ends
   in `fail('Unclassified test artifact')`. This is fatal, not a warning, and it
   already fires today for `tests/PromiseEffect/Unit/FloorTest.php`, a target
   this plan's own map proposes.
4. **The generator's inventory scope**, `git ls-files -- tests scripts/tests
   src/Reporting/Template/tests …` — anything outside those roots is simply not
   inventoried. The controls root and every `scripts/**/tests/` destination lie
   outside it, so ~49 files would drop out of the census **with
   `architecture:check` still green**. That is the ownerless-files failure this
   project has already paid for, and no DoD in stages 02–04 catches it: they
   check G2, G3, the aggregate's suites and pinned paths, but not this scope.

Then, for a new **root** (not for each directory): every address in the root
registration table in AGENTS.md, re-derived against the tree rather than read
from any list — including this plan's execution record, which reports one pass
and not the standing rule.

**A guard already exists for step 2 and the plan did not know it.** A test in an
unregistered directory reddens `architecture:check` today, through
`validateInventory()`. G2 is still worth having — it names the file rather than
the classification — but the tree is better defended than the plan assumed.

## Definition of Done

- `TypeCoverageRuleTest::itAliasesItsOwnTwoBoundariesOnly` executes; its verdict
  is written into the stage report.
- The four `tests/Infrastructure/Logging/*Test.php` files, which sit under no
  level directory and run only because the `Infrastructure` suite includes its
  root wholesale, are moved into `Infrastructure/Logging/Unit/` and registered.
  They are the one place where the current config's shape hides a layout defect.
- G1, G2, G3 exist and are reachable from a composer script the aggregate calls.
- **Each guard is proved to bite**: plant one breakage per guard, record that it
  reddens for its own case and only for it, and that it is green on the clean
  tree. A guard seen only on the broken tree is verified on half its range.
- The executed-test count is recorded here as the baseline every later stage
  compares against.
- `composer check` green.

## Files

`tests/Analysis/Evidence/Design/Unit/TypeCoverage/TypeCoverageRuleTest.php`,
the four `tests/Infrastructure/Logging/*Test.php` files, the new guard files,
`phpunit.xml.dist`, `scripts/phpunit-aggregate.py`,
`scripts/generate-modular-architecture-test-inventory.php`, `phpstan.neon`,
`.php-cs-fixer.dist.php`, `composer.json`, and — if the alias verdict is red —
the owning rule under `src/Analysis/Evidence/Design/`.

**One decision this stage must make explicitly:** the generator's
`EXPLICIT_PATH_DISPOSITIONS` records an intention to move the Logging tests to
`Infrastructure/Unit`, while this plan sends them to
`Infrastructure/Logging/Unit/` — which already exists and holds
`LoggerFactoryTest`. The plan's target is the better one by subject, but it
overrides a recorded intention, so say so rather than letting the regenerated
artifact quietly disagree with the file.

## Where these guards live

They are repository controls by the taxonomy in
[`measurement/controls-taxonomy.md`](measurement/controls-taxonomy.md), so they
belong in the controls root that stage 02 creates. **That is a cycle, and it is
resolved here rather than "at execution": stage 01 creates the root and performs
the full registration table from stage 02 — PHPUnit, PHPStan, cs-fixer,
`autoload-dev`, the aggregate's suite tuple, the composer group.** Stage 02 then
only moves files into a root that already works. A stage that leaves the root
half-registered would be green by its own DoD and would break the next one.

---

## Execution record

Landed on `test-structure-01` in four commits. Every number below was measured
on this tree, not carried over from the plan.

### The alias verdict

`TypeCoverageRuleTest::itAliasesItsOwnTwoBoundariesOnly` **passes on its first
execution.** The contract was sound; the stage carries no product fix. Unit
grows by the provider's three cases, 7575 → 7578.

### Baseline for every later stage

| Suite          | Tests |
| -------------- | ----- |
| Unit           | 7578  |
| Integration    | 712   |
| Functional     | 207   |
| Infrastructure | 683   |
| Governance     | 13    |

691 PHPUnit classes, 9195 expanded cases in the inventory; the aggregate's 9193
is that number less the two cases it excludes by group.

### What registering this root actually cost

The checklist above named four and, for a new root, four more. This is what
*this* pass measured when it registered `governance/` — a record of one
execution, not the standing list. The standing list is the root registration
table in AGENTS.md, and it is longer than this one: it also names
`ModularArchitectureGovernanceIntegrationTest::createIsolatedProject()`,
`.dockerignore` and `scripts/init-environment.sh`.

| Address                                                                                                                                                     | Fails        |
| ----------------------------------------------------------------------------------------------------------------------------------------------------------- | ------------ |
| `phpunit.xml.dist`                                                                                                                                          | loudly       |
| `scripts/phpunit-aggregate.py` — `SUITES`, the partition proof, the `--jobs` bound                                                                          | loudly       |
| `tests/System/TestRunnerConfiguration/Tests/test_phpunit_aggregate.py` — a third copy of the suite tuple, run by `test:cross-tool` and not by the aggregate | loudly       |
| the inventory generator — scan scope, `testSuitePrefixTable()`, `currentSuite()`, `classifyOwner()`, `dispositionFor()`, `targetPath()`                     | loudly       |
| `phpstan.neon`, `.php-cs-fixer.dist.php`, `composer.json`                                                                                                   | loudly       |
| `ScratchPathsCarryRealEntropyTest::ROOTS`                                                                                                                   | **silently** |
| `.gitattributes` — the root would ship in the dist package                                                                                                  | **silently** |
| `.githooks/pre-commit` — its PHP would skip the local hook                                                                                                  | **silently** |
| `generate-modular-architecture-production-inventory.php` — the ban on importing a development namespace read `Qualimetrix\Tests\` only                      | **silently** |
| `generate-rename-enumeration.php` — `surfaces()`                                                                                                            | **silently** |

`surfaces()` keeps one surface over both roots, so stage 02's move of the
`Channel/` controls will not read as a drop in a column nobody re-derives.

### The fourth axis

G2 grew the fourth refusal rather than a docblock sentence. It measures the
excluded set by effect — two `--list-tests` per suite, with and without the
aggregate's `--exclude-group` arguments — and requires it to equal a list the
guard names. Two cases are named today. `--exclude-group=benchmark` matches
nothing, and that is now a fact the guard would refuse rather than a claim in
prose.

### The guards bite

Each planted through the aggregate, the entry point `check:code` uses, not by
calling the guard.

| Refusal                          | Planted                                          | Red                            | Green                                                 |
| -------------------------------- | ------------------------------------------------ | ------------------------------ | ----------------------------------------------------- |
| G1, `itXxx` without `#[Test]`    | attribute removed from a Unit test               | Governance only                | the other four suites; Unit visibly drops 7578 → 7577 |
| G1, `#[Test]` under another name | method renamed off `itXxx`                       | Governance only                | the other four suites                                 |
| G2, a file no suite reaches      | a test file in an unregistered directory         | Governance **and Integration** | Unit, Functional, Infrastructure                      |
| G2, an unaccounted exclusion     | a fourth `--exclude-group` in the aggregate      | Governance only                | the rest                                              |
| G2, an undeclared excluded case  | a third method given `live-freshness`            | Governance only                | the rest                                              |
| G2, a stale declaration          | `live-freshness` removed from a named case       | Governance only                | the rest                                              |
| G3, a violation outside the list | a test file whose namespace contradicts its path | Governance only                | the rest                                              |
| G3, a stale allow-list row       | one listed namespace corrected                   | Governance only                | the rest                                              |

**The G2 directory plant is not exclusive, and the reason is worth keeping.**
`ModularArchitectureGovernanceIntegrationTest` copies the whole `tests/` tree
into an isolated project; the planted orphan reached `classifyOwner()` there and
refused with a different message than that control asserts. One cause, two reds.
The plan's claim that an unregistered directory already reddens
`architecture:check` is confirmed — G2 adds the file's name, not the detection.

### G3 ships with a derived list, not an empty promise

60 `*Test.php` files declare a namespace their path contradicts.
`namespace-path-allow-list.php` is written by
`derive-namespace-path-allow-list.php`, which never exits 0 — see "What review
replaced" below for the exit codes it uses now. A row that stops describing a
violation is refused exactly as loudly as a violation that is missing.
**Emptying the list is its own piece of work and has not been done.**

The neighbouring counts, reconciled rather than rounded: 139 files under the dev
roots declare both a namespace and a type and disagree with their path; 146 is
that plus seven fixtures in the global namespace; 147 is Composer's warning
count, one file declaring two non-compliant classes; 149 counts three more files
that declare a namespace and no type at all. The plan's "60 (139 counting
fixtures)" is the first and second of these.

### Two defects found and deliberately not fixed

- **`classifyOwner()` carries dead code.** A catch-all on `tests/Infrastructure/`
  precedes the per-subject regex below it, which is therefore unreachable for
  every path it was written for. Every `Infrastructure/{Subject}/Unit` row in
  the inventory consequently publishes a flat `Infrastructure/Unit` target the
  tree has not decided on. Reproduce with
  `php scripts/generate-modular-architecture-test-inventory.php --classification-probe=tests/Infrastructure/Cache/Unit/CacheKeyTest.php`.
  Repairing it moves the owner column for a couple of hundred rows — stage 04
  will meet this.
- **The pre-commit hook is narrower than both tools it mirrors.** `scripts/` is
  declared in `phpstan.neon` and in the cs-fixer finder and has never been passed
  to either through the hook. Recorded in the hook itself; not changed, because
  widening it changes what a commit rejects.

`EXPLICIT_PATH_DISPOSITIONS` no longer exists: both its entries keyed retired
paths, and an empty map cannot be typed past PHPStan. References to it in this
plan describe a mechanism the tree no longer carries.

### What review replaced

Three-reviewer review found one mechanism behind most of its findings: a guard
was re-implementing a rule PHPUnit or the runner owns instead of asking for it.
The repaired shapes, which supersede the descriptions above:

- **The runner's arguments are no longer read out of its Python source.**
  `scripts/phpunit-aggregate.py --print-commands --cache-root=DIR` prints the
  exact per-suite argv, built by the same `shard_command()` the run uses. G2
  compares `--list-tests` under that argv against `--list-tests` under the bare
  configuration, so every selector — `--filter`, `--group`, a separated
  `--exclude-group live-freshness` — narrows the measurement exactly as it
  narrows the run, and none of them is modelled. The refusal that named the
  excluded *groups* is gone with the lexer that fed it.
- **G2 judges each declared class, not the file.** PHPUnit does not run every
  class a file declares, and drops one in silence — no warning, no non-zero
  exit. The old set-intersection called a file executed because one of its
  classes was. *Which* class PHPUnit keeps resisted characterisation and is
  claimed nowhere: three probes on PHPUnit 12.5.25 gave three answers. A file
  named `ProbeTest.php` declaring `SecondProbeTest` then `ProbeTest` ran
  `SecondProbeTest`; declaring `ProbeTest` then `SecondProbeTest` ran
  `ProbeTest`; declaring `AlphaTest` then `BetaTest` ran nothing at all and
  reported "Class ProbeTest cannot be found". Neither "the first declared" nor
  "the one named after the file" survives all three, so no refusal explains why
  a class was dropped — only that it was.
- **The refusal separates "unregistered directory" from "registered directory,
  unlisted class" by measurement**, not by that rule: whether any other file in
  the same directory is listed.
- **G2 also refuses in the opposite direction**: a class a suite lists that no
  file under a PSR-4 dev root declares — the one way `phpunit.xml.dist` can
  reach outside the corpus these guards judge.
- **G2 refuses a suite the configuration declares and the runner does not shard.**
  This is the one question PHPUnit cannot be asked: `--list-suites` omits a
  suite holding no test, so an empty `<testsuite>` is invisible to it and to the
  runner's identifier partition alike. The suite *names* are read from
  `phpunit.xml.dist`; nothing else about it is.
- **G1 asks both of PHPUnit's discovery rules and both halves of the
  convention.** A public `test…` method runs under the legacy prefix, and a
  method that is named or attributed as a case but is not public does not run at
  all; neither was visible before.
- **A declared PSR-4 dev root that is not on disk refuses** instead of dropping
  out of all three corpora while the floors stay satisfied.
- **The allow-list carries its own ceiling** and the derive command may only
  lower it, so a fresh violation cannot be absorbed by re-running the command.
  Above the ceiling it writes nothing and exits 6.

### The first round of bite proofs shared the guards' blind spot

Every breakage planted before review was a shape the guard's own model could
see: a stripped attribute, an unregistered directory, a namespace that
disagrees. None was a second class in a file, a `test…` method, a non-public
case, or a runner argument the lexer could not read — the four shapes review
then found. "Green on a clean tree, red on my plant" proved the guard refuses
what its author imagined, which is not the same claim.

The second round takes its plants from the findings rather than from the author,
and runs them through the aggregate. Both of the two that matter most redden the
Governance shard alone: a second test class appended to a live `*Test.php`, and
a `#[Test]` method renamed to the legacy `test…` prefix.

**This is the stage's own lesson turned on itself** — a claim about a set,
accepted from a measurement that did not cover the set — and it is recorded
because the plan's other stages will plant breakages the same way.

### What the second review round replaced

The first round of fixes moved three answers from a model to a measurement and
then introduced a fourth model on the way in. The corrections:

- **The listing is the authority; the model is triage only.** G2 used to decide
  a file carried no case before comparing anything with the listing, so a class
  whose cases come from an abstract base or a trait was refused by name while
  PHPUnit was running it — measured: an abstract base plus an empty heir in a
  registered directory is listed by PHPUnit and was refused with "give the file
  a case, or delete it". Listed classes are now removed before any opinion is
  formed, which is asserted in its own refusal rather than left to branch order.
- **Executability is resolved over the corpus, not over one file.** A class
  declaring no case of its own may inherit every case it runs. Building the
  ancestry map costs 0.0006 s on top of a parse the guards already pay for, so
  the "resolving parents is not free" objection did not survive measurement. The
  silent half closed with it: a second class in a live `*Test.php` whose cases
  are inherited is listed nowhere and is now refused, where before it was judged
  not at all.
- **`PHPUnit\Framework\TestCase` is not the only base in this tree.** The
  out-of-corpus parents were enumerated: exactly two are test bases
  (`PHPUnit\Framework\TestCase`, `PHPStan\Testing\RuleTestCase`) and the rest
  are exceptions, visitors, loggers and rules. Ancestry that leaves the corpus
  elsewhere does not make a class a test class, which is also what stops the
  legacy-prefix rule firing on a helper in a `*Test.php`.
- **The configuration is named, not searched for.** PHPUnit prefers a local
  `phpunit.xml` over `phpunit.xml.dist`, and that file is git-ignored — so the
  runner passes `--configuration` in every command it builds and the guard reads
  the suite names from the path the runner names.
- **The allow-list ratchet counts namespaces, not rows.** Fixing one violation
  and introducing another leaves the row count unchanged; the derive now refuses
  a measurement carrying a namespace the tracked list has no budget for.
- **Refusal wording claims nothing about cause.** The branch that used to say
  "register the directory" now says what was measured — that no class from any
  file in that directory is listed — and names registration as something to
  check rather than as the diagnosis.
