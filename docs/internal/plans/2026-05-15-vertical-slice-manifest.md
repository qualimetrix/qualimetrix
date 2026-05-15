# Architecture Vertical Slice — File Move Manifest

**Date:** 2026-05-15
**Status:** Intermediate artifact for Phase 2 of `2026-05-15-architecture-rules-remediation.md`. Deleted after Phase 2 lands.
**Scope:** Every Architecture-feature file moved from horizontal layers into `src/Architecture/{Domain,Configuration,Processing,Rules}/` per ADR 0010. Test trees mirror the production layout.

Adapter exception: `LayerAssignmentCommand` stays at `src/Infrastructure/Console/Command/Debug/LayerAssignmentCommand.php` (ADR 0010 Part 2). Not in this manifest.

## Sub-namespace 1 — Domain (`src/Architecture/Domain/`)

Source root: `src/Core/Architecture/` → Destination root: `src/Architecture/Domain/`

| #   | Source                                                            | Destination                                                                            |
| --- | ----------------------------------------------------------------- | -------------------------------------------------------------------------------------- |
| 1   | `src/Core/Architecture/ArchitectureConfiguration.php`             | `src/Architecture/Domain/ArchitectureConfiguration.php`                                |
| 2   | `src/Core/Architecture/ArchitectureConfigurationHolder.php`       | `src/Architecture/Domain/ArchitectureConfigurationHolder.php` (⚠ deleted in Phase 4.7) |
| 3   | `src/Core/Architecture/CoverageMode.php`                          | `src/Architecture/Domain/CoverageMode.php`                                             |
| 4   | `src/Core/Architecture/Allow/AllowListEntry.php`                  | `src/Architecture/Domain/Allow/AllowListEntry.php`                                     |
| 5   | `src/Core/Architecture/Allow/AllowTarget.php`                     | `src/Architecture/Domain/Allow/AllowTarget.php`                                        |
| 6   | `src/Core/Architecture/Allow/CaptureBinding.php`                  | `src/Architecture/Domain/Allow/CaptureBinding.php`                                     |
| 7   | `src/Core/Architecture/Allow/InvalidSelectorException.php`        | `src/Architecture/Domain/Allow/InvalidSelectorException.php`                           |
| 8   | `src/Core/Architecture/Allow/LayerSelector.php`                   | `src/Architecture/Domain/Allow/LayerSelector.php`                                      |
| 9   | `src/Core/Architecture/Allow/LayerSelectorParser.php`             | `src/Architecture/Domain/Allow/LayerSelectorParser.php`                                |
| 10  | `src/Core/Architecture/Allow/ParseCapturedState.php`              | `src/Architecture/Domain/Allow/ParseCapturedState.php`                                 |
| 11  | `src/Core/Architecture/Allow/SelectorKind.php`                    | `src/Architecture/Domain/Allow/SelectorKind.php`                                       |
| 12  | `src/Core/Architecture/Allow/SelectorSegment.php`                 | `src/Architecture/Domain/Allow/SelectorSegment.php`                                    |
| 13  | `src/Core/Architecture/Layer/CapturePattern.php`                  | `src/Architecture/Domain/Layer/CapturePattern.php`                                     |
| 14  | `src/Core/Architecture/Layer/ClassContext.php`                    | `src/Architecture/Domain/Layer/ClassContext.php`                                       |
| 15  | `src/Core/Architecture/Layer/ClassContextFactory.php`             | `src/Architecture/Domain/Layer/ClassContextFactory.php`                                |
| 16  | `src/Core/Architecture/Layer/ClassSet.php`                        | `src/Architecture/Domain/Layer/ClassSet.php`                                           |
| 17  | `src/Core/Architecture/Layer/CriterionListValidator.php`          | `src/Architecture/Domain/Layer/CriterionListValidator.php`                             |
| 18  | `src/Core/Architecture/Layer/ExcludeSpec.php`                     | `src/Architecture/Domain/Layer/ExcludeSpec.php`                                        |
| 19  | `src/Core/Architecture/Layer/InvalidLayerDefinitionException.php` | `src/Architecture/Domain/Layer/InvalidLayerDefinitionException.php`                    |
| 20  | `src/Core/Architecture/Layer/LayerCriteriaMatcher.php`            | `src/Architecture/Domain/Layer/LayerCriteriaMatcher.php`                               |
| 21  | `src/Core/Architecture/Layer/LayerDefinition.php`                 | `src/Architecture/Domain/Layer/LayerDefinition.php`                                    |
| 22  | `src/Core/Architecture/Layer/LayerMatch.php`                      | `src/Architecture/Domain/Layer/LayerMatch.php`                                         |
| 23  | `src/Core/Architecture/Layer/LayerPolicy.php`                     | `src/Architecture/Domain/Layer/LayerPolicy.php`                                        |
| 24  | `src/Core/Architecture/Layer/LayerRegistry.php`                   | `src/Architecture/Domain/Layer/LayerRegistry.php`                                      |
| 25  | `src/Core/Architecture/Layer/MatchMode.php`                       | `src/Architecture/Domain/Layer/MatchMode.php`                                          |
| 26  | `src/Core/Architecture/Layer/MatchedCriterion.php`                | `src/Architecture/Domain/Layer/MatchedCriterion.php`                                   |
| 27  | `src/Core/Architecture/Layer/MatchedCriterionKind.php`            | `src/Architecture/Domain/Layer/MatchedCriterionKind.php`                               |
| 28  | `src/Core/Architecture/Layer/MembershipResult.php`                | `src/Architecture/Domain/Layer/MembershipResult.php`                                   |
| 29  | `src/Core/Architecture/Layer/MembershipSpec.php`                  | `src/Architecture/Domain/Layer/MembershipSpec.php`                                     |
| 30  | `src/Core/Architecture/Layer/TemplateLayerDefinition.php`         | `src/Architecture/Domain/Layer/TemplateLayerDefinition.php`                            |

