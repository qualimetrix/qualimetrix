# T03: Rename `health` → `html`, New `health` Text Formatter

**Proposal:** #4 | **Priority:** Batch 1 (naming) | **Effort:** ~3h | **Dependencies:** none

## Motivation

`--format=health` producing HTML is counterintuitive. Users expect terminal output from a CLI tool.
The HTML report now contains multiple interactive charts (treemap + others), making `html` a more
accurate name. Breaking change is acceptable — better to fix naming early.

## Design

### Part 1: Rename existing HtmlFormatter

- Change `HtmlFormatter::getName()` from `'health'` to `'html'`
- Update all references in tests, docs, CLI help

### Part 2: New HealthTextFormatter

Create `src/Reporting/Formatter/Health/HealthTextFormatter.php`:
- Name: `'health'`
- Output: tabular health scores in terminal
- Reuse data from `SummaryEnricher` (same HealthScore objects)
- Show per-dimension: name, score, label, warning/error thresholds
- Show decomposition items for each dimension
- Auto-registered via DI (implements `FormatterInterface`)

**Important:** This formatter is a standalone text formatter, NOT a wrapper over SummaryFormatter.
It uses the same `HealthScore` data model but renders it differently (dedicated health view vs
summary overview). `--group-by`, `--detail` do NOT apply to this formatter (it shows health
dimensions, not violations).

### Output format sketch

```
Health Report: src/

  Dimension        Score   Status      Thresholds
  ─────────────────────────────────────────────────
  Complexity        72.3   ▲ good      warn < 60  err < 40
  Cohesion          46.7   ▼ warning   warn < 50  err < 30
  Coupling          81.5   ▲ good      warn < 60  err < 40
  Maintainability   68.9   ▲ good      warn < 50  err < 30
  Overall           67.4   ▲ good      warn < 50  err < 30

  Cohesion decomposition:
    TCC average          34.2%   (good: > 50%)
    LCOM4 average         3.1    (good: = 1)
    ...
```

## Files to modify

| File                                                     | Change                    |
| -------------------------------------------------------- | ------------------------- |
| `src/Reporting/Formatter/Html/HtmlFormatter.php`         | `getName()` → `'html'`    |
| `src/Reporting/Formatter/Health/HealthTextFormatter.php` | **New file**              |
| Tests referencing `--format=health`                      | Update to `--format=html` |
| `website/docs/reference/cli.md` (EN + RU)                | Update format names       |
| `website/docs/guides/html-report.md` (EN + RU)           | Rename if exists          |
| `CHANGELOG.md`                                           | Breaking change entry     |

## Acceptance criteria

- [ ] `--format=html` produces the interactive HTML report (previously `--format=health`)
- [ ] `--format=health` produces text table of health scores in terminal
- [ ] `--format=health` output includes all dimensions with scores and decomposition
- [ ] `--format=health` respects `--namespace` for drill-down
- [ ] `--format=health` is the expected default for AI agent quick-inspect workflow
- [ ] Old `--format=health` producing HTML is gone (breaking change documented)
- [ ] PHPStan passes, tests pass

## Edge cases

- No health scores available (e.g., no computed metrics configured) — show "no health data" message
- Terminal width < 60 — graceful degradation (truncate labels, drop decomposition)
- `--format=health --output=file.txt` — works, plain text (no ANSI codes)
- Namespace drill-down: `--format=health --namespace=App\\Core` — show health for that namespace only
- Partial analysis (subset of files) — health scores may be incomplete, show as-is (no special handling needed since there are no users relying on current behavior)
