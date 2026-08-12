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
  configuration source merging belongs to `Analysis.Configuration`; finding
  primitives and rule execution belong to `Analysis.Finding`.

## Structure

```text
Duplication/
├── Contract/
│   └── DuplicationInspectionInterface.php
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

## External consumers and contracts

`DuplicationInspectionInterface` is the module's only public contract.
Detection entities, options, the result provider, and the rule implementation
are internal.

| Consumer owner                       | Source FQCN (`null` if permanent)              | Contract type                                                                       | `closes_in` | Promise used                                               |
| ------------------------------------ | ---------------------------------------------- | ----------------------------------------------------------------------------------- | ----------- | ---------------------------------------------------------- |
| `Analysis.Run`                       | `Qualimetrix\Analysis\Pipeline\MetricEnricher` | `Qualimetrix\Analysis\Evidence\Duplication\Contract\DuplicationInspectionInterface` | `P3`        | Reset run state and inspect the discovered file set.       |
| `Infrastructure.DependencyInjection` | `null`                                         | `Qualimetrix\Analysis\Evidence\Duplication\Contract\DuplicationInspectionInterface` | `null`      | Compose the implementation and expose the contract to Run. |

## State and lifecycle

| State                  | Scope   | Owner                       | Created/reset by                                                                                  | Typed readers         |
| ---------------------- | ------- | --------------------------- | ------------------------------------------------------------------------------------------------- | --------------------- |
| `list<DuplicateBlock>` | per-run | `DuplicationResultProvider` | Created by `DuplicationDetector::inspect()` and cleared in O(1) by `DuplicationDetector::reset()` | `CodeDuplicationRule` |

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

P3's generic phase ports remain non-binding. The current temporary Run seam is
the capability-specific inspection contract above.

| Dependency/port                  | Owner                  | Direction                    | Typed input/output                           | Why required                                                         |
| -------------------------------- | ---------------------- | ---------------------------- | -------------------------------------------- | -------------------------------------------------------------------- |
| `DuplicationInspectionInterface` | Duplication            | Run -> Duplication           | `list<SplFileInfo>` -> provider-owned result | Run triggers the file-set inspection without importing the detector. |
| `ConfigurationProviderInterface` | Analysis.Configuration | Duplication -> Configuration | resolved rule options and project root       | Detection uses configured token/line thresholds and relative paths.  |
| Path primitives                  | Core.Path              | Duplication -> Core.Path     | absolute/relative paths                      | Stable file identity and report locations.                           |
| Rule/finding contracts           | Analysis.Finding       | Duplication -> Finding       | options, context and violations              | The owned rule participates in common rule execution and reporting.  |

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

## Temporary grants and closure

| Exact grant                                                                                                                           | Owner          | Reason                                                           | Closure package/condition                                                    | Verification                                                                                       |
| ------------------------------------------------------------------------------------------------------------------------------------- | -------------- | ---------------------------------------------------------------- | ---------------------------------------------------------------------------- | -------------------------------------------------------------------------------------------------- |
| `Qualimetrix\Analysis\Pipeline\MetricEnricher` -> `Qualimetrix\Analysis\Evidence\Duplication\Contract\DuplicationInspectionInterface` | `Analysis.Run` | Preserve current file-set sequencing during the namespace pilot. | P3: replace the capability-specific Run import with the accepted phase port. | The manifest rejects every additional Run source and requires this exact entry to disappear in P3. |
