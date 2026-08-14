# P1 — Duplication capability

> **Status:** Completed. Read this historical package record with the [plan overview](../modular-architecture.md) and [decisions and target](decisions-and-target.md).
### P1 — Co-locate Analysis\Evidence\Duplication and isolate its run-scoped result

**Status:** Completed. Implementation, review fixes and final re-review are complete. P1 deliberately absorbs the Duplication-specific isolation formerly deferred to P3. It does not introduce a generic phase participant or bind any of P3's proposed Run-owned ports. The final post-P1 snapshot contains 697 declarations in 695 files, retains 37 semantic owners and 14 singleton seams, and projects 84 exact internal grants to 15 coarse edges. Full PHPUnit passes with 7,206 tests, 21,273 assertions and one skip; architecture generation/check, selfcheck, full CS, P1-scoped PHPStan, cross-tool tests, strict documentation build and leak checks are green. Baseline reconciliation is complete without accepting new debt. Full PHPStan and therefore aggregate `composer check` remain red only for the pre-existing unrelated `LoggerFactory.php:72` `missingType.iterableValue` finding; this is not claimed as P1-green evidence. P2 has since completed; the later P3-A v4-review pause is retained here as historical sequencing evidence and is superseded by P3-A completion below.

#### Boundary and lifecycle

- Move the 17 existing declarations to the leaf module and add one internal run-scoped result provider. `DuplicateBlock`, `DuplicateLocation`, the provider, detector implementation, rule and options are internal module types.
- Replace the current returning detector contract with the one narrow external inspection contract required by `Analysis\Pipeline\MetricEnricher`. Its inspection operation replaces the provider's complete result for that run; a reset operation clears prior state before the enabled/disabled decision. The exact signatures are `reset(): void` and `inspect(array $files): void`, with `@param list<SplFileInfo> $files` on the contract. The rule receives the internal provider by constructor injection and reads `list<DuplicateBlock>` from it; neither Run nor `AnalysisContext` transports that list.
- Remove `duplicateBlocks` from `EnrichmentResult`, `AnalysisContext` and the `AnalysisPipeline` projection in P1. An enabled run followed by a disabled or no-match run must expose an empty provider result, never the previous run's blocks. The disabled path performs only the provider reset: no tokenisation, hash index, duplicate block or candidate-filter allocation.
- Publish only `Analysis\Evidence\Duplication\Contract\DuplicationInspectionInterface`. Its sole temporary exact application consumer is `Qualimetrix\Analysis\Pipeline\MetricEnricher` (`owner: Analysis.Run`, `closes_in: P3`). `Infrastructure.DependencyInjection` retains a permanent owner-wide consumer for composition wiring. The dedicated configurator may scan/register internals but no production declaration outside the module imports an internal Duplication FQCN.
- Do not add PHPDoc-only consumer semantics, transitive contract closure, a qmx seam or a generic phase port in P1. Removing the universal payload eliminates the earlier need to expose `DuplicateBlock`/`DuplicateLocation` and keeps the existing manifest observation model intact.

#### Exact production move map