**Tests (mirror Domain):** `tests/Architecture/Unit/Domain/...`

| #     | Source                                                                 | Destination                                                              |
| ----- | ---------------------------------------------------------------------- | ------------------------------------------------------------------------ |
| D-T1  | `tests/Unit/Core/Architecture/ArchitectureConfigurationHolderTest.php` | `tests/Architecture/Unit/Domain/ArchitectureConfigurationHolderTest.php` |
| D-T2  | `tests/Unit/Core/Architecture/ArchitectureConfigurationTest.php`       | `tests/Architecture/Unit/Domain/ArchitectureConfigurationTest.php`       |
| D-T3  | `tests/Unit/Core/Architecture/CoverageModeTest.php`                    | `tests/Architecture/Unit/Domain/CoverageModeTest.php`                    |
| D-T4  | `tests/Unit/Core/Architecture/Allow/LayerSelectorTest.php`             | `tests/Architecture/Unit/Domain/Allow/LayerSelectorTest.php`             |
| D-T5  | `tests/Unit/Core/Architecture/Layer/CapturePatternTest.php`            | `tests/Architecture/Unit/Domain/Layer/CapturePatternTest.php`            |
| D-T6  | `tests/Unit/Core/Architecture/Layer/ClassContextFactoryTest.php`       | `tests/Architecture/Unit/Domain/Layer/ClassContextFactoryTest.php`       |
| D-T7  | `tests/Unit/Core/Architecture/Layer/ClassSetTest.php`                  | `tests/Architecture/Unit/Domain/Layer/ClassSetTest.php`                  |
| D-T8  | `tests/Unit/Core/Architecture/Layer/ExcludeSpecTest.php`               | `tests/Architecture/Unit/Domain/Layer/ExcludeSpecTest.php`               |
| D-T9  | `tests/Unit/Core/Architecture/Layer/LayerDefinitionTest.php`           | `tests/Architecture/Unit/Domain/Layer/LayerDefinitionTest.php`           |
| D-T10 | `tests/Unit/Core/Architecture/Layer/LayerPolicyTest.php`               | `tests/Architecture/Unit/Domain/Layer/LayerPolicyTest.php`               |
| D-T11 | `tests/Unit/Core/Architecture/Layer/LayerRegistryTest.php`             | `tests/Architecture/Unit/Domain/Layer/LayerRegistryTest.php`             |
| D-T12 | `tests/Unit/Core/Architecture/Layer/TemplateLayerDefinitionTest.php`   | `tests/Architecture/Unit/Domain/Layer/TemplateLayerDefinitionTest.php`   |

## Sub-namespace 2 — Configuration (`src/Architecture/Configuration/`)

Source root: `src/Configuration/Architecture/` → Destination root: `src/Architecture/Configuration/`

