# 0003. Reporting UX Redesign

**Date:** 2026-03-15
**Status:** Accepted

## Context

The default CLI output was a flat list of violations in GCC-style format — no overview, no health scores, no progressive disclosure. Health scores existed but were only visible in the HTML report. Metric abbreviations (CCN, TCC, LCOM) were jargon-heavy. AI agents couldn't get a concise structured summary.

## Decision

**Summary-first CLI with progressive disclosure:**

1. **New default format: `summary`** — health overview with bars, worst offenders, violation count, contextual hints. Fits in one terminal screen. Previous default `text` still available via `--format=text`.

2. **`--detail` flag** — adds grouped violation list to any format. Replaces separate `text-verbose` formatter (deprecated with migration warning). Not `-v` because Symfony Console reserves `-v/-vv/-vvv` for verbosity.

3. **`--namespace` / `--class` drill-down** — boundary-aware namespace prefix matching and exact FQCN class matching. Mutually exclusive. Works with summary, text, and JSON formatters.

4. **`MetricHintProvider` as single source of truth** — centralizes labels, directions, good values, explanations, ranges, and health decomposition data. Used by SummaryFormatter, SummaryEnricher, HtmlFormatter, and JS (via embedded JSON). Two label sets: short (`METRICS`) for compact text, long (`HTML_LABELS`) for HTML report.

5. **Dual violation messages** — `humanMessage` ("Cyclomatic complexity: 15 (threshold: 10) — too many code paths") for summary/detail/JSON, `message` (existing format) for text/checkstyle/sarif/gitlab/github. GitLab fingerprint stability preserved.

6. **JSON redesign** — summary-oriented structure with health scores, worst offenders, decomposition. Top-50 violations by default (`--format-opt=violations=all|0|N`). No longer PHPMD-compatible.

7. **`SummaryEnricher`** — computes health scores, worst offenders, tech debt from `MetricRepositoryInterface` + violations. Runs after `ReportBuilder::build()`, enriches `Report` with new VOs (`HealthScore`, `WorstOffender`, `DecompositionItem`).

8. **Health decomposition** — shown for dimensions scoring at or below their warning threshold. Contributing metrics displayed with standard abbreviation + plain language explanation. Uses per-dimension thresholds from `ComputedMetricDefaults`.

**Alternatives considered:**
- Separate AI-specific format — rejected (summary is already AI-friendly by design)
- Invented metric synonyms ("Method connectivity") — rejected (confuses both experts and newcomers; standard abbreviation + explanation serves both)
- Letter grades (A-E) — rejected (numbers provide better granularity)

## Consequences

- **Breaking changes** (acceptable in beta): default format changed, JSON structure redesigned, `Report` VO extended, `Violation` VO extended, `text-verbose` deprecated
- All CI formats unchanged (checkstyle, sarif, gitlab, github) — no fingerprint breakage
- Summary is context-window friendly for AI agents (~30 lines vs hundreds)
- `MetricHintProvider` becomes a central dependency for multiple formatters
- Health decomposition data now flows PHP → JSON → JS (single source of truth achieved)