| Current declaration/file                                    | Target file                                                                     | Target visibility |
| ----------------------------------------------------------- | ------------------------------------------------------------------------------- | ----------------- |
| `src/Analysis/Duplication/ContentHintExtractor.php`         | `src/Analysis/Evidence/Duplication/ContentHintExtractor.php`                    | internal          |
| `src/Analysis/Duplication/DataDeclarationTagger.php`        | `src/Analysis/Evidence/Duplication/DataDeclarationTagger.php`                   | internal          |
| `src/Analysis/Duplication/DuplicateBlockFinder.php`         | `src/Analysis/Evidence/Duplication/DuplicateBlockFinder.php`                    | internal          |
| `src/Analysis/Duplication/DuplicateSearchRequest.php`       | `src/Analysis/Evidence/Duplication/DuplicateSearchRequest.php`                  | internal          |
| `src/Analysis/Duplication/DuplicationDetector.php`          | `src/Analysis/Evidence/Duplication/DuplicationDetector.php`                     | internal          |
| `src/Analysis/Duplication/DuplicationDetectorInterface.php` | `src/Analysis/Evidence/Duplication/Contract/DuplicationInspectionInterface.php` | contract          |
| `src/Analysis/Duplication/HashIndexBuildResult.php`         | `src/Analysis/Evidence/Duplication/HashIndexBuildResult.php`                    | internal          |
| `src/Analysis/Duplication/HashIndexBuilder.php`             | `src/Analysis/Evidence/Duplication/HashIndexBuilder.php`                        | internal          |
| `src/Analysis/Duplication/NormalizedToken.php`              | `src/Analysis/Evidence/Duplication/NormalizedToken.php`                         | internal          |
| `src/Analysis/Duplication/PackedPosition.php`               | `src/Analysis/Evidence/Duplication/PackedPosition.php`                          | internal          |
| `src/Analysis/Duplication/RetokenizedFiles.php`             | `src/Analysis/Evidence/Duplication/RetokenizedFiles.php`                        | internal          |
| `src/Analysis/Duplication/SaturatingCandidateFilter.php`    | `src/Analysis/Evidence/Duplication/SaturatingCandidateFilter.php`               | internal          |
| `src/Analysis/Duplication/TokenNormalizer.php`              | `src/Analysis/Evidence/Duplication/TokenNormalizer.php`                         | internal          |
| `src/Core/Duplication/DuplicateBlock.php`                   | `src/Analysis/Evidence/Duplication/DuplicateBlock.php`                          | internal          |
| `src/Core/Duplication/DuplicateLocation.php`                | `src/Analysis/Evidence/Duplication/DuplicateLocation.php`                       | internal          |
| `src/Rules/Duplication/CodeDuplicationOptions.php`          | `src/Analysis/Evidence/Duplication/CodeDuplicationOptions.php`                  | internal          |
| `src/Rules/Duplication/CodeDuplicationRule.php`             | `src/Analysis/Evidence/Duplication/CodeDuplicationRule.php`                     | internal          |
| new                                                         | `src/Analysis/Evidence/Duplication/DuplicationResultProvider.php`               | internal          |

The four Run/Finding integration files are exact and retain their later owners:

| File                                         | P1 responsibility                                                                             |
| -------------------------------------------- | --------------------------------------------------------------------------------------------- |
| `src/Analysis/Pipeline/MetricEnricher.php`   | Reset the inspection contract every run; invoke inspection only when the producer is enabled. |
| `src/Analysis/Pipeline/EnrichmentResult.php` | Remove the Duplication payload.                                                               |
| `src/Analysis/Pipeline/AnalysisPipeline.php` | Stop projecting Duplication state into rule execution.                                        |
| `src/Core/Rule/AnalysisContext.php`          | Remove the universal `duplicateBlocks` field/constructor argument.                            |

The exact DI/composition, production discovery-comment and runtime-port integration slice is:

| File                                                                              | P1 responsibility                                                                                                     |
| --------------------------------------------------------------------------------- | --------------------------------------------------------------------------------------------------------------------- |
| `src/Infrastructure/DependencyInjection/Configurator/AnalysisConfigurator.php`    | Remove concrete/internal Duplication registration and inject only the contract into Run.                              |
| `src/Infrastructure/DependencyInjection/Configurator/DuplicationConfigurator.php` | New composition adapter: scan detector/provider/rule, alias the contract and preserve `qmx.rule` autoconfiguration.   |
| `src/Infrastructure/DependencyInjection/Configurator/RuleConfigurator.php`        | Update comments that otherwise imply every non-Architecture rule lives under `src/Rules/**`.                          |
| `src/Infrastructure/DependencyInjection/ContainerFactory.php`                     | Invoke the new configurator in deterministic configuration order.                                                     |
| `src/Configuration/ConfigurationProviderInterface.php`                            | Record the real one-consumer CBO fan-in increase at the stable runtime configuration port without hiding the DI edge. |
| `src/Configuration/RuleThresholdKeyGroupRegistry.php`                             | Update comments that otherwise imply every Options class lives under `src/Rules/**`; runtime keys stay unchanged.     |
| `tests/Integration/DependencyInjection/ContainerFactoryTest.php`                  | Prove detector alias, provider injection, rule registration and channel/registry visibility.                          |

