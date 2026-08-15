# P0 — authoritative ownership and fail-closed enforcement

> **Status:** Completed. This is the implementation and closure record for the governance foundation.
> **Prerequisites:** [Plan overview](../modular-architecture.md) and [decisions and target](decisions-and-target.md).
### P0 — Establish authoritative ownership and fail-closed enforcement — Completed

**Status:** Completed. Final verification is green: the manifest covers 695 declarations in 693 files and 37 semantic owners; the generated projection has 14 singleton seams, 52 total layers and 296 allow edges; all 85 exact internal grants project to 16 coarse edges. Full PHPUnit passed with 7,200 tests and 21,118 assertions, and architecture governance plus selfcheck are green. P1 has since completed; no later P2-P8 physical move or P3 phase-port contract was implied by P0 completion alone.

P0 was split into executable, non-overlapping file slices. The manifest and its qmx projection form one atomic enforcement package. Shared outputs belong to that slice only; later slices consume them read-only.

#### P0-A — Authoritative ownership and enforcement — Completed

Files: `docs/internal/modular-architecture-manifest.json`, `docs/internal/modular-architecture-manifest.schema.json`, and narrow `.gitignore` exceptions for exactly those two otherwise ignored JSON files; modular-architecture inventory/projection generators; the generated ownership/allow region and its immediately preceding topology header comments in `qmx.yaml`; generated production/import/participant/state/Reporting/test/fixture/documentation/PHPUnit TSV or text artifacts; the `composer check` script entry that invokes generator `--check`; qmx projection/invariant tests and architecture topology fixtures; the enforcement-foundation production/tests below. No ADR, general documentation, baseline or plan file.

**Verified:** manifest/AST coverage, exact consumers/grants, generated 37-owner + 14-seam + `external` DAG, freshness checking, isolated-class coverage and exact declared-cycle validation are green.

The only new `.gitignore` exceptions are `!docs/internal/modular-architecture-manifest.json` and `!docs/internal/modular-architecture-manifest.schema.json`; the repository-wide JSON ignore policy otherwise remains intact.

Enforcement-foundation file scope:

- coverage: `src/Architecture/Rules/LayerViolationRule.php` and `tests/Architecture/Unit/Rules/CoverageDiagnosticsTest.php`;
- exact allow-cycle validation and wiring: `src/Architecture/Configuration/Validation/ExactAllowCycleValidator.php`, `src/Architecture/Configuration/Validation/AllowValidator.php`, `src/Architecture/Configuration/ArchitectureConfigurationFactory.php`, `tests/Architecture/Unit/Configuration/Validation/ExactAllowCycleValidatorTest.php`, `tests/Architecture/Unit/Configuration/Validation/AllowValidatorTest.php` and `tests/Architecture/Unit/Configuration/ArchitectureConfigurationFactoryTest.php`;
- warning-contract migration: remove `src/Architecture/Configuration/Validation/MutualAllowDetector.php` and `tests/Architecture/Unit/Configuration/Validation/MutualAllowDetectorTest.php`; retain and revalidate the real warning in `src/Architecture/Configuration/Validation/WildcardSelfAllowDetector.php` and `tests/Architecture/Unit/Configuration/Validation/WildcardSelfAllowDetectorTest.php`; align its deferred-warning wiring/comments in `src/Architecture/Configuration/Validation/LongFormAllowEntryNormalizer.php`, `src/Configuration/Pipeline/ConfigurationPipeline.php`, `src/Infrastructure/DependencyInjection/Configurator/ConfigurationConfigurator.php`, `src/Infrastructure/Console/RuntimeConfigurator.php`, `tests/Integration/Configuration/DeferredWarningIntegrationTest.php`, `tests/Unit/Configuration/Pipeline/ConfigurationPipelineTest.php`, `tests/Unit/Infrastructure/Console/RuntimeConfiguratorTest.php` and the comments-only example in `tests/Functional/Console/Command/CheckCommandTest.php`.

