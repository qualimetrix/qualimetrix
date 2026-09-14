# Stage 01 — the suite runs what it contains

This stage ships the instruments the rest of the plan is verified with, and
fixes the one defect that proves they are needed. Nothing else touches the tree
until this is green in CI.

## The defect that motivates the stage

`tests/Analysis/Evidence/Design/Unit/TypeCoverage/TypeCoverageRuleTest.php:143`
— `itAliasesItsOwnTwoBoundariesOnly` carries neither `#[Test]` nor
`#[DataProvider]`, while all 11 sibling methods carry both. PHPUnit never calls
it. The CLI-alias contract of the three type-coverage rules is therefore
asserted by nothing, and has been asserted by nothing since the method was
written.

**Fix it before adding the guard, and run it alone first.** It has never
executed: it may be red. A red result means either a stale test or a real defect
in the alias contract, and which one it is decides whether this stage also
carries a product fix. Do not assume green.

## Three guards, and what each refuses

Each guard is itself a control by D5, so each lands in the new controls root —
which makes this stage the proof that the root works end to end before 50+ files
migrate into it. Stage 02 creates the root; this stage may run first only if the
root is created here instead. **Resolve that ordering when executing: either
01 creates the root and 02 fills it, or 02 runs first. Do not let both create it.**

### G1 — every test method is reachable

Refuses a method named `itXxx` that carries no `#[Test]`, and a method carrying
`#[Test]` whose name is not `itXxx` (CLAUDE.md §9, both directions).

```
for each *Test.php in the tree:
    for each public function:
        name_is_it   = /^it[A-Z]/
        has_attribute = #[Test] present in the preceding attribute block
        refuse when name_is_it xor has_attribute
        # ... implementation details
```

Blind spot to state in the guard's own docblock: a method disabled by
`#[Group]` exclusion, by `markTestSkipped`, or by an abstract parent is
reachable by this definition and still may not run.

### G2 — every test file is in a suite

Refuses a `*Test.php` under `tests/` (and, after stage 02, the controls root)
matched by no `<directory>` entry of its config.

```
suite_dirs = every <directory> in phpunit config
for each *Test.php:
    refuse when no suite_dir is a path prefix of it
```

This is the X13 class: 110 tests once sat unexecuted for three runs under a
green `composer check`. Today the count is 0 — the guard protects that, and is
the precondition for stages 02–04, each of which moves files between
directories.

**G2 has a second half, and it is the one that matters for this plan.** The
config enumerates 53 directories by name, and 39 level-directories of existing
capabilities are listed in none of them. A guard that only checks "no file is
orphaned" passes today and passes again the moment a relocation lands a file in
an unlisted directory — because that file is then *not there yet*. So G2 also
refuses a **directory that exists under the tree and is covered by no suite**,
whether or not it currently holds files. Without this half, 32 of the
`category-wrong` fixes in stage 05 silently disable the tests they move.

### G3 — namespace agrees with path

Refuses a file whose declared namespace does not match its directory under the
PSR-4 root.

```
expected = psr4_prefix + relative_dir_of(file) with / -> \
refuse when declared_namespace != expected
```

PHPUnit discovers by file, so a wrong namespace runs but misleads every reader
and breaks any reflection-based tooling. The tree already carries such cases —
`RuleExclusionStatsTest` declares `Tests\Unit\Analysis\RuleExecution` while
living under `Finding/Unit`, and the Measurement slice has more. G3 run against
the whole tree closes those as a side effect, which is why it is written here
and not inside stage 04.

## Definition of Done

- `TypeCoverageRuleTest::itAliasesItsOwnTwoBoundariesOnly` executes. Its verdict
  (green, or red with the cause named) is written into the stage report.
- G1, G2, G3 exist, live in the controls root, and are reachable from a composer
  script that the aggregate actually calls.
- **Each guard is proved to bite**: plant one breakage per guard — a method
  stripped of `#[Test]`, a directory removed from the config, a namespace
  altered — and record that the guard reddens for its own case and only for it.
  A guard verified only against the broken tree is verified on half its range;
  it must also be seen green on the clean tree.
- G2 reports the 39 uncovered directories as a number, and that number is
  recorded here as the baseline stages 02–04 must not increase.
- `composer check` green.

## Files

`tests/Analysis/Evidence/Design/Unit/TypeCoverage/TypeCoverageRuleTest.php`,
the new guard files, `phpunit.xml.dist`, `composer.json`, and — if the alias
verdict is red — the owning rule under `src/Analysis/Evidence/Design/`.
