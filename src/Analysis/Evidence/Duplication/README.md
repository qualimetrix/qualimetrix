# Duplication

## Subject, promise and owners

- **Subject:** token- and block-based code duplication evidence.
- **Promise:** inspect one discovered PHP file set, retain the complete result
  for one analysis run, and emit `duplication.code-duplication` findings from
  that result.
- **Semantic owner:** `Analysis.Evidence.Duplication`.
- **Owned paths:** `src/Analysis/Evidence/Duplication/`,
  `tests/Analysis/Evidence/Duplication/`, and the English/Russian Duplication
  rule pages.
- **Non-goals:** file discovery and run sequencing belong to `Analysis.Run`;
  configuration source merging belongs to `Analysis.Configuration`; Finding
  owns rule and finding primitives plus `Analysis\Finding\RuleExecution`.

## Structure

```text
Duplication/
├── DuplicationDetector.php
├── DuplicationResultProvider.php
├── ContentHintExtractor.php
├── DataDeclarationTagger.php
├── DuplicateBlockFinder.php
├── DuplicateSearchRequest.php
├── HashIndexBuildResult.php
├── HashIndexBuilder.php
├── NormalizedToken.php
├── PackedPosition.php
├── RetokenizedFiles.php
├── SaturatingCandidateFilter.php
├── TokenNormalizer.php
├── DuplicateBlock.php
├── DuplicateLocation.php
├── CodeDuplicationOptions.php
└── CodeDuplicationRule.php
```

## External integration

Duplication publishes no contract of its own. `DuplicationDetector` implements
the consumer-owned
`Analysis\Run\Contract\FileSetInspectionParticipantInterface`; every
Duplication entity, option, result provider, rule, and detector remains internal.
Infrastructure registers the detector as a FileSet participant by
autoconfiguration and never publishes a Duplication alias.

## State and lifecycle

| State                  | Scope   | Owner                       | Created/reset by                                                                                        | Typed readers         |
| ---------------------- | ------- | --------------------------- | ------------------------------------------------------------------------------------------------------- | --------------------- |
| `list<DuplicateBlock>` | per-run | `DuplicationResultProvider` | Created by `DuplicationDetector::inspect()` and cleared in O(1) by `DuplicationDetector::resetForRun()` | `CodeDuplicationRule` |

`inspect()` computes a complete local result before replacing the provider's
value. Replacement never appends to a previous run, and `all()` returns the
typed list by PHP array value semantics, so consumers cannot mutate the
provider's stored array.

The provider is intentionally an instance-owned lifecycle state holder, not a
DTO or public data surface. Its private array is available only through the
replace/read/reset semantics required by one analysis run. The point control
`@qmx-ignore design.data-class` records that the rule's public-surface and WMC
heuristic does not model this lifecycle role; adding unrelated behavior would
weaken the class rather than improve it.

## Dependencies and ports

P3 proves one narrow Run phase port. The generic composite invokes it without a
Duplication-specific branch; Duplication retains its result and emits its own
completion log through its implementation.

| Dependency/port                                                                            | Owner                   | Direction                    | Typed input/output                           | Why required                                                                       |
| ------------------------------------------------------------------------------------------ | ----------------------- | ---------------------------- | -------------------------------------------- | ---------------------------------------------------------------------------------- |
| `FileSetInspectionParticipantInterface`                                                    | Run                     | Run -> Duplication           | `list<SplFileInfo>` -> provider-owned result | Run invokes a selected participant without importing the detector.                 |
| `TransitionalRuntimeConfigurationProviderInterface`                                        | Analysis.Configuration  | Duplication -> Configuration | resolved rule options and project root       | Detection uses configured token/line thresholds and relative paths.                |
| Path and symbol primitives                                                                 | Core.Path / Core.Symbol | Duplication -> Core          | absolute/relative paths and metric subjects  | Stable file, subject, and report identities.                                       |
| Rule/finding contracts, including `Rules\AbstractRule` and `Rules\Support\ThresholdParser` | Analysis.Finding        | Duplication -> Finding       | rule/options/threshold APIs and violations   | The owned rule participates in Finding's current execution and reporting boundary. |

## Test ownership

The module owns eight Unit test classes under
`tests/Analysis/Evidence/Duplication/Unit/`:

- `ContentHintExtractorTest`
- `DataDeclarationTaggerTest`
- `DuplicationDetectorTest`
- `DuplicationMemoryLimitProcessTest`
- `SaturatingCandidateFilterTest`
- `TokenNormalizerTest`
- `DuplicateBlockIdentityTest`
- `CodeDuplicationRuleTest`

The process test protects the bounded-memory candidate index and the real CLI
path. Run the complete owned suite with:

```bash
vendor/bin/phpunit --no-coverage tests/Analysis/Evidence/Duplication/Unit
```

## Extension registration

`CodeDuplicationRule` is a `qmx.rule` implementation. Registration is delegated
to the infrastructure `DuplicationConfigurator`; compiler passes inject its
options, add it to rule/channel registries, and reject duplicate rule/channel
identities. The rule's deterministic id is `duplication.code-duplication`.

## Run integration

The former `MetricEnricher -> DuplicationInspectionInterface` temporary import
and the capability-owned interface are deleted in P3. The final route is Run's
FileSet participant port implemented by `DuplicationDetector`. Disabling
`duplication.code-duplication` prevents both inspection and allocation; a second
analysis run begins with an empty provider.
