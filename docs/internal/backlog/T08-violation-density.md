# T08: Violation Density Metric

**Proposal:** #13 | **Priority:** Batch 3 (output enrichment) | **Effort:** ~2h | **Dependencies:** none

## Motivation

A 200-line class with 13 violations is structurally worse than a 400-line class with 13 violations.
Current reports treat them equally. Violation density (violations/LOC) provides a normalized comparison.

## Design

**Computed at reporting time**, not as a collected metric — violation density depends on rule results,
which are only available after analysis phase.

### Where to add

In the "worst classes" and "worst namespaces" sections of:
- Summary formatter (text output)
- JSON formatter

### Density formula

```
density = violations / physical_LOC
```

Use **physical LOC** (total line count of class/file), not logical LOC.
Display as violations per 100 LOC for readability: `density * 100`, formatted as `X.Y / 100 LOC`.

**Where to compute:** In the enrichment layer (`SummaryEnricher` or a new `DensityEnricher`),
not duplicated across formatters. Both JSON and Summary formatters read the pre-computed density.

### Ranking change

Currently worst classes are ranked by violation count. Add an option to rank by density instead:
- `--format-opt=rank-by=count` (default, backward compatible)
- `--format-opt=rank-by=density`

## Files to modify

| File                                                                    | Change                                          |
| ----------------------------------------------------------------------- | ----------------------------------------------- |
| `src/Reporting/Formatter/Summary/SummaryFormatter.php`                  | Add density to worst classes display            |
| `src/Reporting/Formatter/Json/JsonFormatter.php`                        | Add `density` field to worst classes/namespaces |
| `src/Reporting/Formatter/Summary/WorstClassesRenderer.php` (or similar) | Include density in rendering                    |
| Tests                                                                   | Add density calculation tests                   |

## Acceptance criteria

- [ ] Worst classes show density alongside violation count
- [ ] JSON output includes `violationDensity` in worst classes/namespaces
- [ ] Density is per 100 LOC, rounded to 1 decimal
- [ ] `--format-opt=rank-by=density` sorts by density instead of count
- [ ] Default ranking unchanged (by count)
- [ ] PHPStan passes, tests pass

## Edge cases

- Class with 0 LOC (interface, empty class) → skip from density ranking (avoid division by zero)
- Class with LOC=null (metric not collected) → skip from density ranking
- Namespace with many small classes → high density even with few total violations (correct behavior)
- Namespace density uses `loc.sum` as denominator
- File-level violations (not class-scoped) → use file LOC for density
- Classes with 0 violations → not shown in density ranking (only worst offenders)