| #   | Source                                                                       | Destination                                                                  |
| --- | ---------------------------------------------------------------------------- | ---------------------------------------------------------------------------- |
| 31  | `src/Configuration/Architecture/ArchitectureConfigurationFactory.php`        | `src/Architecture/Configuration/ArchitectureConfigurationFactory.php`        |
| 32  | `src/Configuration/Architecture/ArchitectureFactoryResult.php`               | `src/Architecture/Configuration/ArchitectureFactoryResult.php`               |
| 33  | `src/Configuration/Architecture/Allow/AllowAliasExpander.php`                | `src/Architecture/Configuration/Allow/AllowAliasExpander.php`                |
| 34  | `src/Configuration/Architecture/Validation/AllowValidator.php`               | `src/Architecture/Configuration/Validation/AllowValidator.php`               |
| 35  | `src/Configuration/Architecture/Validation/CoverageValidator.php`            | `src/Architecture/Configuration/Validation/CoverageValidator.php`            |
| 36  | `src/Configuration/Architecture/Validation/ExcludeBlockValidator.php`        | `src/Architecture/Configuration/Validation/ExcludeBlockValidator.php`        |
| 37  | `src/Configuration/Architecture/Validation/LayerCriterionNormalizer.php`     | `src/Architecture/Configuration/Validation/LayerCriterionNormalizer.php`     |
| 38  | `src/Configuration/Architecture/Validation/LayersValidator.php`              | `src/Architecture/Configuration/Validation/LayersValidator.php`              |
| 39  | `src/Configuration/Architecture/Validation/LongFormAllowEntryNormalizer.php` | `src/Architecture/Configuration/Validation/LongFormAllowEntryNormalizer.php` |
| 40  | `src/Configuration/Architecture/Validation/MutualAllowDetector.php`          | `src/Architecture/Configuration/Validation/MutualAllowDetector.php`          |
| 41  | `src/Configuration/Architecture/Validation/WildcardSelfAllowDetector.php`    | `src/Architecture/Configuration/Validation/WildcardSelfAllowDetector.php`    |

**Tests (mirror Configuration):** `tests/Architecture/Unit/Configuration/...`

| #    | Source                                                                               | Destination                                                                          |
| ---- | ------------------------------------------------------------------------------------ | ------------------------------------------------------------------------------------ |
| C-T1 | `tests/Unit/Configuration/Architecture/ArchitectureConfigurationFactoryTest.php`     | `tests/Architecture/Unit/Configuration/ArchitectureConfigurationFactoryTest.php`     |
| C-T2 | `tests/Unit/Configuration/Architecture/Allow/AllowAliasExpanderTest.php`             | `tests/Architecture/Unit/Configuration/Allow/AllowAliasExpanderTest.php`             |
| C-T3 | `tests/Unit/Configuration/Architecture/Validation/AllowValidatorTest.php`            | `tests/Architecture/Unit/Configuration/Validation/AllowValidatorTest.php`            |
| C-T4 | `tests/Unit/Configuration/Architecture/Validation/CoverageValidatorTest.php`         | `tests/Architecture/Unit/Configuration/Validation/CoverageValidatorTest.php`         |
| C-T5 | `tests/Unit/Configuration/Architecture/Validation/LayersValidatorTest.php`           | `tests/Architecture/Unit/Configuration/Validation/LayersValidatorTest.php`           |
| C-T6 | `tests/Unit/Configuration/Architecture/Validation/MutualAllowDetectorTest.php`       | `tests/Architecture/Unit/Configuration/Validation/MutualAllowDetectorTest.php`       |
| C-T7 | `tests/Unit/Configuration/Architecture/Validation/WildcardSelfAllowDetectorTest.php` | `tests/Architecture/Unit/Configuration/Validation/WildcardSelfAllowDetectorTest.php` |

## Sub-namespace 3 — Processing (`src/Architecture/Processing/`)

Source root: `src/Analysis/Architecture/` → Destination root: `src/Architecture/Processing/`

| #   | Source                                                  | Destination                                               |
| --- | ------------------------------------------------------- | --------------------------------------------------------- |
| 42  | `src/Analysis/Architecture/LayerExpansionException.php` | `src/Architecture/Processing/LayerExpansionException.php` |
| 43  | `src/Analysis/Architecture/LayerExpansionResult.php`    | `src/Architecture/Processing/LayerExpansionResult.php`    |
| 44  | `src/Analysis/Architecture/LayerExpansionStage.php`     | `src/Architecture/Processing/LayerExpansionStage.php`     |

