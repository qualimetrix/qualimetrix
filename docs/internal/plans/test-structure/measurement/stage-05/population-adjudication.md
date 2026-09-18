# The nine files a person had to decide

`population.tsv` marked nine ledger paths anything other than `at-path` or
`moved-agreed`. Each is settled below against the **body** of its heirs, not
against the rename edge: a rename edge says where a file went, and a row says
where a *defect* went, and a split makes those two different questions.

They now read `adjudicated` in `population.tsv` and `packages.tsv`, and
`current_heirs` carries the heir that was decided — both heirs where the split
itself closed the row.

**No row split.** Every one of the ten rows landed on one heir or was closed by
the split; none needed to become two rows. The 219/276 totals therefore stand,
and `counterparts.tsv` is untouched — its 60 entries were already free of
ambiguity.

## The derivation was wrong about one file, and the wrongness is instructive

`population-method.md` records that lowering the rename threshold to 30% buys
exactly one pair,
`DocumentationConsistencyTest → governance/RuleDeclaration/DocumentationRuleSurfaceTest.php`,
and leaves settling it to a person. **That pair is a true rename edge and the
wrong answer for this row.** The file did not move, it split four ways:

| Methods at `585b7c72` | Heir today                                                                |
| --------------------- | ------------------------------------------------------------------------- |
| 5                     | `governance/RuleDeclaration/DocumentationRuleSurfaceTest.php`             |
| 3                     | `governance/PlanningRecords/PlanningRecordIsolationTest.php`              |
| 5                     | `governance/RatchetArtifact/BaselineCountPublicationTest.php`             |
| 1                     | `governance/DocumentationCensus/RegisteredFormatterDocumentationTest.php` |

The edge names the largest heir. The row names a method,
`itRecognizesPlanningChronologyOnlyInsideComments`, and that method is in the
second. Lowering the threshold would have produced a confident wrong answer,
which is worse than `UNRESOLVED`. The method name is the identity; the path is
not.

```
git ls-files -z | xargs -0 grep -nF -- 'itRecognizesPlanningChronologyOnlyInsideComments'
```

## The nine

### `BenchmarkConsumersCoverageTest` → `scripts/benchmark/tests/BenchmarkCoverageRefusalTest.php`

`moved-rename-only`: W1 had the edge, W2 could not confirm it because the
basename changed. Read, the heir runs `scripts/benchmark-regression.php` and
`scripts/collect-benchmark-data.php` as subprocesses — the two scripts the row
said it tested — and it now sits beside them. The `misplaced` row is closed by
`e15c7f42`.

### `ConfigurationErrorClassificationTopologyTest` → both heirs

Seven methods at `585b7c72`: three AST-based control tests and four
fixture-based build tests. (The ledger note says "two … and five"; the split
point is right, the counts are not.) `c49fc0b4` put the three under
`governance/Channel/` and left the four under `tests/Analysis/Finding/Integration/`.
The row's defect is the mixing, and the mixing is what the split removed, so the
row is closed rather than attached to an heir.

### `ModularArchitectureGovernanceIntegrationTest` → both heirs

The `WITNESSES-DISAGREE` case the method document predicted: W1 followed the
rename chain into `scripts/modular-architecture/tests/ModularArchitectureGeneratorRefusalTest.php`,
W2 found a second file still under the original name in
`governance/ModularOwnership/`. Both are heirs and both are now at a subject that
fits: the generator's own refusals sit beside the generator, the control over the
generated projections sits in `governance/`. The row said the subject was
`scripts/`, not Architecture; neither heir is under Architecture any more.

### `ChannelRenameMapTest` → `tests/Analysis/Policy/Baseline/Unit/ChannelRenameMapTest.php`

`governance/Channel/ChannelRenameMapTest.php` declares one method,
`itReadsTheRepositorysOwnDeclaredChannelMap`. The row names
`itAnswersTheSharedCorpusAsDeclared`, and that method is in the Baseline heir,
where it still asserts `array_keys($map->renames)` against `$map->oldNames()`.
The tautology is live and is the one `high` row P0a held.

**The file moves to `P1-high`.** P1 builds the controls stand once, and a
tautology whose replacement is proven by a case in that stand does not want to be
repaired in a different package first. The move takes P1 from 22 files / 36 rows
to 23 / 37.

### `UnmatchedExcludeIntegrationTest` → `tests/Analysis/Run/Integration/ExcludeBinding/UnmatchedDiscoveryExcludeIntegrationTest.php`

