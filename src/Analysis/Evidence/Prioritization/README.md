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
combines that debt with measured class rank and returns deterministically
ranked issues. Reporting consumes the resulting values to assemble output; it
does not own their calculation.

## Definition of Done

- debt and impact calculations preserve finding identity and stable ordering;
- Prioritization imports only declared Measurement and Finding contracts;
- formatters and Console adapters do not reach into Prioritization internals.