#### Exact test and discovery scope

The eight migration-owned tests move to `tests/Analysis/Evidence/Duplication/Unit/`; their generated current total is exactly 75 discovered test cases:

| Current test                                                            | Target test                                                                      | Cases |
| ----------------------------------------------------------------------- | -------------------------------------------------------------------------------- | ----: |
| `tests/Unit/Analysis/Duplication/ContentHintExtractorTest.php`          | `tests/Analysis/Evidence/Duplication/Unit/ContentHintExtractorTest.php`          | 14    |
| `tests/Unit/Analysis/Duplication/DataDeclarationTaggerTest.php`         | `tests/Analysis/Evidence/Duplication/Unit/DataDeclarationTaggerTest.php`         | 15    |
| `tests/Unit/Analysis/Duplication/DuplicationDetectorTest.php`           | `tests/Analysis/Evidence/Duplication/Unit/DuplicationDetectorTest.php`           | 16    |
| `tests/Unit/Analysis/Duplication/DuplicationMemoryLimitProcessTest.php` | `tests/Analysis/Evidence/Duplication/Unit/DuplicationMemoryLimitProcessTest.php` | 2     |
| `tests/Unit/Analysis/Duplication/SaturatingCandidateFilterTest.php`     | `tests/Analysis/Evidence/Duplication/Unit/SaturatingCandidateFilterTest.php`     | 2     |
| `tests/Unit/Analysis/Duplication/TokenNormalizerTest.php`               | `tests/Analysis/Evidence/Duplication/Unit/TokenNormalizerTest.php`               | 10    |
| `tests/Unit/Core/Duplication/DuplicateBlockIdentityTest.php`            | `tests/Analysis/Evidence/Duplication/Unit/DuplicateBlockIdentityTest.php`        | 2     |
| `tests/Unit/Rules/Duplication/CodeDuplicationRuleTest.php`              | `tests/Analysis/Evidence/Duplication/Unit/CodeDuplicationRuleTest.php`           | 14    |

The process memory test must stop deriving the repository root from its legacy directory depth. Its target regression resolves the repository root stably, proves `vendor/autoload.php` and `bin/qmx` exist there, and keeps both the 24 MB candidate-index probe and full CLI duplicate-detection probe green.

The following integration/discovery files stay in place and appear only once in the executable scope:

| File                                                                                                | Guard changed by P1                                                           |
| --------------------------------------------------------------------------------------------------- | ----------------------------------------------------------------------------- |
| `tests/Unit/Analysis/Pipeline/AnalysisPipelineTest.php`                                             | No Duplication payload reaches `AnalysisContext`.                             |
| `tests/Unit/Analysis/Pipeline/MetricEnricherTest.php`                                               | Reset/replace lifecycle, enabled inspection and disabled zero-work path.      |
| `tests/Integration/Violation/ChannelCoverageTest.php`                                               | Moved rule still declares and emits the same channel.                         |
| `tests/Unit/Rules/ThresholdOverrideIntegrationTest.php`                                             | Moved options retain override behaviour; also a rule/options discovery guard. |
| `tests/Integration/Documentation/DocumentationConsistencyTest.php`                                  | Rule discovery includes capability-owned rules outside `src/Rules/**`.        |
| `tests/Unit/Configuration/RuleThresholdKeyGroupRegistryDriftTest.php`                               | Threshold call-site discovery includes the moved rule/options.                |
| `tests/Unit/Rules/ThresholdValidatorAssignmentTest.php`                                             | Threshold-aware Options discovery includes the moved class.                   |
| `tests/Unit/Infrastructure/DependencyInjection/CompilerPass/ChannelDeclarationCompilerPassTest.php` | Its production-rule discovery contract/comments name all current rule roots.  |

