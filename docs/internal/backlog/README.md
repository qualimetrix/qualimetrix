# Tool Improvement Backlog

Prioritized task specifications for independent agent execution.
Source: [TOOL_IMPROVEMENT_PROPOSALS.md](../TOOL_IMPROVEMENT_PROPOSALS.md) + discussion (2026-03-29).

## Execution cycle

Plan → Review plan → Implement → Review (Claude + Gemini + Codex)

## Task order

### Batch 0 — Quick wins
- [T01](T01-full-json-violations.md) — Full JSON violation output (#10) ✅ reviewed
- [T02](T02-npath-severity-categories.md) — NPath severity categories (#12) ✅ reviewed

### Batch 1 — Naming & output
- [T03](T03-health-format-rename.md) — Rename `health`→`html`, new `health` text formatter (#4) ✅ reviewed

### Batch 2 — False positive reduction
- [T04](T04-vo-constructor-exemption.md) — VO constructor exemption for long-parameter-list (#1) ✅ reviewed
- [T05](T05-lcom-stateless-grouping.md) — LCOM stateless method grouping (#2) ✅ reviewed

### Batch 3 — Output enrichment
- [T06](T06-duplication-content-hints.md) — Duplication content hints (#8) ✅ reviewed
- [T07](T07-groupby-extensions.md) — GroupBy class/namespace for JSON (#5) ✅ reviewed
- [T08](T08-violation-density.md) — Violation density metric (#13) ✅ reviewed

### Batch 4 — Health drill-down
- [T09](T09-worst-contributors.md) — Worst contributors per health dimension (#11, depends on T03) ✅ reviewed

### Batch 5 — Architecture
- [T10](T10-full-dependency-graph.md) — Full dependency graph always (#14) ✅ reviewed + detailed design
- [T11](T11-framework-cbo.md) — Framework CBO distinction (#3, depends on T10) ✅ reviewed

### Batch 6 — Threshold infrastructure
- [T12](T12-custom-threshold-annotations.md) — @qmx-threshold annotations (#9) ✅ reviewed + detailed design

## Review status

Triple-reviewed (Claude + Gemini + Codex) on 2026-03-29.

| Task | Claude    | Gemini    | Codex     | Outcome                                                      |
| ---- | --------- | --------- | --------- | ------------------------------------------------------------ |
| T01  | LGTM      | LGTM      | MINOR     | Updated: fixed CheckCommand path, clarified --all synonyms   |
| T02  | LGTM      | LGTM      | LGTM      | Updated: absolute categories, int-only helper                |
| T03  | MINOR     | LGTM      | NEEDS REV | Updated: clarified formatter scope, partial analysis         |
| T04  | MINOR     | LGTM      | NEEDS REV | Updated: PHP 8.2 readonly semantics, extra edge cases        |
| T05  | MINOR     | LGTM      | NEEDS REV | Updated: precise virtual node algorithm, benchmark check     |
| T06  | MINOR     | LGTM      | MINOR     | Updated: source availability, extraction from tokens         |
| T07  | MINOR     | LGTM      | NEEDS REV | Updated: both violations[] + violationGroups, enum naming    |
| T08  | MINOR     | LGTM      | NEEDS REV | Updated: physical LOC, enricher placement, edge cases        |
| T09  | MINOR     | LGTM      | NEEDS REV | Updated: T03 dependency, contributor formula, configurable N |
| T10  | NEEDS REV | HIGH RISK | NEEDS REV | **Rewriting with detailed design**                           |
| T11  | MINOR     | LGTM      | NEEDS REV | Updated: config injection, boundary matching, CBO formula    |
| T12  | NEEDS REV | LGTM      | NEEDS REV | **Rewriting with detailed design**                           |

## Decisions log

| #       | Decision                                               | Rationale                                           |
| ------- | ------------------------------------------------------ | --------------------------------------------------- |
| #1      | Raise threshold for VO, not skip                       | VO with 15 params is still a problem                |
| #2      | Stateless-method heuristic, not interface resolve      | No full type resolution needed; covers 95%          |
| #4      | Rename `health`→`html`, new `health` = text            | Breaking change, correct naming                     |
| #5      | Both `violations[]` + `violationGroups` in JSON        | Backward compat for flat array, groups as addition  |
| #7      | Enum cases: `ClassName`/`NamespaceName`                | `class`/`namespace` are reserved words in PHP       |
| #8      | Physical LOC for density, compute in enricher          | Consistent source, no duplication across formatters |
| #9      | Single `@qmx-threshold` annotation with two syntaxes   | Mirrors config threshold/warning/error              |
| #11     | Config via DI parameter + Core VO, not direct coupling | Maintains Metrics→Core dependency direction         |
| #12     | NPath categories only, not CCN/Cognitive               | NPath has exponential scale; others are linear      |
| #13     | Contributors ranked by primary metric per dimension    | Avoid cross-metric normalization complexity         |
| #14     | Always full graph, filter only at reporting            | Correct for instability, ClassRank, Distance        |
| general | No backward compat/migration needed                    | No users yet                                        |
