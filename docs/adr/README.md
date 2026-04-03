# Architecture Decision Records (ADR)

This directory contains Architecture Decision Records — lightweight documents that capture
important design decisions and their rationale.

## When to create an ADR

After implementing a feature that involved non-obvious design decisions. The ADR preserves
the "why" that is not derivable from the code or git history.

## Format

Each ADR is a Markdown file named `NNNN-short-title.md` (e.g., `0001-html-report-design.md`).

```markdown
# NNNN. Short Title

**Date:** YYYY-MM-DD
**Status:** Accepted | Superseded by NNNN

## Context

What problem or question prompted this decision?

## Decision

What was decided and why? Include alternatives considered.

## Consequences

What follows from this decision — trade-offs, constraints, future implications.
```

## Guidelines

- Keep ADRs concise — focus on decisions, not implementation details
- One ADR per coherent set of related decisions (not one per micro-choice)
- Link to the spec (`docs/internal/SPEC_*.md`) if one existed during design phase
- After implementation, the spec can be archived or deleted — the ADR preserves key decisions
- ADRs are immutable once accepted; if a decision is reversed, create a new ADR that supersedes it

## Index

- [0001 — Computed Metrics (Health Scores)](0001-computed-metrics.md) — formula-based health scores, calibration, Expression Language
- [0002 — Interactive HTML Report](0002-html-report.md) — D3 treemap, self-contained HTML, JS build pipeline, hint embedding
- [0003 — Reporting UX Redesign](0003-reporting-ux-redesign.md) — summary-first CLI, progressive disclosure, MetricHintProvider
- [0004 — Architecture Findings (April 2026)](0004-architecture-findings-april-2026.md) — lazy command loading, PSR-3 interpolation, dead code removal