**Tests (mirror Processing):** `tests/Architecture/Unit/Processing/...`

| #    | Source                                                         | Destination                                                      |
| ---- | -------------------------------------------------------------- | ---------------------------------------------------------------- |
| P-T1 | `tests/Unit/Analysis/Architecture/LayerExpansionStageTest.php` | `tests/Architecture/Unit/Processing/LayerExpansionStageTest.php` |

Note: `ArchitectureProcessor` + `ArchitectureProcessorInterface` are added in Phase 4 (ADR 0008), not part of this manifest.

## Sub-namespace 4 — Rules (`src/Architecture/Rules/`)

Source root: `src/Rules/Architecture/` → Destination root: `src/Architecture/Rules/`

| #   | Source                                                 | Destination                                            |
| --- | ------------------------------------------------------ | ------------------------------------------------------ |
| 45  | `src/Rules/Architecture/CircularDependencyOptions.php` | `src/Architecture/Rules/CircularDependencyOptions.php` |
| 46  | `src/Rules/Architecture/CircularDependencyRule.php`    | `src/Architecture/Rules/CircularDependencyRule.php`    |
| 47  | `src/Rules/Architecture/LayerViolationOptions.php`     | `src/Architecture/Rules/LayerViolationOptions.php`     |
| 48  | `src/Rules/Architecture/LayerViolationRule.php`        | `src/Architecture/Rules/LayerViolationRule.php`        |

**Tests (mirror Rules):** `tests/Architecture/Unit/Rules/...`

| #    | Source                                                         | Destination                                                    |
| ---- | -------------------------------------------------------------- | -------------------------------------------------------------- |
| R-T1 | `tests/Unit/Rules/Architecture/CircularDependencyRuleTest.php` | `tests/Architecture/Unit/Rules/CircularDependencyRuleTest.php` |
| R-T2 | `tests/Unit/Rules/Architecture/CoverageDiagnosticsTest.php`    | `tests/Architecture/Unit/Rules/CoverageDiagnosticsTest.php`    |
| R-T3 | `tests/Unit/Rules/Architecture/LayerViolationOptionsTest.php`  | `tests/Architecture/Unit/Rules/LayerViolationOptionsTest.php`  |
| R-T4 | `tests/Unit/Rules/Architecture/LayerViolationRuleTest.php`     | `tests/Architecture/Unit/Rules/LayerViolationRuleTest.php`     |

## Integration / Support / Fixtures (moved alongside Domain commit)

These do not split by sub-namespace, so they move with the **Domain** commit to keep `composer test` green from the first move onward.

| #    | Source                                                                     | Destination                                                                |
| ---- | -------------------------------------------------------------------------- | -------------------------------------------------------------------------- |
| I-T1 | `tests/Integration/Architecture/CaptureBindingIntegrationTest.php`         | `tests/Architecture/Integration/CaptureBindingIntegrationTest.php`         |
| I-T2 | `tests/Integration/Architecture/LayerCriteriaIntegrationTest.php`          | `tests/Architecture/Integration/LayerCriteriaIntegrationTest.php`          |
| I-T3 | `tests/Integration/Architecture/LayerExcludeIntegrationTest.php`           | `tests/Architecture/Integration/LayerExcludeIntegrationTest.php`           |
| I-T4 | `tests/Integration/Architecture/LayerTemplateExpansionIntegrationTest.php` | `tests/Architecture/Integration/LayerTemplateExpansionIntegrationTest.php` |
| I-T5 | `tests/Integration/Architecture/LayerViolationIntegrationTest.php`         | `tests/Architecture/Integration/LayerViolationIntegrationTest.php`         |
| I-T6 | `tests/Integration/Architecture/Phase1ConfigCompatibilityTest.php`         | `tests/Architecture/Integration/Phase1ConfigCompatibilityTest.php`         |
| I-T7 | `tests/Integration/Architecture/RelationsFilterIntegrationTest.php`        | `tests/Architecture/Integration/RelationsFilterIntegrationTest.php`        |
| S-T1 | `tests/Support/Architecture/AllowListBuilder.php`                          | `tests/Architecture/Support/AllowListBuilder.php`                          |
| F-1  | `tests/Fixtures/ArchitectureCaptureBindingSample/`                         | `tests/Architecture/Fixtures/CaptureBindingSample/`                        |
| F-2  | `tests/Fixtures/ArchitectureCriteriaSample/`                               | `tests/Architecture/Fixtures/CriteriaSample/`                              |
| F-3  | `tests/Fixtures/ArchitectureExcludeSample/`                                | `tests/Architecture/Fixtures/ExcludeSample/`                               |
| F-4  | `tests/Fixtures/ArchitectureRelationsSample/`                              | `tests/Architecture/Fixtures/RelationsSample/`                             |
| F-5  | `tests/Fixtures/ArchitectureSample/`                                       | `tests/Architecture/Fixtures/Sample/`                                      |
| F-6  | `tests/Fixtures/ArchitectureTemplateSample/`                               | `tests/Architecture/Fixtures/TemplateSample/`                              |

