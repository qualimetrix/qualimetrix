# Stage 01 — the suite runs what it contains

This stage ships the instruments the rest of the plan is verified with, and
fixes the one defect that proves they are needed. Nothing else touches the tree
until this is green in CI.

## The defect that motivates the stage

`tests/Analysis/Evidence/Design/Unit/TypeCoverage/TypeCoverageRuleTest.php:143`
— `itAliasesItsOwnTwoBoundariesOnly` carries neither `#[Test]` nor
`#[DataProvider]`, while all 11 sibling methods carry both. PHPUnit never calls
it. The CLI-alias contract of the three type-coverage rules is asserted by
nothing, and has been since the method was written.

**Fix it before adding the guard, and run it alone first.** It has never
executed: it may be red. Red means either a stale test or a real defect in the
alias contract, and which one decides whether this stage also carries a product
fix. Do not assume green.

## D6 — the suite config stops enumerating directories by name

`phpunit.xml.dist` lists 53 directories by name. **PHPUnit supports globs in
`<directory>`** — measured on this tree with PHPUnit 12.5.25. Each suite becomes
a set of depth globs (`tests/*/Unit`, `tests/*/*/Unit`, … to the tree's maximum
depth of four) instead of hand-maintained paths, so a subject directory created
by any later stage is covered the moment it exists.

**The glob set alone loses 100 of the 679 test classes, and the first draft of
this section did not say so.** Measured, not reasoned:

| Config                             | Classes | Methods  |
| ---------------------------------- | ------- | -------- |
| current enumeration                | 679     | 9098     |
| depth globs only                   | 579     | 7838     |
| depth globs + transitional entries | **679** | **9098** |

The 100 split into two causes, and each has its own consequence:

- **96 are the legacy-bucket files.** In `tests/Unit/Core/...` the level comes
  *first*, so `tests/*/Unit` cannot match them. They are covered by globs only
  after stage 04 moves them. Until then the config keeps
  `<directory>tests/Unit</directory>` and its two siblings as **transitional
  entries**, deleted by stage 04 as its last step.
- **4 are `tests/Infrastructure/Logging/*Test.php`, which sit under no level
  directory at all.** They run today only because the `Infrastructure` suite
  includes its root wholesale. Either they move into
  `Infrastructure/Logging/Unit/` — which is what the layout says anyway — or
  they need a permanent explicit entry. Moving them is this plan's answer, and
  it belongs to this stage, not to stage 04, because until it happens the glob
  set is not self-sufficient.

So D6 does not delete the enumeration in one step: it replaces the parts that
globs can express and keeps a shrinking, explicitly-named remainder. Claiming
the hazard is simply "gone" — as the first draft did — would itself have been
the kind of unverified promise this plan exists to remove.

**Cost to state:** a glob set silently includes a directory somebody adds later.
That is intended here (a test should run), but the config no longer documents
what exists. G2 below is what keeps that honest.

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

Refuses a `*Test.php` reachable by no suite. **After D6 this is nearly free**,
but it stays, because a glob set can still miss a depth, and because this is the
X13 class: 110 tests once sat unexecuted for three runs under a green
`composer check`.

```
executed = tests PHPUnit actually lists for the configured suites
on_disk   = every *Test.php under the covered roots
refuse when on_disk \ executed is non-empty
```

**Take the executed set from PHPUnit's own `--list-tests`, not from a
reimplementation of its matching rules.** The previous draft of this plan
specified G2 as a directory-coverage check and justified it with a claim that is
simply false — that a file moved into an unlisted directory would not be caught
by an orphan check "because that file is then not there yet". It is there, and
an orphan check catches it. The real value of G2 is the count, not the
directory inventory, and after D6 the directory inventory is meaningless.

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
- D6 landed: the config uses globs, and `--list-tests` returns the same test
  count as before the change. A different count means the rewrite changed what
  runs, which is the one thing it must not do.
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