`phpunit.xml.dist` adds the exact target Unit directory. The discovery gate compares the before/after `--list-tests` inventory: all 75 existing migrated test IDs remain in the Unit suite exactly once and the old eight paths disappear. P1 adds exactly two lifecycle regressions in `MetricEnricherTest`: `itClearsDuplicationResultsWhenTheNextRunDisablesTheRule` (enabled -> disabled) and `itReplacesDuplicationResultsWhenTheNextEnabledRunFindsNoMatches` (enabled -> no match). Live discovery disproved the earlier `7,200 + 2` estimate because P1-A also adds `itEncodesThePostP1DuplicationBoundary`, `itClassifiesLegacyAndTargetDuplicationTestsWithoutACatchAll` and `itClassifiesTheP1DuplicationModuleReadmeExactly`, while P1-D adds `itWiresTheDuplicationCapabilityThroughItsContractAndRegistries`. The complete six-case delta is those four integration/governance cases plus the two lifecycle cases, for 7,206 full-project cases; any further delta blocks P1.

#### Exact documentation and governance scope

Documentation reviewed/updated atomically with the landed current state:

| File                                                                        | Required update                                                                                                                       |
| --------------------------------------------------------------------------- | ------------------------------------------------------------------------------------------------------------------------------------- |
| `src/Analysis/Evidence/Duplication/README.md`                               | New leaf README: subject, one contract, provider lifecycle, dependencies, owned tests, registration and P3 closure.                   |
| `src/Analysis/README.md`                                                    | Remove the legacy Duplication subtree and universal payload description.                                                              |
| `src/Core/README.md`                                                        | Remove `Core/Duplication`.                                                                                                            |
| `src/Rules/README.md`                                                       | Point Duplication rule/options to their capability owner.                                                                             |
| `src/Infrastructure/README.md`                                              | Add `DuplicationConfigurator` and its exact registration responsibility.                                                              |
| `website/docs/rules/duplication.md`, `website/docs/rules/duplication.ru.md` | Preserve user behaviour while aligning implementation notes/owned location.                                                           |
| `docs/ARCHITECTURE.md`, `AGENTS.md`                                         | Mark P1's physical leaf and result isolation as current without claiming P2+.                                                         |
| `docs/adr/0022-capability-oriented-modular-monolith.md`                     | Record P1 as landed evidence; keep P3 ports non-binding.                                                                              |
| `CHANGELOG.md`                                                              | Add the complete Breaking migration mapping, including FQCN moves, removed universal constructor fields and detector contract change. |
| `docs/internal/plans/modular-architecture.md`                               | Mark P1 complete only after package E's final evidence/review.                                                                        |

Governance edits/outputs are exact: `docs/internal/modular-architecture-manifest.json`; `scripts/generate-modular-architecture-production-inventory.php`; `scripts/generate-modular-architecture-test-inventory.php`; `phpunit.xml.dist`; `qmx.yaml`; `qmx-baseline.json`; all 12 files under `docs/internal/generated/modular-architecture/`; and `tests/Architecture/Integration/ModularArchitectureGovernanceIntegrationTest.php`. The schema and coordinator script are reviewed but unchanged unless their current contracts actually fail. The manifest removes the P1 internal concrete-detector grant, publishes only the inspection contract, records its one temporary exact Run consumer plus permanent Infrastructure consumer, and keeps all other Duplication declarations internal.

