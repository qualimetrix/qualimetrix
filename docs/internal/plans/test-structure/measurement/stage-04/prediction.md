# Stage 04 — measured baseline and per-suite prediction

Measured on `main` @ e15c7f42, clean tree, 2026-09-17.

## The oracle, and the trap in it

The count to compare against is **not** `phpunit --testsuite=<S> --list-tests`.
That command reports 9198 across the six suites, while `composer check` runs 9196,
and the two-case difference is not skipping — it is `--exclude-group=live-freshness`,
which `scripts/phpunit-aggregate.py` passes and a bare `phpunit` invocation does
not. An earlier draft of this file asserted the gap was "two skipped Governance
cases"; running the suite directly disproved that, since it reports
`OK (750 tests)` with nothing skipped.

So the oracle is the runner's own command with `--list-tests` appended:

```
vendor/bin/phpunit --testsuite=<S> --no-coverage \
  --exclude-group=benchmark --exclude-group=live-freshness --list-tests | grep -c '^ - '
```

`governance/TestSuiteHygiene/TestFilesAreExecutedTest.php` already says this in
prose — what a suite *could* run and what `composer check` *does* run are two
measurements, and their difference is whatever the runner excludes. Taking the
first for the second is how a moved test disappears from CI under a green count.

Separately and harmlessly: one Unit case is skipped at runtime and still counts in
PHPUnit's `Tests:` total, so it does not move any number here.

## Baseline

| Suite          | Baseline | Source                                        |
| -------------- | -------: | --------------------------------------------- |
| Unit           | 6987     | the oracle command above                      |
| Integration    | 419      | the oracle command above                      |
| Functional     | 203      | the oracle command above                      |
| Infrastructure | 660      | the oracle command above                      |
| Tooling        | 179      | the oracle command above                      |
| Governance     | 748      | the oracle command above                      |
| **Total**      | **9196** | equals what `composer test:aggregate` reports |

## Per-package prediction

The stage runs in three move packages partitioned by manifest owner. Each is checked
against its own row, not against the end of the stage: a package green on the total
while two suites are wrong is the exact hole this campaign keeps finding.

| After              | Unit | Integration | Functional | Infrastructure | Tooling | Governance | Total | Files |
| ------------------ | ---: | ----------: | ---------: | -------------: | ------: | ---------: | ----: | ----: |
| baseline           | 6987 | 419         | 203        | 660            | 179     | 748        | 9196  | —     |
| P1 Infrastructure  | 6705 | 383         | 152        | 1029           | 179     | 748        | 9196  | 52    |
| P2 Reporting       | 6705 | 383         | 152        | 1029           | 179     | 748        | 9196  | 51    |
| P3 Core + Analysis | 6705 | 383         | 152        | 1029           | 179     | 748        | 9196  | 11    |

**P1 carries the entire suite delta; P2 and P3 are suite-neutral.** That is what
makes P2 and P3 checkable at all: their oracle is that every one of the six numbers
is byte-identical to the P1 row. A single case shifting suite in P2 or P3 means a
file landed under `tests/Infrastructure/` without an `Infrastructure.` owner, and
nothing else produces that signature.

The total cannot move: a relocation creates and destroys no case. But the total is
not the check — it reconciles even when two suites are wrong in opposite directions.

## `live-freshness` does not run under `composer check`

The two excluded cases are `ModularArchitectureGovernanceIntegrationTest` and
`SuppressionSnapshotFreshnessTest`. Nothing else in the repository passes that
exclusion, and `composer check` reaches PHPUnit only through the aggregate, so
**neither runs in `composer check` or in CI.**

This matters to this stage specifically. The first of those two files is where the
hardcoded counts over generated artifacts live — `assertCount(28, ...)` over
`test-orphan-dispositions.tsv` and `assertCount(1, ...)` over
`test-system-support-owners.tsv`. Package P4 edits those numbers, and a green
`composer check` says nothing about whether it got them right. P4 must run that test
explicitly:

```
vendor/bin/phpunit --testsuite=Governance --no-coverage \
  --filter=ModularArchitectureGovernanceIntegrationTest
```

Whether these two belong outside the aggregate at all is not this stage's question,
but it is a real one and it is recorded in `04-subject-layout.md`.

## Population
`relocation-map.csv` — 114 rows: 88 in the legacy buckets, 26 pre-existing residue.
112 are PHPUnit test classes (1323 cases); 2 are support classes and carry no case.

## How the map was obtained, and what this method cannot see

**Obtained by:** each file's own `#[CoversClass]`, resolved through its `use`
statements to a fully qualified name, looked up in
`docs/internal/modular-architecture-manifest.json`, whose 37 owners are the
repository's only authoritative ownership statement. Target is
`tests/<owner path>/<level>/<namespace remainder below the owner>/<basename>`.

**Cannot see:**

- A file that covers more than it declares, or declares a class it barely
  exercises. `#[CoversClass]` is a claim, not a proof.
- Whether the *level* segment is right. A test filed under `Unit` that builds a
  container keeps its wrong level through this stage; that is stage 05's
  `category-wrong` class.
- 11 rows had no single manifest owner (8 with no `CoversClass`, 3 covering two
  owners). Each was read and decided by hand; the row's `note` carries `DECIDED:`
  and the reason.
- Case counts come from `--list-tests` per file, which counts data-provider rows. A
  provider whose row count depended on the filesystem would make the prediction move
  with the tree. None was observed; nothing here proves their absence.

## The Infrastructure suite asymmetry is inherited, not introduced — and now larger

After P1 the Infrastructure suite holds 1029 cases, roughly a third of them unit
tests of `Git`, `Console`, `Serializer` and `Ast`. The config already declares
`<directory>tests/Infrastructure</directory>` for every level while every other
suite enumerates one directory per level. The stage does not create that shape, but
it does make it much more visible — 660 cases become 1029.

Recorded so it reads as a deliberately inherited asymmetry and not as evidence that
`Infrastructure` is a level. It is an owner prefix. Whether the config should declare
per-level Infrastructure directories is stage 05's question.