- Replace path heuristics, inferred visibility and inferred consumers with exact manifest declarations and exact observed import-pair checks.
- Preserve AST facts independently from policy: the parser discovers declarations/imports; the manifest supplies true owner, `internal|contract`, structured per-FQCN consumers with lifecycle, exact temporary internal grants and singleton seams.
- Make coverage account for canonical-deduplicated analysed classes even when the dependency graph has no edges, so an isolated uncovered class fails while an owned isolated class remains clean.
- Preserve exact self-edges through allow normalization, then run `ExactAllowCycleValidator` from `ArchitectureConfigurationFactory` before analysis; reject exact self-, two- and three-plus-node declared allow cycles. Do not duplicate actual code-cycle detection.
- Remove mutual-allow warning semantics and its detector entirely; mutual exact allows are now a hard two-node cycle error, while the unrelated `wildcard-self-allow` warning continues through the deferred-warning pipeline.
- Derive coarse semantic-owner/seam qmx allows from used permanent and temporary per-FQCN consumer entries and exact grants. The 85 internal pairs currently contribute 16 coarse owner edges; never describe a qmx edge as an exact grant.
- Generate one layer per 37 semantic owners, the exact 14 singleton seams above, and final `external`. Visibility is never part of a qmx layer name or membership rule. `external` excludes `Qualimetrix\**`, project layers may depend on it, and it depends on none.
- Keep `Analysis`, `Evidence`, `Policy` and every unlisted child namespace uncovered; no owner/category template or broad role-bucket pattern may enrol a new declaration.
- Emit `closure_package` for every movable production/test/documentation row, temporary contract consumer and temporary internal grant. Counts in reports are computed from input, never asserted as generator constants.
- Implement normal write mode and a no-write `--check` comparison mode; validate the declared qmx allow graph as a DAG before analysis, while actual code-cycle detection remains a separate rule.

DoD: for the reviewed snapshot, 695 declarations in 693 files map 1:1 to the AST and exactly one of 37 owners; all 771 structured consumer entries are explicit and used, and every observed cross-owner import matches one. Permanent entries have `source_fqcn: null` and `closes_in: null`; temporary entries name an exact source FQCN and closure package, their source semantic owner equals `owner`, and a fourth same-owner source declaration is rejected; internal declarations cannot publish consumers. All 85 unique imports whose target is `internal` match used exact source-FQCN/target-FQCN grants; they contribute 16 coarse owner edges, and every other cross-owner import enabled only by a coarse qmx edge is rejected by the manifest checker before selfcheck. Unused consumers, projected allows, exact grants and projected grant edges fail. The qmx projection has 37 non-empty owner layers plus 14 singleton seams and `external` (52 total); the 51-vertex/245-edge internal graph and 52-vertex/296-edge final graph are DAGs, and returning any seam to its true owner recreates a cycle. qmx membership equals manifest semantic owner except for the declared enforcement layer of those 14 exact FQCNs; visibility never changes qmx membership, and a new declaration or import cannot add a consumer, layer, allow or grant during regeneration. Focused regressions prove an isolated uncovered class fails even with an empty dependency graph, an owned isolated class stays clean, repository/edge class evidence deduplicates, and ignore mode remains unchanged. Exact declared self-, two- and three-plus-node cycles fail before analysis; no production/test reference to `MutualAllowDetector` or active mutual-allow warning remains, and the `wildcard-self-allow` deferred-warning seam remains green. Topology fixtures additionally prove same-owner internal/contract imports remain inside one owner layer, permanent and exact temporary contract imports are checked by the manifest, a fourth same-owner temporary source and an unlisted cross-owner internal pair fail despite a coarse qmx allow, seam diagnostics retain true manifest owner, taxonomy-root and unlisted-child declarations are uncovered, and uncovered endpoint/isolated class/actual class-cycle cases fail. The two internal manifest JSON files are not ignored while unrelated JSON policy stays unchanged. Re-run the existing 304-test Architecture/configuration/DI/console seam evidence, all inventories, mandatory manifest check before selfcheck, `composer check` freshness and selfcheck; all are green and `--check` leaves the worktree byte-for-byte unchanged. The 695/771/85/16/14 and graph counts are generated snapshot evidence, never hardcoded invariants.

#### P0-B — Documentation and ADR 0022 alignment — Completed

Files: ADR 0022, `docs/ARCHITECTURE.md`, affected source/component READMEs, root/project instructions and module README template. No manifest, generator, qmx, tests, plan or baseline file.

**Verified:** ADR 0022 is accepted, ADR 0012 is superseded, current manifest/qmx enforcement is distinguished from pending P1-P8 physical moves, and phase ports remain explicitly non-binding until P3.

