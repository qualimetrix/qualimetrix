# Duplication

## Subject, promise and owners

- **Subject:** token- and block-based code duplication evidence.
- **Promise:** inspect one discovered PHP file set, retain the complete result
  for one analysis run, and emit `duplication.clone` findings from
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
replace/read/reset semantics required by one analysis run. It carried a point
`@qmx-ignore design.data-class` until `design.data-class` was corrected to gate
on a low share of functional public methods; its replace/read/reset methods are
functional, so the control is no longer needed.

## Dependencies and ports

There is one narrow Run phase port. The generic composite invokes it without a
Duplication-specific branch; Duplication retains its result and emits its own
completion log through its implementation.

| Dependency/port                                                                            | Owner                   | Direction                    | Typed input/output                           | Why required                                                                          |
| ------------------------------------------------------------------------------------------ | ----------------------- | ---------------------------- | -------------------------------------------- | ------------------------------------------------------------------------------------- |
| `FileSetInspectionParticipantInterface`                                                    | Run                     | Run -> Duplication           | `list<SplFileInfo>` -> provider-owned result | Run invokes a selected participant without importing the detector.                    |
| `RuleConfigurationInterface` and Run file-set input                                        | Finding / Run           | Duplication -> Finding / Run | named rule options and project root          | Detection reads its named rule and receives root only through Run's participant call. |
| Path and symbol primitives                                                                 | Core.Path / Core.Symbol | Duplication -> Core          | absolute/relative paths and metric subjects  | Stable file, subject, and report identities.                                          |
| Rule/finding contracts, including `Rules\AbstractRule` and `Rules\Support\ThresholdParser` | Analysis.Finding        | Duplication -> Finding       | rule/options/threshold APIs and findings     | The owned rule participates in Finding's current execution and reporting boundary.    |

## Test ownership

The module owns test classes at three levels, and the level is decided by what
the body does rather than by where the class started out.

Seven Unit classes under `tests/Analysis/Evidence/Duplication/Unit/`, all of
them in memory:

- `ContentHintExtractorTest`
- `DataDeclarationTaggerTest`
- `DuplicateBlockFinderTest`
- `SaturatingCandidateFilterTest`
- `TokenNormalizerTest`
- `DuplicateBlockIdentityTest`
- `CodeDuplicationRuleTest`

One Integration class under `tests/Analysis/Evidence/Duplication/Integration/`:
`DuplicationDetectorTest`, which writes real files into a temporary directory
and runs the detector's whole pipeline over them.

Three Functional classes under `tests/Analysis/Evidence/Duplication/Functional/`:
`DuplicationMemoryLimitProcessTest`, which builds a temporary project and runs
`bin/qmx` in a real PHP subprocess under a `memory_limit`. It protects the
bounded-memory candidate index, the report of every copy of a block copied
around a hundred times under a 128M limit, and the real CLI path, and it is the
reason the module has a Functional level at all. `DuplicationGitScopeProcessTest`
runs `--report=git:staged` over a git repository in which only a new copy is
staged, and pins that the copy is reported in its own file.
`DuplicationCopyFingerprintProcessTest` runs `--format=gitlab` and
`--format=sarif` over two copies and pins that each carries its own
fingerprint.

Run the complete owned suite with:

```bash
vendor/bin/phpunit --no-coverage tests/Analysis/Evidence/Duplication
```

## Extension registration

`CodeDuplicationRule` emits one finding per copy of each `DuplicateBlock`,
located on that copy. Each copy has an identity of its own — the content hash,
the copy's file and its place among the block's copies in that file, never a
line number — so a new copy is a new finding to a baseline and a new
fingerprint to GitLab and SARIF (ADR 0085). Each finding names at most ten other copies, in its message
and as related locations, and counts the rest: the copies' `Location` objects
are built once per block and shared, and a bound is what keeps a block of N
copies from carrying N² related locations into SARIF.

`CodeDuplicationRule` is a `qmx.rule` implementation. Registration is delegated
to the infrastructure `DuplicationConfigurator`; compiler passes inject its
options, add it to rule/channel registries, and reject duplicate rule/channel
identities. The rule's deterministic id is `duplication.clone`.

## Run integration

The former `MetricEnricher -> DuplicationInspectionInterface` temporary import
and the capability-owned interface are gone. The final route is Run's
FileSet participant port implemented by `DuplicationDetector`. Disabling
`duplication.clone` prevents both inspection and allocation; a second
analysis run begins with an empty provider.


## Locality

This README is part of the subject boundary: keep its production code, tests, fixtures, support, and documentation with the named owner. External consumers use declared contracts only; mutable runtime state has one owner, reset point, and typed readers. Composition-only access to a private declaration requires a reviewed exact binding, not a generic qmx permission.
