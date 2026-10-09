# Prioritization evidence

`Analysis\\Evidence\\Prioritization` owns evidence used to order findings by
remediation effort and structural impact. It consumes Measurement and Finding
contracts and has no dependency on Reporting.

## Layout

```text
Prioritization/
├── Debt/
│   ├── DebtCalculator.php
│   ├── DebtSummary.php
│   └── RemediationTimeRegistry.php
└── Impact/
    ├── ClassRankIndex.php
    ├── ClassRankResolver.php
    ├── ImpactCalculator.php
    └── RankedIssue.php
```

Debt derives remediation minutes from finding identity and severity. Impact
combines that debt with measured ClassRank share and returns deterministically
ranked issues. Reporting consumes the resulting values to assemble output; it
does not own their calculation.

`ClassRankResolver` reads only `coupling.class-rank-share` for exact
declarations, namespace/file maxima and the project median. Missing share
never falls back to raw PageRank probability. Impact multiplies share by severity
weight and remediation minutes; an unranked finding uses the native measured
median, or zero when no share was measured. `RankedIssue::classRankShare` and
JSON top issues' `coupling.class-rank-share` carry these units. A common graph
size multiplier preserves ordering within one graph while changing the numeric
impact scale; it does not establish comparability across different graphs.

## Definition of Done

- debt and impact calculations preserve finding identity and stable ordering;
- Prioritization imports only declared Measurement and Finding contracts;
- formatters and Console adapters do not reach into Prioritization internals.


## Locality

This README is part of the subject boundary: keep its production code, tests, fixtures, support, and documentation with the named owner. External consumers use declared contracts only; mutable runtime state has one owner, reset point, and typed readers. Composition-only access to a private declaration requires a reviewed exact binding, not a generic qmx permission.