- Supersede the “large vertical / thin layered” rule while preserving its historical rationale.
- Document taxonomy-only containers, leaf ownership, contract publication, manifest authority, generated enforcement and the fact that generated inventories are neither runtime metadata nor DI registration.
- Preserve the distinction between current implemented governance (authoritative 695-declaration/37-owner manifest plus generated 37-owner + 14-seam + `external` projection) and the pending P1-P8 physical layout. Retain the former 41-layer draft and 60/9 status probe only as rejected feasibility history, and describe phase ports only as non-binding hypotheses informed by P0/P1/P2.
- Record ADR 0022 as `Accepted` after the green P0-D review; no other package owns those decision-status documents. Completed.

DoD: accepted-state docs accurately describe P0 enforcement as current without claiming P1-P8 moves; public docs do not expose an internal manifest/qmx option; ADR alternatives, rejected projections and the 14 temporary enforcement seams match this design gate; direct `bin/qmx check` is distinguished from mandatory repository governance; final P0 completion rechecked all docs for current/target consistency.

#### P0-C — Baseline reconciliation — Completed

Files: `qmx-baseline.json` and baseline-specific verification evidence only. No manifest, generator, qmx configuration, topology test, plan or documentation file.

**Verified:** selfcheck is green with no architecture coverage/layer escape hatch in the v11 ratchet.

- Reconcile only findings whose identity changes because fail-closed topology becomes a hard gate.
- Review any residual snapshot delta; do not regenerate accepted debt mechanically and do not baseline architecture coverage or layer violations.

DoD: `composer selfcheck` has no new topology violation; any baseline delta is minimal, explained and contains no ownership/grant escape hatch.

#### P0-D — Final plan review gate — Completed

Files: this plan only. P0-D read P0-A evidence, P0-B's staged docs, P0-C evidence and all generated inventories but edited no implementation/configuration/documentation artifact.

**Verified:** the final independent governance review returned GO; generated inventories and qmx are fresh, the exact/coarse enforcement seam is covered, and full PHPUnit plus architecture/selfcheck are green.

- Materialise P1–P8's exact production/test/documentation scope through authoritative generated TSV rows whose `closure_package` is exactly P1 through P8, rather than duplicating hundreds of paths in prose. Every non-migration documentation row uses exactly one of `P0-D`, `permanent`, or `shared` and is not silently assigned to a migration package by filename substrings.
- Record design-gate evidence, resolved dispositions and package dependencies, then perform a fresh architecture-plan review.
- Authorise P0-B's `Proposed -> Accepted` documentation closure after enforcement, baseline and plan findings are resolved. Completed.

DoD: the review confirmed manifest/AST 1:1 coverage, all 85 exact internal import-pair checks and their 16 coarse owner projections, all 771 explicit/used structured consumers with valid permanent owner-wide or temporary exact-source lifecycle, 37 non-empty owner layers + 14 inclusion-minimal seams + external, 245-edge internal and 296-edge final DAGs, non-inferred imports, used closure-named exact grants, narrow manifest JSON ignore exceptions, `composer check` freshness enforcement and the complete topology fixture matrix. Documentation inventory discovery is committable/reproducible, root instructions and shared governance documents have explicit non-migration dispositions, and no generated P1-P8 row contradicts a P0/shared owner. P0-A, P0-B, P0-C and P0-D are complete; the generated 52-layer projection is current enforcement, while the rejected 41-layer draft and rejected 60/9 status projection remain historical evidence only.

For every P1–P8 package, rows bearing that closure in the generated production, test and documentation inventories are the authoritative enumeration of artifacts whose **semantic ownership or physical location migrates** in that package. They are not the complete executable edit set. Each package must additionally enumerate its exact integration consumers, DI adapters, discovery guards, governance inputs/outputs and current-layout documentation; touching those files does not change their later semantic owner or closure package. Documentation rows marked `P0-D`, `permanent`, or `shared` remain governance/lifecycle dispositions, but a package updates them when its landed current state would otherwise make them false. The package first changes manifest policy deliberately, applies its physical and integration edits, regenerates the 37-owner/remaining-seam qmx projection and every inventory, proves the new projection DAG and every remaining seam necessary, and finally runs the generator in `--check` mode. Generated TSVs enumerate migration-owned moves and verify freshness; they are not runtime manifests, DI registries or substitutes for the package's explicit integration list.
