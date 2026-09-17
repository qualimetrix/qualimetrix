# Stage 02 — predicted executed-case counts, measured before the first move

The DoD asks for the post-move count to be predicted first: a green aggregate
that quietly stopped running forty files looks exactly like success. This file
is that prediction, and `case-census.tsv` beside it is the per-file evidence it
sums.

## How the numbers were taken

`vendor/bin/phpunit --testsuite=<suite> --list-tests --no-coverage
--exclude-group=benchmark` per suite, one line per **expanded** case. Two
parsing traps, both hit and both fixed, are worth naming because either one
silently shrinks the census:

- A data-set name may itself contain `::`
  (`itGivesEveryThresholdParserCallSiteAMatchingRegistryEntry"design.dit::"`),
  so the class/method split must take the **first** `::`, not the last. Taking
  the last dropped `RuleThresholdKeyGroupRegistryDriftTest` — 157 cases — from
  the census entirely.
- A test file may declare several classes
  (`ConfigurationErrorClassificationTopologyTest` declares seven, six of them
  fixtures), so the class is the one named by the file, not the first or last
  declaration.

`controls-verdict.tsv` is read by splitting on tabs, not through a CSV reader:
two `reason` cells contain a `"`, and quote-aware parsing merges their rows,
turning 40 `repo-control` into 39.

## Baseline, re-measured on `52eae218`

| suite          | excluded-group run (`composer check`) | full listing |
| -------------- | ------------------------------------- | ------------ |
| Unit           | 7578                                  | 7578         |
| Integration    | 712                                   | 714          |
| Functional     | 207                                   | 207          |
| Infrastructure | 683                                   | 683          |
| Governance     | 16                                    | 16           |
| **total**      | **9196**                              | **9198**     |

The two-case gap is `--exclude-group=live-freshness`, and both cases are named
in `TestFilesAreExecutedTest::SILENTLY_EXCLUDED`. This reproduces the stage-01
baseline exactly, so the instrument agrees with the one that produced it.

## Verdict composition, re-derived from the TSV against the tree

15 `product-test` + 40 `repo-control` + 9 `tooling-test` + 17 `mixed` = 81,
and every method named in a `mixed` row's `scope` exists in the listing — the
plan's composition and the tree agree. Group file counts reproduce the
taxonomy's table exactly (sum 57).

## Prediction: relocation only, before any deletion

`repo-control` moves whole; `mixed` moves the cases of the methods its `scope`
names. Relocation moves cases between suites and creates none, so the total is
invariant — that invariance is the check, not the per-suite figures.

| suite          | before | moves out | after    |
| -------------- | ------ | --------- | -------- |
| Unit           | 7578   | −357      | 7221     |
| Integration    | 714    | −119      | 595      |
| Functional     | 207    | −1        | 206      |
| Infrastructure | 683    | −18       | 665      |
| Governance     | 16     | +495      | 511      |
| **total**      | 9198   | 0         | **9198** |

These figures carry the two corrections in `two-witness-adjudication.md`:
`RatchetKeyGrammarTest` moves whole (1 case → 8) and
`DirectiveAuditReportReadingTest` moves four methods rather than three (+1).
Read straight off `controls-verdict.tsv` the rows would be −356 / −112 / +487.

Under the aggregate's `--exclude-group=live-freshness`, Integration reads 593
and the total 9196, provided both excluded cases keep their group; both are
inside the moving set, so their `SILENTLY_EXCLUDED` names change with them.

Cases per destination group:

| group                     | cases | group                        | cases |
| ------------------------- | ----- | ---------------------------- | ----- |
| `ThresholdKeys`           | 287   | `SolePrimitiveOwnership`     | 7     |
| `RuleOptionKeys`          | 64    | `TestSuiteHygiene`           | 6     |
| `Channel`                 | 34    | `Occurrence`                 | 4     |
| `ConsoleComposition`      | 17    | `RatchetArtifact`            | 8     |
| `RuleDeclaration`         | 16    | `FormatOptionKeys`           | 3     |
| `DocumentationCensus`     | 14    | `MeasurementVocabulary`      | 3     |
| `ModularOwnership`        | 14    | `RepositoryEntrypoints`      | 3     |
| `ConfigurationVocabulary` | 7     | `MeasurementIdentity`        | 2     |
| `ControlRigLedger`        | 1     | `GeneratedArtifactFreshness` | 1     |
| `DirectiveVocabulary`     | 4     |                              |       |

`TestSuiteHygiene` lands on top of the 16 cases stage 01 already put there, so
that directory is predicted at 22.

## What this prediction does not cover

- **Deletions.** Every figure above is relocation-only. A deletion subtracts
  from `Governance` after the move, and its size is stated when the deletion is
  decided, not assumed here.
- **Splitting a `mixed` file can change the count.** A shared data provider or
  a `#[DataProvider]` that both halves use must be duplicated; if a copy is
  parameterised differently the case count moves. Each split package therefore
  re-measures rather than inheriting this row.
- **The census counts cases, not coverage.** A method that moves with its
  assertions gutted keeps its case and satisfies every number here.