Baseline reconciliation is evidence-driven: generate a current candidate and compare it structurally with the pre-P1 ratchet. Re-key the moved `DataDeclarationTagger` FQCN/path/offset entry only if its WMC facts and magnitude/count are unchanged. Classify the existing `MetricEnricher` entry separately as unchanged, identity-re-keyed, resolved by the P1 refactor, or a regression; only the first three outcomes may land, with evidence for the chosen classification. The `ConfigurationProviderInterface` CBO change from 21 to 22 is real and legitimate: `DuplicationConfigurator` adds one explicit consumer to this stable runtime configuration port. Preserve that DI edge, remove the old CBO 21 baseline row, and use the source-level inclusive threshold 23 with a reason; current fan-in 22 passes with no headroom, while the next consumer at 23 fails. Do not accept new debt, bulk-regenerate unrelated entries or require a preset delta count. Cache/serialization compatibility is evidence, not assumed work: the inventory must remain empty for Duplication types in cache, parallel collection and serializer payloads; the P1 move therefore changes no cache key or wire schema.

#### P1 execution packages

```text
P1-A governance intent
  -> P1-B module move
  -> {P1-C Run/Finding isolation || P1-D DI/discovery wiring}
  -> P1-E generated/docs/baseline/validation/review closure
```

- **P1-A — governance intent — Completed:** the only writer of `docs/internal/modular-architecture-manifest.json`, the production/test inventory generator inputs and `ModularArchitectureGovernanceIntegrationTest`. It establishes the reviewed declaration/visibility/consumer/grant policy but does not write generated artifacts or claim a green intermediate generator before B/C/D land.
- **P1-B — module move — Completed:** the 17 moves, new provider, eight owned tests and module README.
- **P1-C — Run/Finding isolation — Completed:** the four Run/Finding production files and the four downstream tests (`AnalysisPipelineTest`, `MetricEnricherTest`, `ChannelCoverageTest`, `ThresholdOverrideIntegrationTest`).
- **P1-D — DI/discovery wiring — Completed:** the seven exact DI/composition, production discovery-comment and runtime-port integration files, including the justified inclusive CBO threshold 23 on `ConfigurationProviderInterface`; `src/Infrastructure/README.md`; the three remaining named discovery guards; and `ChannelDeclarationCompilerPassTest`. It does not edit any B/C path.
- **P1-E — serial integration — Completed:** the only writer of all 12 generated artifacts, `qmx.yaml`, `phpunit.xml.dist`, evidence-driven `qmx-baseline.json` reconciliation (including removal of the old `ConfigurationProviderInterface` CBO 21 row) and the remaining shared documentation/CHANGELOG; it also owns full validation and required review. It runs only after B, C and D all complete and their diffs are independently verified.

B starts only after A. C and D are file-disjoint and may execute in parallel only after B; E starts after both and is the sole generated/qmx/PHPUnit/baseline/remaining-shared-docs writer. No agent uses git operations or runs dependency-mutating commands in the shared tree.

DoD: all 75 existing migrated IDs run exactly once; the six named P1 additions are the two lifecycle, three governance boundary/classification and one DI wiring regressions; and the final full-project count is 7,206. The memory-limit process tests resolve the new root and pass; the dedicated configurator registers the detector, provider and rule, and channel/rule/options registries discover the moved classes. Two runs on one container prove no stale provider state (enabled then disabled, and enabled then no matches). The disabled path performs exactly one O(1) provider reset and zero inspection calls, tokenisation, hashing, duplicate-block creation or candidate-filter allocation. `EnrichmentResult`, `AnalysisContext` and `AnalysisPipeline` have no `duplicateBlocks` field/argument/transport. Exactly one temporary contract consumer exists (`MetricEnricher -> DuplicationInspectionInterface`, `closes_in: P3`), Infrastructure is its permanent composition consumer, and no production declaration outside the module imports a Duplication internal. The internal grant closes, no new seam or taxonomy allow target appears, the generated DAG and every remaining seam pass, baseline reconciliation contains no accepted debt and records the evidence-backed `DataDeclarationTagger` and `MetricEnricher` classifications, cache/wire inventory stays empty, Breaking migration notes are complete, and full PHPUnit, architecture check, selfcheck, full CS, P1-scoped PHPStan, docs build and focused process/registry/topology tests pass. Aggregate `composer check` remains blocked only by the unrelated pre-existing `LoggerFactory.php:72` PHPStan finding recorded in the completed status evidence.