The row is the basename collision with the Architecture-side file of the same
name. `7a89a3ad` renamed **both** sides — `UnmatchedDiscoveryExcludeIntegrationTest`
and `UnmatchedLayerExcludeIntegrationTest` — and each name now says which subject
it belongs to. Closed.

### `HookStatusCommandTest`, the functional half

Row: `chdir()` in `setUp()` with no `tearDown()` restoring the working
directory. Line 32 of
`tests/Infrastructure/Console/Functional/Command/HookStatusCommandTest.php` is
still `chdir($this->tempDir);`. Live, and it goes to `P5-infrastructure` with its
two `HookInstall`/`HookUninstall` siblings, which carry the identical row.

### `HookStatusCommandTest`, the unit half

Two rows on one ledger path.

- The `misplaced` row — one SUT split across two roots, `configure()` under
  `tests/Unit/…` and `execute()` under `tests/Functional/…` — is closed by
  `7a89a3ad`: both files now sit under `tests/Infrastructure/Console/`, one
  owner, and the remaining `Unit`/`Functional` distinction is the level
  taxonomy, not a misfiling.
- The smoke-test row is live on
  `tests/Infrastructure/Console/Unit/Command/HookStatusCommandTest.php`, which
  still declares three cases about `configure()` and none about `execute()`.

The file goes to `P5-infrastructure` carrying one open row.

### `SarifRuleDescriptorCoverageTest` → `governance/Channel/SarifRuleDescriptorCoverageTest.php`

A split again, and this time one heir keeps the defect. The `dupe` row is about
walking the whole channel universe and asserting a page exists under
`website/docs`; the governance heir does exactly that in two cases, and the
`tests/Reporting/…` heir is left with one fallback case and no universe walk.
The named counterpart, `ChannelPresentationCoverageTest`, is also under
`governance/Channel/` now, so the pair is in one package: `P7-governance-tooling`.

### `DocumentationConsistencyTest` → `governance/PlanningRecords/PlanningRecordIsolationTest.php`

Settled at the top of this file. The row is `wont-fix`: the method it names feeds
the control's own comment extractor a string that must match and a string that
must not, which is the proof that the detector can refuse. This repository
requires that proof of its controls — `composer gate:controls`,
`composer directives:controls`, `composer directives:controls:coverage` — and
gets it by putting the case beside the detector. Calling that "a unit test of a
private helper inside a control class" describes the required shape.

## Where the ten rows went

| Row    | Heir decided                                         | Disposition                   |
| ------ | ---------------------------------------------------- | ----------------------------- |
| `R019` | both (split closed it)                               | `already-fixed` `c49fc0b4`    |
| `R034` | both (split closed it)                               | `already-fixed` `c49fc0b4`    |
| `R041` | `PlanningRecordIsolationTest`                        | `wont-fix`                    |
| `R043` | `…/Console/Functional/Command/HookStatusCommandTest` | open, `P5-infrastructure`     |
| `R095` | `BenchmarkCoverageRefusalTest`                       | `already-fixed` `e15c7f42`    |
| `R195` | `…/Console/Unit/Command/HookStatusCommandTest`       | open, `P5-infrastructure`     |
| `R211` | `…/Console/Unit/Command/HookStatusCommandTest`       | `already-fixed` `7a89a3ad`    |
| `R212` | `UnmatchedDiscoveryExcludeIntegrationTest`           | `already-fixed` `7a89a3ad`    |
| `R213` | `governance/Channel/SarifRuleDescriptorCoverageTest` | open, `P7-governance-tooling` |
| `R270` | `…/Baseline/Unit/ChannelRenameMapTest`               | open, `P1-high`               |

A file whose every row P0a closed stays in `P0-population`; a file with an open
row moves to the package owning its decided heir. That rule, and not the
`already-fixed` verdicts, is what changed the package sizes:

| Package                 | Files   | Rows    |
| ----------------------- | ------: | ------: |
| `P0-population`         | 5       | 5       |
| `P1-high`               | 23      | 37      |
| `P3-evidence`           | 58      | 68      |
| `P4-analysis-core`      | 41      | 52      |
| `P5-infrastructure`     | 44      | 57      |
| `P6-reporting`          | 16      | 19      |
| `P7-governance-tooling` | 32      | 38      |
| **total**               | **219** | **276** |
