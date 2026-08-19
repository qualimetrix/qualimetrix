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
**Status:** Proposed | Accepted | Superseded by NNNN

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
- [0005 — Architecture Layer Rules](0005-architecture-rules.md) — `architecture.layer-violation`, namespace-based membership, allow-list semantics, per-use-site reporting, Phase 2 deferrals (Decision 3 superseded by 0006)
- [0006 — Architecture Rules: Declaration-Order Matching](0006-architecture-rules-declaration-order.md) — supersedes 0005 Decision 3; first-match-wins, ordered YAML list, evidence-based `architecture.potential-shadow`, `unreachable-layer`, debug CLI
- [0007 — Architecture Rules Phase 2: Flexibility & Expressiveness](0007-architecture-rules-phase-2-design.md) — locked design for class-membership beyond namespace, template layers with capture binding, `exclude:`, `relations:` whitelist with hybrid alias API
- [0008 — `ArchitectureProcessor` Service](0008-architecture-processor-service.md) — single DI-managed coordinator owning the architecture rules-pipeline lifecycle (bind / prepare / classify); removes scatter across factory, expansion stage, holder, and runtime configurator
- [0009 — YAML Loader Normalization Model](0009-yaml-loader-normalization-model.md) — replaces opt-out preservation with explicit per-section `SectionNormalizationPolicy`; closes the leaf-normalization bug class
- [0010 — Architecture as Vertical Slice (Pilot)](0010-architecture-vertical-slice.md) — `src/Architecture/{Domain,Configuration,Processing,Rules}/`; adapter-exclusion principle (CLI/HTTP/message handlers stay in `Infrastructure`); criteria for applying vertical slice (Part 5 internal freedom superseded by 0016)
- [0011 — Architecture Rules Errata for 0005 and 0007](0011-architecture-rules-errata.md) — corrects ADR 0005 stale `types:` deferral (shipped as `relations:` per ADR 0007), ADR 0007 D7 template-criteria phrasing, info-vs-warning wording for `empty-template`, and D4 `[` metacharacter handling (rejected per M17)
- [0012 — Project Architectural Direction: Hybrid](0012-hybrid-architectural-direction.md) — superseded by ADR 0022; retained as the historical substantial/thin hybrid rationale
- [0013 — Per-Options Threshold Override Validators](0013-threshold-override-validators.md) — replaces the global `warning ≤ error` parser invariant with a per-Options strategy (Standard / Inverted / IndependentAxis / WarningOnly); fixes a Maintainability bug latent across releases and three Design bugs shipped in v0.18; structural tests close the parser-path coverage gap
- [0014 — Deptrac Retirement](0014-deptrac-retirement.md) — drops the `deptrac/deptrac` dev-dependency; `qmx.yaml` took over architecture enforcement (27 layers then, later 30 after [ADR 0016](0016-subject-cohesion.md)); current manifest-generated owner topology is documented by ADR 0022
- [0015 — Typed `AbsolutePath` and `RelativePath` Value Objects](0015-relative-path-vo.md) — replaces ambiguous `string` paths with a two-VO family at every internal boundary (CLI / Git / pipeline / cache / config / reporting); `PathFactory` consolidates string-to-VO conversion; PHPStan `qmx.bannedStringPathProperty` rule guards against regression
- [0016 — Subject Cohesion](0016-subject-cohesion.md) — directories are subjects rather than technical-role buckets; tests for naming, co-change, and duplication guide placement
- [0017 — Baseline Reported-Magnitude Ceiling](0017-baseline-ceiling.md) — version 10 baseline entries bound live finding groups by their reported magnitude or count, with fail-safe staleness and explicit lifecycle commands
- [0018 — Analysis Coverage, Verdict, and Output Projection](0018-analysis-coverage-verdict-and-output-projection.md) — typed discovered-file terminal states, exit 4 for incomplete analysis, formatter coverage projection, and fail-closed artifact writers
- [0019 — Namespace Metric Ownership and Attribution](0019-namespace-metric-ownership-and-attribution.md) — explicit namespace source contributions while physical file bags remain the project-total source
- [0020 — Method Size and NPath Semantics](0020-method-size-and-npath-semantics.md) — dedicated method statement count, MI migration, and recursive expression-path accounting
- [0021 — Declaration-Scoped Callable Identity and Dependency Projections](0021-declaration-scoped-callable-identity-and-dependency-projections.md) — intrinsic declaration identity, callable ownership, and separate architecture/coupling/ClassRank projections
- [0022 — Capability-Oriented Modular Monolith](0022-capability-oriented-modular-monolith.md) — accepted capability-oriented direction, manifest-governed ownership, and final locality boundaries; supersedes ADR 0012
- [0023 — P8 Context Locality and Composition Bindings](0023-p8-context-locality-and-composition-bindings.md) — concrete configuration-document seam, owner-local runtime state, and permanent exact DI composition bindings
- [0024 — Channel Identity and Selector Semantics](0024-channel-identity-and-selector-semantics.md) — selector matching becomes equality with an explicit `X.*` wildcard; the addressed level comes from the directive; `RuleCategory` loses all behaviour; channels declare threshold-override support, file scope, and acceptability; configuration errors gate past `fail_on`; the `annotation.directive` rule reports directives that address nothing


## Index maintenance

Keep the index aligned with accepted ADRs. Architecture locality and permanent
composition-binding policy are defined by ADR 0022 and ADR 0023.
