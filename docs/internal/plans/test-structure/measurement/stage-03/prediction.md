# Stage 03 — the prediction, written before anything moves

Per-file expanded case counts, measured one file at a time with
`vendor/bin/phpunit --list-tests --no-coverage <file>` on `main` at `c49fc0b4`.

**None of the 16 carries the `benchmark` or `live-freshness` group** — checked
by listing both groups over exactly these 16 paths and getting no rows. So for
this stage the *discovered* delta and the *executed* delta are the same number,
which is not true of the tree as a whole (see `baseline.md`).

## Per file

| #   | file                                            | cases   | current suite             |
| --- | ----------------------------------------------- | ------- | ------------------------- |
| 1   | `ClassifierTest`                                | 12      | Unit                      |
| 2   | `FloorTest`                                     | 19      | Unit                      |
| 3   | `LedgerVocabularyTest`                          | 4       | Unit                      |
| 4   | `DirectiveAuditGateTest`                        | 12      | Unit                      |
| 5   | `DirectiveAuditReportReadingTest`               | 25      | Unit                      |
| 6   | `DirectiveAuditControlsSuiteKeyTest`            | 1       | Unit                      |
| 7   | `ChannelRenameTsvGateAgreementTest`             | 17      | Unit                      |
| 8   | `BannedStringPathPropertyRuleTest`              | 3       | Unit                      |
| 9   | `BannedStringPathPromotedPropertyRuleTest`      | 1       | Unit                      |
| 10  | `ThresholdPopulationAgreementTest`              | 25      | Unit                      |
|     | **subtotal, rows 1–10 (tool has a directory)**  | **119** | all Unit                  |
| 11  | `SuppressionSnapshotKeyTest`                    | 11      | Unit                      |
| 12  | `RenameEnumerationRetirementTest`               | 8       | Unit                      |
| 13  | `HealthCalibrationBenchTest`                    | 23      | Unit                      |
| 14  | `BenchmarkCoverageRefusalTest`                  | 5       | Integration               |
| 15  | `BenchmarkRegressionClassificationTest`         | 10      | Integration               |
| 16  | `ModularArchitectureGeneratorRefusalTest`       | 3       | Integration               |
|     | **subtotal, rows 11–16 (flat `scripts/*.php`)** | **60**  | 42 Unit + 18 Integration  |
|     | **total**                                       | **179** | 161 Unit + 18 Integration |

## Predicted suite counts

The new suite is named `Tooling`, following the `Governance` precedent: a
separate root gets a separate suite, so the aggregate keeps one shard per root.

### If only rows 1–10 move (fork A1)

| suite          | before | after    |
| -------------- | ------ | -------- |
| Unit           | 7148   | **7029** |
| Integration    | 437    | 437      |
| Functional     | 203    | 203      |
| Infrastructure | 660    | 660      |
| Governance     | 748    | 748      |
| Tooling        | —      | **119**  |
| **executed**   | 9196   | **9196** |
| **discovered** | 9198   | **9198** |

### If all 16 move

| suite          | before | after    |
| -------------- | ------ | -------- |
| Unit           | 7148   | **6987** |
| Integration    | 437    | **419**  |
| Functional     | 203    | 203      |
| Infrastructure | 660    | 660      |
| Governance     | 748    | 748      |
| Tooling        | —      | **179**  |
| **executed**   | 9196   | **9196** |
| **discovered** | 9198   | **9198** |

### If `ThresholdPopulationAgreementTest` stays with the product (fork C = stay)

Subtract 25 from `Tooling` and add it back to `Unit` in whichever variant
applies. Under A1 that is Unit 7054, Tooling 94.

## What makes this a check rather than a report

Relocation creates no cases, so **the two totals are the invariant and the
per-suite rows are the prediction.** A run whose totals match but whose per-suite
rows do not is a file that landed in a suite this table did not send it to — the
defect a total-only check cannot see. A run whose totals differ is a case
created or lost, which is a different and worse defect.

Both numbers must be read from the same two commands the baseline used:
`composer architecture:check` for 9198, `python3 scripts/phpunit-aggregate.py`
for the per-suite rows and 9196.
