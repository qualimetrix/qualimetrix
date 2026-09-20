# Architecture Decision Records

This directory contains Architecture Decision Records: concise documents that
capture durable design choices and the reasons behind them.

## When to create an ADR

Create an ADR after a feature introduces a non-obvious decision that cannot be
recovered reliably from code and tests. Audits, inventories, execution plans,
measurement protocols, and implementation diaries belong with the subject or
tool that owns them, not in this index.

## Format

Each ADR is a Markdown file named `NNNN-short-title.md`.

```markdown
# NNNN. Short Title

**Date:** YYYY-MM-DD
**Status:** Proposed | Accepted | Superseded by NNNN

## Context

What problem or question prompted this decision?

## Decision

What was decided and why? Include alternatives considered.

## Consequences

What follows from the decision: trade-offs, constraints, and implications.
```

## Guidelines

- Keep the record concise and separate durable decisions from implementation
  evidence.
- Keep one coherent subject per ADR; use links when another accepted decision
  owns part of the contract.
- An accepted ADR is historical evidence. When its decision changes, create a
  new ADR and mark the old record as superseded.
- Current implementation details belong in the owning component README.
- A current ADR must be self-contained and must not depend on an execution plan.

## Current decisions

- [0001 — Computed Metrics](0001-computed-metrics.md) — formula-based computed metrics and health scores.
- [0002 — Interactive HTML Report](0002-html-report.md) — self-contained interactive report delivery.
- [0003 — Reporting UX Redesign](0003-reporting-ux-redesign.md) — summary-first reporting and progressive disclosure.
- [0006 — Declaration-Order Matching](0006-architecture-rules-declaration-order.md) — first matching declared layer wins.
- [0009 — YAML Loader Normalization](0009-yaml-loader-normalization-model.md) — schema-owned normalization policy.
- [0013 — Threshold Override Validators](0013-threshold-override-validators.md) — options-owned threshold validation.
- [0014 — Deptrac Retirement](0014-deptrac-retirement.md) — project-owned architecture enforcement replaces deptrac.
- [0015 — Typed Paths](0015-relative-path-vo.md) — typed absolute and relative path boundaries.
- [0016 — Subject Cohesion](0016-subject-cohesion.md) — directories and module boundaries follow subjects.
- [0017 — Baseline Ceiling](0017-baseline-ceiling.md) — baselines cap the reported magnitude they accepted.
- [0018 — Coverage, Verdict, and Projection](0018-analysis-coverage-verdict-and-output-projection.md) — analysis completeness is separate from verdict and presentation.
- [0019 — Namespace Metric Ownership](0019-namespace-metric-ownership-and-attribution.md) — explicit namespace contribution and attribution.
- [0020 — Method Size and NPath](0020-method-size-and-npath-semantics.md) — method-size and recursive NPath semantics.
- [0021 — Declaration-Scoped Identity](0021-declaration-scoped-callable-identity-and-dependency-projections.md) — callable identity and dependency projections, partially superseded by 0026.
- [0022 — Capability-Oriented Modular Monolith](0022-capability-oriented-modular-monolith.md) — capability ownership, manifest governance, and module topology.
- [0023 — Context Locality and Composition Bindings](0023-p8-context-locality-and-composition-bindings.md) — owner-local state and exact private composition.
- [0024 — Channel Identity and Selectors](0024-channel-identity-and-selector-semantics.md) — channel addressing and selection semantics.
- [0026 — Assigned Declaration Ordinal](0026-assigned-declaration-ordinal.md) — stable declaration ordering and its named limits.
- [0027 — Weight of Class](0027-weight-of-class-measures-accessors-not-visibility.md) — accessor-based WOC semantics.
- [0029 — Channel Presentation Join](0029-channel-presentation-join.md) — presentation joins channel and producer metadata at run time.
- [0030 — One Rule per Judgement](0030-one-rule-per-type-coverage-dimension.md) — independent judgements have independent producers.
- [0031 — Producer-Owned Channel Shape](0031-channel-shape-is-a-producer-property.md) — a producer declares magnitude or occurrence shape.
- [0032 — Computed-Metric Producer Split](0032-computed-metric-producer-split.md) — one producer name per closed built-in definition.
- [0033 — Derived Display Family](0033-display-family-is-derived-from-the-producer-name.md) — presentation family follows the producer name.
- [0034 — Symbol Level](0034-the-level-is-a-coordinate-of-a-symbol.md) — level is a coordinate of symbol identity.
- [0035 — Metric Key Grammar](0035-a-metric-key-names-its-family-in-kebab.md) — metric keys name their subject family in kebab case.
- [0036 — Formula Metric Addressing](0036-a-formula-addresses-a-metric-by-its-key.md) — formulas address metrics by published key.
- [0037 — Suppressed Output](0037-suppressed-format-and-produced-findings.md) — produced, published, and suppressed findings remain distinct.
- [0038 — Options-Owned Warning Boundary](0038-an-options-class-names-its-own-warning-boundary.md) — options expose their configured warning boundary.
- [0039 — Directive Audit Contract](0039-directive-audit-command-and-contract.md) — the directive audit is a separate four-verdict contract.
- [0040 — Narrow Directive Sweep](0040-narrow-directive-sweep.md) — threshold directives are checked by re-executing their producer.
- [0041 — Unsuppressible Unused-Directive Channel](0041-no-directive-may-silence-the-unused-directive-channel.md) — a directive cannot hide its own ineffectiveness.
- [0043 — Late-Assembled Finding Selection](0043-late-assembled-findings-and-channel-selection.md) — late findings obey channel selection, not a frozen exclusion ledger.
- [0044 — Identifier-Keyed Configuration](0044-identifier-keyed-configuration-options.md) — authored identifier spelling survives normalization.
- [0045 — Error Stream Ownership](0045-one-owner-for-the-error-stream.md) — one adapter owns diagnostics and progress interaction.
- [0046 — Judged Metric Declaration](0046-a-channel-declares-the-metric-it-judges.md) — channels declare the metric they judge.
- [0047 — Suppression and Exclusion](0047-suppression-is-not-exclusion.md) — produced-and-hidden findings use suppression vocabulary.
- [0049 — Rule Option Recognition](0049-rule-option-key-recognition.md) — option keys are recognized at every declared depth or refused.
- [0050 — Configuration Refusal Carrier](0050-configuration-refusal-carrier.md) — Configuration owns the typed bad-input carrier.
- [0051 — Refusal Routing](0051-refusal-is-not-routed-by-command.md) — throwable kind, not command, determines refusal presentation.
- [0055 — Rule Option Value Shape](0055-a-rule-option-declares-the-shape-of-its-value.md) — accepted option keys declare their value shape.
- [0058 — Layered Value Survival](0058-a-layers-value-survives-the-layers-above-it.md) — higher layers preserve values they did not rewrite.
- [0059 — Declared-Layer Policy and Architecture Governance](0059-declared-layer-policy-and-architecture-governance.md) — current layer-policy semantics and manifest authority.
- [0060 — Published Vocabulary and Name Ownership](0060-published-vocabulary-and-name-ownership.md) — current naming grammar, owners, and completeness rule.
- [0061 — Configuration Miss and Refusal Semantics](0061-configuration-miss-and-refusal-semantics.md) — final miss classification and refusal contract.
- [0062 — Health Scores Measure What They Cover](0062-health-scores-measure-what-they-cover.md) — aggregation, corpus floor, and recalibrated thresholds for the six health dimensions.
- [0063 — One Declaration Answers About a Rule's Options](0063-one-declaration-answers-about-a-rules-options.md) — the rules listing and the option refusal read one declaration.
- [0064 — The HTML Viewer Lives Outside the PSR-4 Root](0064-the-html-viewer-lives-outside-the-psr-4-root.md) — the HTML report's browser program is a root-level subject, not a PSR-4 path.
- [0065 — The Manifest Records Ownership, Not a Migration Schedule](0065-the-manifest-records-ownership-not-a-migration-schedule.md) — the migration's package labels leave the manifest; a consumer is owner-wide or exact, and never dated.
- [0067 — Subprocess Read Discipline](0067-subprocess-read-discipline.md) — one drain-safe way to run a child process and capture its output, with supervision left where it is.

## Superseded history

- [0008 — ArchitectureProcessor Service](0008-architecture-processor-service.md) — replaced by the capability-oriented topology in ADR 0022.
- [0010 — Architecture Vertical-Slice Pilot](0010-architecture-vertical-slice.md) — replaced by subject cohesion and ADR 0022.
- [0012 — Hybrid Architectural Direction](0012-hybrid-architectural-direction.md) — replaced by ADR 0022; its HTML-viewer placement prescription by ADR 0064.
- [0056 — Source Composition Loses the Middle Layer's Value](0056-source-composition-is-measured-and-left-alone.md) — corrected by ADR 0058.
