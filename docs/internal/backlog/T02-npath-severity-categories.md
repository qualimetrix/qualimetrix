# T02: NPath Severity Categories

**Proposal:** #12 | **Priority:** Batch 0 (quick win) | **Effort:** ~30min | **Dependencies:** none

## Motivation

NPath 800 and NPath 2,000,000 are qualitatively different problems requiring different strategies.
Currently the violation message just says "exceeds threshold". Adding a human-readable category helps
developers prioritize without memorizing NPath scale.

## Design

Add a severity category label to NPath violation messages only (not CCN/Cognitive — those have
linear scales where categories don't add value).

**Categories (absolute ranges, independent of configured threshold):**

| Range            | Label     | Meaning                                 |
| ---------------- | --------- | --------------------------------------- |
| 1–1,000          | moderate  | Minor refactoring (extract 1-2 methods) |
| 1,001–10,000     | high      | Significant refactoring needed          |
| 10,001–1,000,000 | very high | Major restructuring required            |
| > 1,000,000      | extreme   | Fundamental redesign needed             |

Categories are based on absolute NPath values, not relative to the configured threshold.
If a user sets threshold=5000, a value of 6000 still gets "high" (not "moderate").

## Files to modify

| File                                           | Change                                             |
| ---------------------------------------------- | -------------------------------------------------- |
| `src/Rules/Complexity/NpathComplexityRule.php` | Add category to violation message (lines 155, 198) |

No new classes needed. Just a private method `getCategoryLabel(int $npath): string`.
The method accepts `int` only — the display string "> 1M" is handled before calling this method.

## Message format (before → after)

**Before:**
```
NPath complexity (execution paths) is 36120, exceeds threshold of 200. Reduce branching or extract methods
```

**After:**
```
NPath complexity (execution paths) is 36120 (very high), exceeds threshold of 200. Reduce branching or extract methods
```

## Acceptance criteria

- [ ] Method-level NPath violations include category label
- [ ] Class-level NPath violations include category label
- [ ] Categories are consistent: same value always produces same label
- [ ] Values at exact boundaries are assigned to the higher category (e.g., 1000 → moderate, 1001 → high)
- [ ] Existing tests updated for new message format
- [ ] PHPStan passes

## Edge cases

- NPath value displayed as "> 1M" (overflow) — always maps to "extreme"
- NPath exactly at threshold boundary — gets the lowest applicable category