Fixture directory rename strips the `Architecture` prefix because the parent path (`tests/Architecture/Fixtures/`) already supplies the disambiguation. Fixture-path references inside Integration tests update accordingly.

## Out of scope (stay where they are)

- `src/Infrastructure/Console/Command/Debug/LayerAssignmentCommand.php` — adapter (ADR 0010 Part 2)
- `src/Configuration/Pipeline/Stage/CliStage.php` and other generic Configuration pipeline plumbing — cross-cutting Configuration infra, not Architecture-specific
- `src/Core/Rule/RuleInterface.php` and other generic Core primitives — cross-cutting Core infra
- `src/Core/Dependency/*` — generic graph/dependency primitives consumed by Architecture but not Architecture-specific

## Commit batching

Per remediation plan §2.2 and user-confirmed strategy:

1. **Commit 1** — `docs(plan): commit vertical-slice manifest` — this file
2. **Commit 2** — `refactor(architecture): move Domain sub-namespace to src/Architecture/Domain/` — 30 production + 12 unit + 7 integration + 1 support + 6 fixtures (Domain is the foundation; Integration/Support/Fixtures ride with it to keep tests green)
3. **Commit 3** — `refactor(architecture): move Configuration sub-namespace to src/Architecture/Configuration/` — 11 production + 7 unit tests
4. **Commit 4** — `refactor(architecture): move Processing sub-namespace to src/Architecture/Processing/` — 3 production + 1 unit test
5. **Commit 5** — `refactor(architecture): move Rules sub-namespace to src/Architecture/Rules/` — 4 production + 4 unit tests

After each commit: `composer dump-autoload && composer check`. If composer check fails, the failing commit is reverted before continuing.

Phase 2.4 (deptrac.yaml), 2.6 (DI configurator), 2.7 (READMEs + CLAUDE.md hybrid section) land as a **single follow-up commit** "refactor(architecture): wire up vertical slice (deptrac, DI, READMEs)" after all five moves succeed.

## Tooling

- **Primary:** PhpStorm MCP `rename_refactoring` for the first file in each sub-namespace as a smoke test of semantic refactoring with automatic import updates. If smoke test succeeds, continue with PhpStorm MCP for the rest of the sub-namespace.
- **Fallback:** `git mv` + scripted namespace + `use` import update across the project + `composer dump-autoload`. Used if PhpStorm MCP smoke test fails or behaves unexpectedly.

## Verification after each commit

1. `composer dump-autoload` — autoloader regenerated
2. `composer test` — all tests pass
3. `composer phpstan` — PHPStan L8 clean
4. `vendor/bin/deptrac` — passes (will fail until Phase 2.4 deptrac.yaml updated; expected on intermediate commits; final follow-up commit closes the gap)
5. `grep -rn "Qualimetrix\\\\(Core|Configuration|Analysis|Rules)\\\\Architecture" src/ tests/` — should drop to zero after all five sub-namespace moves complete

Deptrac is expected to be RED on intermediate commits because the new `Architecture` layer is not yet declared. The follow-up "wire up" commit closes deptrac in a single transaction.

## Rollback

If a commit's `composer check` fails irrecoverably, `git revert <commit>` and re-execute the failing sub-namespace in smaller batches. Manifest is the recovery spec.

## Deletion

After Phase 2 lands successfully and is reviewed in Phase 7, this manifest file is deleted per CLAUDE.md plan-cleanup policy.
