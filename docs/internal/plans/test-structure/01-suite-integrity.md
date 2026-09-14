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

The previous draft replaced `phpunit.xml.dist`'s 53 enumerated directories with
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
  copy of the suite map — `testSuitePrefixTable()`, 53 literals — and reconciles
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
the new guard files, `phpunit.xml.dist`, `scripts/phpunit-aggregate.py`,
`composer.json`, and — if the alias verdict is red — the owning rule under
`src/Analysis/Evidence/Design/`.

## Where these guards live

They are repository controls by the taxonomy in
[`measurement/controls-taxonomy.md`](measurement/controls-taxonomy.md), so they
belong in the controls root that stage 02 creates. **That is a cycle, and it is
resolved here rather than "at execution": stage 01 creates the root and performs
the full registration table from stage 02 — PHPUnit, PHPStan, cs-fixer,
`autoload-dev`, the aggregate's suite tuple, the composer group.** Stage 02 then
only moves files into a root that already works. A stage that leaves the root
half-registered would be green by its own DoD and would break the next one.
