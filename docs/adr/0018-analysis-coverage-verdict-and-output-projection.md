# 0018. Analysis Coverage, Verdict, and Output Projection

**Date:** 2026-08-08
**Status:** Accepted

## Context

The analysis pipeline used two counters, `filesAnalyzed` and `filesSkipped`, to
describe discovery outcomes. A skipped file could mean an intentional generated
file exclusion or a parse/processing failure. Consumers therefore could not tell
a complete run from a partial one. `check` could report success when every input
failed, baseline lifecycle commands could interpret a partial measured set, and
`graph:export` could publish a partial graph as if it were authoritative.

Formatters also had no common representation of coverage. Special-casing zero
analyzed files in the command bypassed the selected formatter and made stdout
invalid or empty for machine-readable formats.

## Decision

`AnalysisCoverage` is the canonical pipeline verdict. Every discovered PHP file
has exactly one terminal state: analyzed, intentionally excluded as generated,
or failed with a typed parse/processing failure. Generated exclusions preserve
completeness; failures do not.

The pipeline returns available findings and metrics together with coverage.
`check` is therefore diagnostic on incomplete input: it still renders the
selected format, marks policy results as non-authoritative, and exits with code
4. Exit 4 takes precedence over warning/error policy codes. Zero discovered
files and generated-only input are complete runs and still pass through every
formatter.

Reporting receives a dependency-safe `ReportCoverage` projection rather than
the Analysis type. Each native formatter represents coverage in its own schema.
Human diagnostics that are not part of the report payload are routed to stderr,
so stdout remains a valid selected-format document.

Artifact writers use a stricter boundary. Baseline lifecycle commands refuse to
interpret or mutate state on incomplete analysis, and `--force` cannot override
that refusal. Dependency graph export likewise emits no partial artifact and
preserves an existing destination byte-for-byte.

Unknown rule selectors and rule-option owners are invalid input, not warnings:
they fail closed with exit 3 before a report payload is written.

## Consequences

- Callers can distinguish policy failure (1/2), invalid input (3), and incomplete
  analysis (4).
- Machine consumers receive syntactically valid zero-file and partial-analysis
  payloads instead of command-specific prose on stdout.
- A partial report may help diagnosis, but its policy result must not be treated
  as authoritative.
- Baseline and graph artifacts are never evidence derived from incomplete input.
- New formatters must define a coverage projection and cover empty, complete,
  generated-only, partially failed, and all-failed states.
