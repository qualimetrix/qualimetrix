# directive-audit — independent evidence for inline-directive decisions

This directory owns the data that checks whether inline directives are found
and whether their negative controls cover the executable audit. It is evidence
for the audit, not product configuration and not a replacement for the audit's
own verdict report.

## Artifacts

| file                                   | role                                                                                     | owner                                         | refresh                                                   |
| -------------------------------------- | ---------------------------------------------------------------------------------------- | --------------------------------------------- | --------------------------------------------------------- |
| `enumeration-threshold-directives.tsv` | tokenizer-derived inventory of authored `@qmx-threshold` sites in `src/`                 | `scripts/directive-audit/Enumerator.php`      | `php scripts/enumerate-inline-directives.php src --write` |
| `enumeration-unguarded-cases.tsv`      | adjudication of cases that an earlier control measurement found unguarded or misdeclared | `scripts/directive-audit-controls/Probes.php` | re-run the named focused control before changing its row  |

The threshold enumeration is generated. Do not hand-edit it: `composer
enumeration:directives:check` compares it with a fresh tokenizer scan. The
tokenizer intentionally does not reuse the product extractor, so agreement is
evidence rather than two spellings of one defect.

The unguarded-cases table is a source record. Its rows explain why a case is
classified as `UNGUARDED` or `MISDECLARED`, or — for a case once found
unguarded — which probe now guards it (`GUARDED`); a control declaration must
be changed together with its adjudication and a fresh focused run.

## Operating checks

```bash
composer enumeration:directives:check
composer directives:audit
composer directives:narrow-control
composer directives:controls -- --only=<probe-id>
```

`directives:narrow-control` compares narrow and full sweeps over a heterogeneous
fixture before it measures `src/`; equal answers over a uniform population are
not sufficient evidence.

## Permanent mutation coverage limitations

The stand still executes every case in its suite and compares every actual red
set with its declaration. Equality, stale names and blanket-mutation refusal
remain strict. The following exact cases retain product regressions and one-time defect
proofs, but have no permanent own-probe coverage:

- `Qualimetrix.Governance.DirectiveVocabulary.ExecutionFingerprintFieldCoverageTest::itIgnoresTheInternalAddressedProducer`: Internal selection-address invariance has a separate one-time product mutation proof; this stand does not repeat that probe.
- `Qualimetrix.Tests.Infrastructure.Console.Functional.DirectivesCommandTest::itNamesMeasuredCountsForExcludedGeneratedAndEmptyEntries`: Run-entry count publication has its own regression and one-time proof, outside permanent directive-decision mutation coverage.
- `Qualimetrix.Tests.Infrastructure.Console.Functional.DirectivesCommandTest::itNamesTheFileThatWroteANonExistentPath`: Configuration refusal-origin publication has its own regression and one-time proof, outside permanent directive-decision mutation coverage.
- `Qualimetrix.Tests.Infrastructure.Console.Functional.DirectivesCommandTest::itReportsAnIntentionallyEmptyGeneratedScope`: Generated-only run outcome has its own regression and one-time proof, outside permanent directive-decision mutation coverage.
- `Qualimetrix.Tests.Infrastructure.Console.Unit.DirectiveAuditSummaryProjectionTest::itPublishesBothEqualRankDisableWritersInTheTextAndJsonSelection`: Configuration writer provenance has its own regression and one-time proof, outside permanent directive-decision mutation coverage.

- `QmxDirectiveAudit.Tests.DirectiveAuditReportReadingTest::itRefusesAVerdictWhoseFieldsAreNotTheShapeTheAuditPublishes with data set "inert with refusals"`: Report refusal shape and verdict consistency have an owning reader regression and a one-time method mutation proof; no permanent own probe is required.
- `QmxDirectiveAudit.Tests.DirectiveAuditReportReadingTest::itRefusesAVerdictWhoseFieldsAreNotTheShapeTheAuditPublishes with data set "refusals missing"`: Report refusal shape and verdict consistency have an owning reader regression and a one-time method mutation proof; no permanent own probe is required.
- `QmxDirectiveAudit.Tests.DirectiveAuditReportReadingTest::itRefusesAVerdictWhoseFieldsAreNotTheShapeTheAuditPublishes with data set "refusals not a list"`: Report refusal shape and verdict consistency have an owning reader regression and a one-time method mutation proof; no permanent own probe is required.
- `QmxDirectiveAudit.Tests.DirectiveAuditReportReadingTest::itRefusesAVerdictWhoseFieldsAreNotTheShapeTheAuditPublishes with data set "refusals null"`: Report refusal shape and verdict consistency have an owning reader regression and a one-time method mutation proof; no permanent own probe is required.
- `QmxDirectiveAudit.Tests.DirectiveAuditReportReadingTest::itRefusesAVerdictWhoseFieldsAreNotTheShapeTheAuditPublishes with data set "refused without refusals"`: Report refusal shape and verdict consistency have an owning reader regression and a one-time method mutation proof; no permanent own probe is required.
- `Qualimetrix.Tests.Analysis.Policy.Inline.Integration.DirectiveUsageTest::itRefusesToJudgeASelectorThatNamesNoChannelAtAll`: Unknown-selector classification has an owning usage regression and a one-time assertion failure proof, outside permanent own-probe coverage.
- `Qualimetrix.Tests.Infrastructure.Console.Functional.DirectivesCommandTest::itPublishesEveryInvalidThresholdAsOneRefusedSite`: Invalid-threshold publication has an owning command regression and a one-time assertion failure proof, outside permanent own-probe coverage.
- `Qualimetrix.Tests.Infrastructure.Console.Unit.DirectiveAuditSummaryProjectionTest::itPublishesRefusalDetailsWithoutTheInternalAddress`: Public refusal details have an owning projection regression and a one-time assertion failure proof, outside permanent own-probe coverage.

The report prints each applicable limitation. A new product regression with a
one-time defect proof can be named here rather than growing the permanent
mutation stand. The coverage arithmetic uses the same restricted population;
the real execution universe and stale-declaration check remain complete.
