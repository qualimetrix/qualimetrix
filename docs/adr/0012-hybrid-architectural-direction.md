# 0012. Project Architectural Direction: Hybrid Vertical-Slice / Layered Model

**Date:** 2026-05-15
**Status:** Accepted
**Related:** [0010 — Architecture as Vertical Slice (Pilot)](0010-architecture-vertical-slice.md)

## Context

Qualimetrix has been organized as a horizontal-layer model since inception: `Core` (primitives) ← `Metrics`/`Rules`/`Reporting`/`Configuration` ← `Analysis` ← `Infrastructure`, with deptrac enforcing the downward dependency flow. This worked while features were "compute one metric → emit one violation"-shaped. Recent feature growth has produced multi-feature evidence that the model strains for substantial domain features:

- **`ArchitectureConfigurationHolder`** existed as a cross-layer bridge service whose only purpose was to let the architecture rule reach configuration data that the layered model could not deliver. It is a workaround, not a design.
- **`FrameworkNamespacesHolder`** exhibits a *superficially similar* holder-as-cross-layer-bridge symptom; its root cause (parallel-worker handshake) differs from `ArchitectureConfigurationHolder`. Its existence is a weaker evidence signal but reinforces the broader pattern: cross-layer state in pure-layered model produces holders.
- The `[Proactive Relocation]` memory entry exists because contributors keep struggling with "where does this go?" when a cross-domain primitive could legitimately live in `Core`, in the feature, or in `Configuration`. The recurring confusion is a signal, not an individual mistake.
- **Computed Metrics** already spans `Core` (Expression Language wrapper), `Configuration` (formula loading), and `Metrics` (computed-metric collector) awkwardly, with cross-layer awareness baked into multiple files.
- The user's own global CLAUDE.md states a preference: *"{domain}/{subdomain}/..., но без слоев"* — DDD-aligned in spirit, but the phrasing is too strong for the project's actual situation.

The strain is real, but a pure DDD-without-layers position would be dishonest: cross-cutting infrastructure (Core primitives like `SymbolPath`/`Violation`/`Severity`/`Dependency*`/`NamespaceMatcher`, the CLI base, parallel execution, cache, serialization, AST parsing, graph algorithms, reporting infrastructure) is not domain-specific. It serves every feature. Forcing it into a feature slice would either duplicate it across features or create an artificial "shared" feature that's just `Core` with a different name.

The honest target is a **hybrid**: vertical slice where substantial domain features warrant it, layered where features are thin enough to fit, and a retained cross-cutting infrastructure layer for what is genuinely cross-cutting. This ADR records that direction so future contributors have a framework instead of a one-off decision each time.

## Decision

The project moves toward a **hybrid model** with five rules.

### 1. Substantial domain features → vertical slice

Features meeting the [ADR 0010](0010-architecture-vertical-slice.md) criteria (cross-layer-consuming rule AND independent-lifecycle adapter) organize as:

```
src/{Feature}/
├── Domain/         — types and primitives owned by the feature
├── Configuration/  — feature-specific loaders, factories, validators
├── Processing/     — multi-stage behavior
└── Rules/          — rules that consume the feature's prepared state
```

Internal sub-namespace dependencies are free; external dependencies cross a single domain boundary. Deptrac enforces only that boundary.

A feature may qualify by **analogous complexity** even without the literal two-criteria rule: it has multi-stage processing (config-load → analysis-time preparation → rule consumption) AND a configuration loader complex enough to live separately from `src/Configuration/`. This is case-by-case, not auto-qualifying.

### 2. Thin metric/rule features → layered

Features that fit "compute metric → emit violation" simplicity stay under the horizontal-layer tree: `src/Metrics/{Category}/`, `src/Rules/{Category}/`, `src/Configuration/{Category}/`. Complexity, Cohesion, Coupling, Size, Code Smell, Security, and Design currently fit here. No migration is warranted for any of them.

### 3. Cross-cutting infrastructure → retained layered

`src/Core/` (cross-cutting primitives — `SymbolPath`, `Violation`, `Severity`, `Dependency*`, `NamespaceMatcher`, etc.), `src/Infrastructure/` (CLI, DI, cache, parallel, git, profiler), `src/Reporting/` (formatters), `src/Analysis/` (orchestration — pipeline, discovery, collection). These are not domain-specific; they serve all features. Layered organization fits their dependency structure honestly.

### 4. Adapters → Infrastructure regardless

CLI commands, HTTP endpoints (future), message handlers (future), shell hooks — adapters live in `src/Infrastructure/` regardless of which feature they touch. This is the adapter-exclusion principle from [ADR 0010](0010-architecture-vertical-slice.md), generalized to every feature.

### 5. Migration policy

- **New substantial features:** start vertical from day one if they meet the criteria. Don't build a layered version first and migrate later.
- **Existing layered features:** migrate when natural opportunity arises — a major refactor, growth in complexity, planned remediation. Don't proactively migrate features that work.
- **Architecture is the pilot.** Its migration creates the template: subagent strategy, deptrac migration, DI configurator pattern, manifest format, rollback playbook. Subsequent qualified migrations reuse the playbook rather than re-litigating the approach.

### What this is NOT

- **Not "DDD without layers."** Cross-cutting infrastructure remains layered honestly. The phrasing "{domain}/{subdomain}/..., но без слоев" captures intent but overshoots the actual decision.
- **Not "vertical slice for every feature."** Thin features stay simple — verticalizing them adds organizational overhead without recovering complexity.
- **Not a forced mass-migration campaign.** Migration is opportunistic, criteria-gated, and respects existing working code.

### Future migration candidates (informational, not commitments)

- **Computed Metrics** — an **analogous-complexity candidate**: it has complex formula compilation and multi-stage processing but lacks an independent-lifecycle adapter, so it does not satisfy the two-criteria rule literally. The hybrid model treats it as a candidate for case-by-case decision when next touched for substantial work, not as a definite future migration.
- **HTML Report** — already roughly vertical at `src/Reporting/Template/`. Formalizing as a vertical slice may have low marginal value over the current organization.
- Others remain layered until evidence emerges of the same strain pattern.

### Alternatives considered

- **Full DDD migration project-wide (no layers).** Rejected — cross-cutting infrastructure has no honest domain home; thin features would be over-organized; the migration cost is large with no proportionate benefit.
- **Status quo (pure layered).** Rejected — the strain pattern is proven across at least three independent features (`ArchitectureConfigurationHolder`, `FrameworkNamespacesHolder`, Computed Metrics scatter), plus the recurring relocation confusion captured in memory.
- **Vertical slice for Architecture only, with no longer-term direction.** Rejected — solves the immediate strain but does not address the recurring class. Without ADR 0012, the next feature meeting the same criteria would re-litigate the decision from scratch.

## Consequences

- Architecture serves as the **pilot**; [ADR 0010](0010-architecture-vertical-slice.md) framing reflects this explicitly
- Future ADRs for substantial features can reference the [ADR 0010](0010-architecture-vertical-slice.md) criteria and this direction rather than rebuilding the rationale
- CLAUDE.md project-structure section is updated to describe the hybrid model and provide a decision framework for new features
- No forced migration of existing layered features — convention diversity (some vertical, some layered) becomes explicit and rationalized, not accidental
- Cross-cutting infrastructure layer is preserved with honest intent — "these are genuinely cross-cutting" rather than "we have layers because we forgot to migrate them"
- Reviewers gain a shared vocabulary: "this feature should be vertical because both criteria hold" vs "this stays layered, it's thin"

## References

- Pilot implementation: [ADR 0010 — Architecture as Vertical Slice](0010-architecture-vertical-slice.md)
- Processor service supporting the pilot: [ADR 0008](0008-architecture-processor-service.md)
- Project-structure section in `CLAUDE.md` (updated to describe hybrid model)
- Strain evidence: `ArchitectureConfigurationHolder` (pre-remediation), `FrameworkNamespacesHolder` (`src/Core/Coupling/`), Computed Metrics scatter across `Core` / `Configuration` / `Metrics`
- Prior art (vertical slice / feature-folder discourse):
  - Jimmy Bogard, *Vertical Slice Architecture*
  - Eric Evans, *Domain-Driven Design* (bounded-context boundaries)
