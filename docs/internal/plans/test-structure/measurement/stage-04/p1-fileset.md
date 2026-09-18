# Stage 04, package P1 — the Infrastructure-owned working set

Measured against `main` @ `46c3deca` (clean tree). The generator,
`scripts/generate-modular-architecture-test-inventory.php`, is read only via
`git show 46c3deca:scripts/generate-modular-architecture-test-inventory.php` because
another agent is concurrently rewriting it in the working tree (P0); every generator
line number and literal below is quoted from that pinned blob, saved locally as
`generator.php`, not from the working copy. `docs/internal/plans/test-structure/04-packages.md`
and `docs/internal/plans/test-structure/measurement/stage-04/addresses.md` were read
first, per the task brief.

Verified before any of the below: `git diff --stat e15c7f42 46c3deca -- tests/
scripts/generate-modular-architecture-test-inventory.php phpunit.xml.dist` is empty —
so `prediction.md`, measured on `main` @ `e15c7f42`, applies unchanged to `46c3deca`
for these three paths, and its baseline numbers are cited directly below rather than
re-measured by a fresh `--list-tests` run.

## 1. The moving files

Command:

```
python3 -c "
import csv
rows = list(csv.DictReader(open('docs/internal/plans/test-structure/measurement/stage-04/relocation-map.csv')))
p1 = [r for r in rows if r['owner'].startswith('Infrastructure.')]
print(len(p1))
"
```

Result: **52** — matches the plan's stated count. Owners represented (8 of the 37
manifest owners): `Infrastructure.Ast`, `Infrastructure.Cache`, `Infrastructure.Console`,
`Infrastructure.DependencyInjection`, `Infrastructure.Git`, `Infrastructure.Parallel`,
`Infrastructure.Rule`, `Infrastructure.Serializer`.

Current-suite breakdown (from the map's own `current_suite` column, `Counter` over the
52 rows): Unit 28, Infrastructure (residue, already under `tests/Infrastructure/` but at
the wrong sub-path) 16, Functional 4, Integration 4. `target_suite` is `Infrastructure`
for all 52 — this is the stated basis for the per-suite prediction in section 5.

Every row, `current -> target`, with its current and target suite and owning capability:

| #   | current                                                                            | target                                                                                                        | current suite  | target suite   | owner                              | current namespace                                         | target namespace (implied)                                                      |
| --: | ---------------------------------------------------------------------------------- | ------------------------------------------------------------------------------------------------------------- | -------------- | -------------- | ---------------------------------- | --------------------------------------------------------- | ------------------------------------------------------------------------------- |
| 1   | `tests/Functional/Console/Command/HookInstallCommandTest.php`                      | `tests/Infrastructure/Console/Functional/Command/HookInstallCommandTest.php`                                  | Functional     | Infrastructure | Infrastructure.Console             | `Qualimetrix\Tests\Functional\Console\Command`            | `Qualimetrix\Tests\Infrastructure\Console\Functional\Command`                   |
| 2   | `tests/Functional/Console/Command/HookStatusCommandTest.php`                       | `tests/Infrastructure/Console/Functional/Command/HookStatusCommandTest.php`                                   | Functional     | Infrastructure | Infrastructure.Console             | `Qualimetrix\Tests\Functional\Console\Command`            | `Qualimetrix\Tests\Infrastructure\Console\Functional\Command`                   |
| 3   | `tests/Functional/Console/Command/HookUninstallCommandTest.php`                    | `tests/Infrastructure/Console/Functional/Command/HookUninstallCommandTest.php`                                | Functional     | Infrastructure | Infrastructure.Console             | `Qualimetrix\Tests\Functional\Console\Command`            | `Qualimetrix\Tests\Infrastructure\Console\Functional\Command`                   |
| 4   | `tests/Functional/Console/LayerAssignmentCommandTest.php`                          | `tests/Infrastructure/Console/Functional/Command/Debug/LayerAssignmentCommandTest.php`                        | Functional     | Infrastructure | Infrastructure.Console             | `Qualimetrix\Tests\Functional\Console`                    | `Qualimetrix\Tests\Infrastructure\Console\Functional\Command\Debug`             |
| 5   | `tests/Infrastructure/Integration/RulesCommandWiringTest.php`                      | `tests/Infrastructure/DependencyInjection/Integration/RulesCommandWiringTest.php`                             | Infrastructure | Infrastructure | Infrastructure.DependencyInjection | `Qualimetrix\Tests\Infrastructure\Integration`            | `Qualimetrix\Tests\Infrastructure\DependencyInjection\Integration`              |
| 6   | `tests/Infrastructure/Integration/SharedRuleOptionsContainerTest.php`              | `tests/Infrastructure/DependencyInjection/Integration/CompilerPass/SharedRuleOptionsContainerTest.php`        | Infrastructure | Infrastructure | Infrastructure.DependencyInjection | `Qualimetrix\Tests\Infrastructure\Integration`            | `Qualimetrix\Tests\Infrastructure\DependencyInjection\Integration\CompilerPass` |
| 7   | `tests/Infrastructure/Unit/ChannelUniverseTest.php`                                | `tests/Infrastructure/Rule/Unit/ChannelUniverseTest.php`                                                      | Infrastructure | Infrastructure | Infrastructure.Rule                | `Qualimetrix\Tests\Infrastructure\Unit`                   | `Qualimetrix\Tests\Infrastructure\Rule\Unit`                                    |
| 8   | `tests/Infrastructure/Unit/CollectorCompilerPassTest.php`                          | `tests/Infrastructure/DependencyInjection/Unit/CompilerPass/CollectorCompilerPassTest.php`                    | Infrastructure | Infrastructure | Infrastructure.DependencyInjection | `Qualimetrix\Tests\Infrastructure\Unit`                   | `Qualimetrix\Tests\Infrastructure\DependencyInjection\Unit\CompilerPass`        |
| 9   | `tests/Infrastructure/Unit/ConfigurationStageCompilerPassTest.php`                 | `tests/Infrastructure/DependencyInjection/Unit/CompilerPass/ConfigurationStageCompilerPassTest.php`           | Infrastructure | Infrastructure | Infrastructure.DependencyInjection | `Qualimetrix\Tests\Infrastructure\Unit`                   | `Qualimetrix\Tests\Infrastructure\DependencyInjection\Unit\CompilerPass`        |
| 10  | `tests/Infrastructure/Unit/ConfigurationValidatorCompilerPassTest.php`             | `tests/Infrastructure/DependencyInjection/Unit/CompilerPass/ConfigurationValidatorCompilerPassTest.php`       | Infrastructure | Infrastructure | Infrastructure.DependencyInjection | `Qualimetrix\Tests\Infrastructure\Unit`                   | `Qualimetrix\Tests\Infrastructure\DependencyInjection\Unit\CompilerPass`        |
| 11  | `tests/Infrastructure/Unit/FileSetInspectionParticipantCompilerPassTest.php`       | `tests/Infrastructure/DependencyInjection/Unit/CompilerPass/FileSetInspectionParticipantCompilerPassTest.php` | Infrastructure | Infrastructure | Infrastructure.DependencyInjection | `Qualimetrix\Tests\Infrastructure\Unit`                   | `Qualimetrix\Tests\Infrastructure\DependencyInjection\Unit\CompilerPass`        |
| 12  | `tests/Infrastructure/Unit/FormatterCompilerPassTest.php`                          | `tests/Infrastructure/DependencyInjection/Unit/CompilerPass/FormatterCompilerPassTest.php`                    | Infrastructure | Infrastructure | Infrastructure.DependencyInjection | `Qualimetrix\Tests\Infrastructure\Unit`                   | `Qualimetrix\Tests\Infrastructure\DependencyInjection\Unit\CompilerPass`        |
| 13  | `tests/Infrastructure/Unit/GlobalCollectorCompilerPassTest.php`                    | `tests/Infrastructure/DependencyInjection/Unit/CompilerPass/GlobalCollectorCompilerPassTest.php`              | Infrastructure | Infrastructure | Infrastructure.DependencyInjection | `Qualimetrix\Tests\Infrastructure\Unit`                   | `Qualimetrix\Tests\Infrastructure\DependencyInjection\Unit\CompilerPass`        |
| 14  | `tests/Infrastructure/Unit/KnownRuleNamesAdapterTest.php`                          | `tests/Infrastructure/Rule/Unit/KnownRuleNamesAdapterTest.php`                                                | Infrastructure | Infrastructure | Infrastructure.Rule                | `Qualimetrix\Tests\Infrastructure\Unit`                   | `Qualimetrix\Tests\Infrastructure\Rule\Unit`                                    |
| 15  | `tests/Infrastructure/Unit/ParallelCollectorClassesCompilerPassTest.php`           | `tests/Infrastructure/DependencyInjection/Unit/CompilerPass/ParallelCollectorClassesCompilerPassTest.php`     | Infrastructure | Infrastructure | Infrastructure.DependencyInjection | `Qualimetrix\Tests\Infrastructure\Unit`                   | `Qualimetrix\Tests\Infrastructure\DependencyInjection\Unit\CompilerPass`        |
| 16  | `tests/Infrastructure/Unit/RuleCompilerPassTest.php`                               | `tests/Infrastructure/DependencyInjection/Unit/CompilerPass/RuleCompilerPassTest.php`                         | Infrastructure | Infrastructure | Infrastructure.DependencyInjection | `Qualimetrix\Tests\Infrastructure\Unit`                   | `Qualimetrix\Tests\Infrastructure\DependencyInjection\Unit\CompilerPass`        |
| 17  | `tests/Infrastructure/Unit/RuleOptionsCompilerPassTest.php`                        | `tests/Infrastructure/DependencyInjection/Unit/CompilerPass/RuleOptionsCompilerPassTest.php`                  | Infrastructure | Infrastructure | Infrastructure.DependencyInjection | `Qualimetrix\Tests\Infrastructure\Unit`                   | `Qualimetrix\Tests\Infrastructure\DependencyInjection\Unit\CompilerPass`        |
| 18  | `tests/Infrastructure/Unit/RuleRegistryCompilerPassTest.php`                       | `tests/Infrastructure/DependencyInjection/Unit/CompilerPass/RuleRegistryCompilerPassTest.php`                 | Infrastructure | Infrastructure | Infrastructure.DependencyInjection | `Qualimetrix\Tests\Infrastructure\Unit`                   | `Qualimetrix\Tests\Infrastructure\DependencyInjection\Unit\CompilerPass`        |
| 19  | `tests/Infrastructure/Unit/RuleRegistryTest.php`                                   | `tests/Infrastructure/Rule/Unit/RuleRegistryTest.php`                                                         | Infrastructure | Infrastructure | Infrastructure.Rule                | `Qualimetrix\Tests\Infrastructure\Unit`                   | `Qualimetrix\Tests\Infrastructure\Rule\Unit`                                    |
| 20  | `tests/Infrastructure/Unit/RulesCommandTest.php`                                   | `tests/Infrastructure/Console/Unit/Command/RulesCommandTest.php`                                              | Infrastructure | Infrastructure | Infrastructure.Console             | `Qualimetrix\Tests\Infrastructure\Unit`                   | `Qualimetrix\Tests\Infrastructure\Console\Unit\Command`                         |
| 21  | `tests/Integration/DependencyInjection/ContainerFactoryTest.php`                   | `tests/Infrastructure/DependencyInjection/Integration/ContainerFactoryTest.php`                               | Integration    | Infrastructure | Infrastructure.DependencyInjection | `Qualimetrix\Tests\Integration\DependencyInjection`       | `Qualimetrix\Tests\Infrastructure\DependencyInjection\Integration`              |
| 22  | `tests/Integration/Infrastructure/Cache/CacheKeyGeneratorVOTest.php`               | `tests/Infrastructure/Cache/Integration/CacheKeyGeneratorVOTest.php`                                          | Integration    | Infrastructure | Infrastructure.Cache               | `Qualimetrix\Tests\Integration\Infrastructure\Cache`      | `Qualimetrix\Tests\Infrastructure\Cache\Integration`                            |
| 23  | `tests/Integration/Infrastructure/Git/GitSubdirScopeTest.php`                      | `tests/Infrastructure/Git/Integration/GitSubdirScopeTest.php`                                                 | Integration    | Infrastructure | Infrastructure.Git                 | `Qualimetrix\Tests\Integration\Infrastructure\Git`        | `Qualimetrix\Tests\Infrastructure\Git\Integration`                              |
| 24  | `tests/Integration/Infrastructure/Git/ReportingGitScopeQueryProjectSubdirTest.php` | `tests/Infrastructure/Git/Integration/ReportingGitScopeQueryProjectSubdirTest.php`                            | Integration    | Infrastructure | Infrastructure.Git                 | `Qualimetrix\Tests\Integration\Infrastructure\Git`        | `Qualimetrix\Tests\Infrastructure\Git\Integration`                              |
| 25  | `tests/Unit/Infrastructure/Ast/CachedFileParserTest.php`                           | `tests/Infrastructure/Ast/Unit/CachedFileParserTest.php`                                                      | Unit           | Infrastructure | Infrastructure.Ast                 | `Qualimetrix\Tests\Unit\Infrastructure\Ast`               | `Qualimetrix\Tests\Infrastructure\Ast\Unit`                                     |
| 26  | `tests/Unit/Infrastructure/Ast/FileParserFactoryTest.php`                          | `tests/Infrastructure/Ast/Unit/FileParserFactoryTest.php`                                                     | Unit           | Infrastructure | Infrastructure.Ast                 | `Qualimetrix\Tests\Unit\Infrastructure\Ast`               | `Qualimetrix\Tests\Infrastructure\Ast\Unit`                                     |
| 27  | `tests/Unit/Infrastructure/Ast/PhpFileParserTest.php`                              | `tests/Infrastructure/Ast/Unit/PhpFileParserTest.php`                                                         | Unit           | Infrastructure | Infrastructure.Ast                 | `Qualimetrix\Tests\Unit\Infrastructure\Ast`               | `Qualimetrix\Tests\Infrastructure\Ast\Unit`                                     |
| 28  | `tests/Unit/Infrastructure/Cache/CacheKeyGeneratorTest.php`                        | `tests/Infrastructure/Cache/Unit/CacheKeyGeneratorTest.php`                                                   | Unit           | Infrastructure | Infrastructure.Cache               | `Qualimetrix\Tests\Unit\Infrastructure\Cache`             | `Qualimetrix\Tests\Infrastructure\Cache\Unit`                                   |
| 29  | `tests/Unit/Infrastructure/Cache/FileCacheTest.php`                                | `tests/Infrastructure/Cache/Unit/FileCacheTest.php`                                                           | Unit           | Infrastructure | Infrastructure.Cache               | `Qualimetrix\Tests\Unit\Infrastructure\Cache`             | `Qualimetrix\Tests\Infrastructure\Cache\Unit`                                   |
| 30  | `tests/Unit/Infrastructure/Console/ApplicationTest.php`                            | `tests/Infrastructure/Console/Unit/ApplicationTest.php`                                                       | Unit           | Infrastructure | Infrastructure.Console             | `Qualimetrix\Tests\Unit\Infrastructure\Console`           | `Qualimetrix\Tests\Infrastructure\Console\Unit`                                 |
| 31  | `tests/Unit/Infrastructure/Console/CheckCommandDefinitionTest.php`                 | `tests/Infrastructure/Console/Unit/CheckCommandDefinitionTest.php`                                            | Unit           | Infrastructure | Infrastructure.Console             | `Qualimetrix\Tests\Unit\Infrastructure\Console`           | `Qualimetrix\Tests\Infrastructure\Console\Unit`                                 |
| 32  | `tests/Unit/Infrastructure/Console/CheckScopeResolverTest.php`                     | `tests/Infrastructure/Console/Unit/CheckScopeResolverTest.php`                                                | Unit           | Infrastructure | Infrastructure.Console             | `Qualimetrix\Tests\Unit\Infrastructure\Console`           | `Qualimetrix\Tests\Infrastructure\Console\Unit`                                 |
| 33  | `tests/Unit/Infrastructure/Console/CliOptionsParserTest.php`                       | `tests/Infrastructure/Console/Unit/CliOptionsParserTest.php`                                                  | Unit           | Infrastructure | Infrastructure.Console             | `Qualimetrix\Tests\Unit\Infrastructure\Console`           | `Qualimetrix\Tests\Infrastructure\Console\Unit`                                 |
| 34  | `tests/Unit/Infrastructure/Console/Command/HookStatusCommandTest.php`              | `tests/Infrastructure/Console/Unit/Command/HookStatusCommandTest.php`                                         | Unit           | Infrastructure | Infrastructure.Console             | `Qualimetrix\Tests\Unit\Infrastructure\Console\Command`   | `Qualimetrix\Tests\Infrastructure\Console\Unit\Command`                         |
| 35  | `tests/Unit/Infrastructure/Console/FilteredInputDefinitionTest.php`                | `tests/Infrastructure/Console/Unit/FilteredInputDefinitionTest.php`                                           | Unit           | Infrastructure | Infrastructure.Console             | `Qualimetrix\Tests\Unit\Infrastructure\Console`           | `Qualimetrix\Tests\Infrastructure\Console\Unit`                                 |
| 36  | `tests/Unit/Infrastructure/Console/FormatterContextFactoryTest.php`                | `tests/Infrastructure/Console/Unit/FormatterContextFactoryTest.php`                                           | Unit           | Infrastructure | Infrastructure.Console             | `Qualimetrix\Tests\Unit\Infrastructure\Console`           | `Qualimetrix\Tests\Infrastructure\Console\Unit`                                 |
| 37  | `tests/Unit/Infrastructure/Console/MeasuredFindingSetTest.php`                     | `tests/Infrastructure/Console/Unit/MeasuredFindingSetTest.php`                                                | Unit           | Infrastructure | Infrastructure.Console             | `Qualimetrix\Tests\Unit\Infrastructure\Console`           | `Qualimetrix\Tests\Infrastructure\Console\Unit`                                 |
| 38  | `tests/Unit/Infrastructure/Console/RuntimeLoggerConfiguratorTest.php`              | `tests/Infrastructure/Console/Unit/RuntimeLoggerConfiguratorTest.php`                                         | Unit           | Infrastructure | Infrastructure.Console             | `Qualimetrix\Tests\Unit\Infrastructure\Console`           | `Qualimetrix\Tests\Infrastructure\Console\Unit`                                 |
| 39  | `tests/Unit/Infrastructure/Console/ScopeWarningCheckerTest.php`                    | `tests/Infrastructure/Console/Unit/ScopeWarningCheckerTest.php`                                               | Unit           | Infrastructure | Infrastructure.Console             | `Qualimetrix\Tests\Unit\Infrastructure\Console`           | `Qualimetrix\Tests\Infrastructure\Console\Unit`                                 |
| 40  | `tests/Unit/Infrastructure/Git/ChangedFileTest.php`                                | `tests/Infrastructure/Git/Unit/ChangedFileTest.php`                                                           | Unit           | Infrastructure | Infrastructure.Git                 | `Qualimetrix\Tests\Unit\Infrastructure\Git`               | `Qualimetrix\Tests\Infrastructure\Git\Unit`                                     |
| 41  | `tests/Unit/Infrastructure/Git/GitClientTest.php`                                  | `tests/Infrastructure/Git/Unit/GitClientTest.php`                                                             | Unit           | Infrastructure | Infrastructure.Git                 | `Qualimetrix\Tests\Unit\Infrastructure\Git`               | `Qualimetrix\Tests\Infrastructure\Git\Unit`                                     |
| 42  | `tests/Unit/Infrastructure/Git/GitRepositoryLocatorTest.php`                       | `tests/Infrastructure/Git/Unit/GitRepositoryLocatorTest.php`                                                  | Unit           | Infrastructure | Infrastructure.Git                 | `Qualimetrix\Tests\Unit\Infrastructure\Git`               | `Qualimetrix\Tests\Infrastructure\Git\Unit`                                     |
| 43  | `tests/Unit/Infrastructure/Git/GitScopeParserTest.php`                             | `tests/Infrastructure/Git/Unit/GitScopeParserTest.php`                                                        | Unit           | Infrastructure | Infrastructure.Git                 | `Qualimetrix\Tests\Unit\Infrastructure\Git`               | `Qualimetrix\Tests\Infrastructure\Git\Unit`                                     |
| 44  | `tests/Unit/Infrastructure/Git/GitScopeResolverTest.php`                           | `tests/Infrastructure/Git/Unit/GitScopeResolverTest.php`                                                      | Unit           | Infrastructure | Infrastructure.Git                 | `Qualimetrix\Tests\Unit\Infrastructure\Git`               | `Qualimetrix\Tests\Infrastructure\Git\Unit`                                     |
| 45  | `tests/Unit/Infrastructure/Git/ReportingGitScopeQueryTest.php`                     | `tests/Infrastructure/Git/Unit/ReportingGitScopeQueryTest.php`                                                | Unit           | Infrastructure | Infrastructure.Git                 | `Qualimetrix\Tests\Unit\Infrastructure\Git`               | `Qualimetrix\Tests\Infrastructure\Git\Unit`                                     |
| 46  | `tests/Unit/Infrastructure/Parallel/FileProcessingResultWireFormatTest.php`        | `tests/Infrastructure/Parallel/Unit/FileProcessingResultWireFormatTest.php`                                   | Unit           | Infrastructure | Infrastructure.Parallel            | `Qualimetrix\Tests\Unit\Infrastructure\Parallel`          | `Qualimetrix\Tests\Infrastructure\Parallel\Unit`                                |
| 47  | `tests/Unit/Infrastructure/Parallel/FileProcessingTaskFactoryTest.php`             | `tests/Infrastructure/Parallel/Unit/FileProcessingTaskFactoryTest.php`                                        | Unit           | Infrastructure | Infrastructure.Parallel            | `Qualimetrix\Tests\Unit\Infrastructure\Parallel`          | `Qualimetrix\Tests\Infrastructure\Parallel\Unit`                                |
| 48  | `tests/Unit/Infrastructure/Parallel/Strategy/AmphpParallelStrategyTest.php`        | `tests/Infrastructure/Parallel/Unit/Strategy/AmphpParallelStrategyTest.php`                                   | Unit           | Infrastructure | Infrastructure.Parallel            | `Qualimetrix\Tests\Unit\Infrastructure\Parallel\Strategy` | `Qualimetrix\Tests\Infrastructure\Parallel\Unit\Strategy`                       |
| 49  | `tests/Unit/Infrastructure/Parallel/WorkerBootstrapTest.php`                       | `tests/Infrastructure/Parallel/Unit/WorkerBootstrapTest.php`                                                  | Unit           | Infrastructure | Infrastructure.Parallel            | `Qualimetrix\Tests\Unit\Infrastructure\Parallel`          | `Qualimetrix\Tests\Infrastructure\Parallel\Unit`                                |
| 50  | `tests/Unit/Infrastructure/Serializer/IgbinarySerializerTest.php`                  | `tests/Infrastructure/Serializer/Unit/IgbinarySerializerTest.php`                                             | Unit           | Infrastructure | Infrastructure.Serializer          | `Qualimetrix\Tests\Unit\Infrastructure\Serializer`        | `Qualimetrix\Tests\Infrastructure\Serializer\Unit`                              |
| 51  | `tests/Unit/Infrastructure/Serializer/PhpSerializerTest.php`                       | `tests/Infrastructure/Serializer/Unit/PhpSerializerTest.php`                                                  | Unit           | Infrastructure | Infrastructure.Serializer          | `Qualimetrix\Tests\Unit\Infrastructure\Serializer`        | `Qualimetrix\Tests\Infrastructure\Serializer\Unit`                              |
| 52  | `tests/Unit/Infrastructure/Serializer/SerializerSelectorTest.php`                  | `tests/Infrastructure/Serializer/Unit/SerializerSelectorTest.php`                                             | Unit           | Infrastructure | Infrastructure.Serializer          | `Qualimetrix\Tests\Unit\Infrastructure\Serializer`        | `Qualimetrix\Tests\Infrastructure\Serializer\Unit`                              |

## 2. Namespace rewrite and pre-existing defects

For each of the 52 files, the declared namespace was read with
`git show 46c3deca:<path>` and matched by regex (`^namespace ...;`, `^(?:final\s+)?class ...`)
against the PSR-4-implied namespace of both `current` and `target` (root
`Qualimetrix\Tests\ => tests/`, one namespace segment per path segment after `tests/`,
excluding the filename).

**Result: 0 of the 52 files have a declared namespace that disagrees with their current
path.** The "current namespace" column in the table above mirrors `current` with `/` ->
`\` and no filename, for every row. So P1 carries no pre-existing namespace/path defect
to fix incidentally — the "target namespace (implied)" column above is what each file's
namespace becomes.

## 3. References to the 52 classes from outside the moving set

### 3a. Full FQCN sweep

For each of the 52 files, the FQCN was built as `namespace + '\' + class name` from the
same `git show` read (not from a path-prefix pattern), giving 52 distinct FQCNs. Then,
for each FQCN:

```
git grep -l -F '<FQCN>'
```

run over the whole tracked tree (`git grep` covers non-PHP files by default — no
`--` restriction to `*.php`).

Raw totals: **113** file-hits across all 52 FQCNs (including the moving file's own
declaration); all 52 FQCN sweeps returned at least one hit outside the moving file
itself, so none of the 52 sweeps was empty — the empty-result trap the addresses
document warns about does not apply here.

Every outside hit, by carrying file and kind:

| Carrying file                                                                      | Kind                                                                         | Which of the 52 it names                                                                                                                       | Note                                                                                                                 |
| ---------------------------------------------------------------------------------- | ---------------------------------------------------------------------------- | ---------------------------------------------------------------------------------------------------------------------------------------------- | -------------------------------------------------------------------------------------------------------------------- |
| `docs/internal/generated/modular-architecture/test-ownership.tsv`                  | generated TSV row                                                            | all 52                                                                                                                                         | regenerated by P0's rewrite and again after the move; not a reference to fix by hand                                 |
| `docs/internal/generated/modular-architecture/test-phpunit-discovery.txt`          | generated inventory listing                                                  | all 52                                                                                                                                         | same — regenerated, not hand-fixed                                                                                   |
| `docs/internal/plans/test-structure/measurement/stage-02/case-census.tsv`          | historical measurement data, plain FQCN in a TSV cell                        | `HookInstallCommandTest`, `HookUninstallCommandTest`, `RuleRegistryTest`, `Console\Command\HookStatusCommandTest` (short-name variant, see 3b) | a frozen measurement snapshot from an earlier stage; out of scope for this move, listed so it is not silently missed |
| `docs/internal/plans/test-structure/measurement/stage-04-review/native-round-3.md` | prose, plain FQCN                                                            | `RulesCommandWiringTest`                                                                                                                       | review note about the stale `@see` in item 3c below; not itself something P1 rewrites                                |
| `governance/Channel/ChannelDeclarationFixtureDriftTest.php`                        | drives a fixture that itself carries a plain-text FQCN reference (see below) | `ChannelUniverseTest`                                                                                                                          | test-owned governance file whose companion fixture names the moving file's old path                                  |

`governance/Channel/ChannelDeclarationFixtureDriftTest.php`'s hit was read directly: it
covers `governance/Channel/Fixtures/declared.txt`, which itself contains, in a comment,
the literal string `tests/Infrastructure/Unit/ChannelUniverseTest.php's` (verified:
`grep -n ChannelUniverseTest governance/Channel/Fixtures/declared.txt` -> line 58). This
is exactly the address enumeration's own sanity-check target
(`governance/Channel/Fixtures/declared.txt`, named in the task brief) confirmed
non-empty: **P1 must update this fixture comment**, or the comment goes stale (it is a
comment, not parsed input to the test's assertions, so staleness there is a
documentation defect rather than a red test — but it is a defect this package
introduces if left alone).

Two live (non-generated, non-historical) production/test references were also found:

- `tests/Infrastructure/Console/Functional/ApplicationRefusalTest.php` — an `@see`
  docblock link to the full FQCN
  `Qualimetrix\Tests\Unit\Infrastructure\Console\ApplicationTest::itReturnsRefusalExitCodeForABareInvalidArgumentExceptionWithNoCarrier()`.
- `tests/Infrastructure/Console/Functional/Command/CheckCommandInputValidationTest.php`
  — an `@see \Qualimetrix\Tests\Unit\Infrastructure\Console\ApplicationTest` docblock
  link (class-level, no method).

Both are non-moving files (their own current suite is already `Infrastructure` at a
correct sub-path — `Console/Functional` and `Console/Functional/Command` respectively —
outside the 52). Both must have their `@see` target rewritten to
`Qualimetrix\Tests\Infrastructure\Console\Unit\ApplicationTest` when `ApplicationTest`
moves. These are 2 of the "10 non-moving tracked files" the addresses document counts
across all three move packages; the task brief's own hint list names exactly these two
plus three more that belong to P2/P3.

A third such reference:

- `tests/Analysis/Run/Unit/Configuration/ProjectScopeCoverageTest.php` — references
  `ScopeWarningCheckerTest` by full FQCN (confirmed present in the FQCN-sweep output for
  `Qualimetrix\Tests\Unit\Infrastructure\Console\ScopeWarningCheckerTest`). This is
  the third of the four P1-owned files named in the addresses document's own list of five
  carriers (`governance/ConfigurationVocabulary/YamlKeyReachabilityTest.php`,
  `tests/Analysis/Run/Unit/Configuration/ProjectScopeCoverageTest.php`,
  `tests/Infrastructure/Console/Functional/ApplicationRefusalTest.php`,
  `tests/Infrastructure/Console/Functional/Command/CheckCommandInputValidationTest.php`,
  `tests/Infrastructure/Console/Integration/RuleExclusionStatsWiringTest.php`).

**The fourth named carrier, `governance/ConfigurationVocabulary/YamlKeyReachabilityTest.php`,
was checked directly and does not, in fact, name any of the 52 FQCNs or short names**
(`grep -n Infrastructure governance/ConfigurationVocabulary/YamlKeyReachabilityTest.php`
returns nothing). Its reference in the addresses document's grouping must belong to a
different package (P2 or P3) — this derivation does not confirm it as a P1 address, and
says so rather than assuming the document's grouping is exactly 4+4+2 by file identity.

### 3b. Short-name + `::` sweep

For each of the 52 class basenames, `git grep -l -F '<ClassName>::'` over the whole
tree, filtered to hits outside the 52 paths. 51 of 52 classes returned at least one
outside hit — all via `test-phpunit-discovery.txt`, which lists every `Class::method`
test ID and is generated/regenerated, not a hand-fixed reference.

Three exceptions beyond the generated-artifact noise:

- `RuleCompilerPassTest::`, `ContainerFactoryTest::`, and
  `ReportingGitScopeQueryProjectSubdirTest::` appear as substrings inside
  `scripts/generate-modular-architecture-test-inventory.php` — see section 4, these are
  the generator's own `P6_RENAMED_TEST_IDS` / `P6_LIVE_ADDED_TEST_IDS` literals.
- `ContainerFactoryTest::itRegistersAllRules` appears in a **prose docblock**, not an
  `@see` link, in `governance/RuleDeclaration/RuleRegistrationDriftTest.php` line 26:
  "`ContainerFactoryTest::itRegistersAllRules` compares it against a" — a plain-text
  test-ID mention inside a class docblock, naming the short class name only (not the
  FQCN), so it is invisible to an `@see` sweep and to the FQCN sweep in 3a, and was
  found only by the short-name-plus-`::` form. It should be updated for readability but
  is prose, not a resolved reference — the docblock does not fail to compile or fail a
  test if left stale, unlike an `@see` link.
- `CacheKeyGeneratorTest` and `ContainerFactoryTest` are also named in
  `docs/internal/plans/test-structure/measurement/slice-reports/10-infrastructure-core.md`,
  a frozen prior-stage measurement document — out of scope, listed for completeness.

### 3c. A known pre-existing dead reference, found while confirming the above

`tests/Infrastructure/Console/Integration/RuleExclusionStatsWiringTest.php` (a
non-moving file) carries, at line 43:

```
 * {@see \Qualimetrix\Tests\Integration\Infrastructure\Console\RulesCommandWiringTest}.
```

This FQCN does **not** match the current, real FQCN of the moving file
`RulesCommandWiringTest`, which today is
`Qualimetrix\Tests\Infrastructure\Integration\RulesCommandWiringTest` (`Integration`
and `Infrastructure` swapped, and no `Console` segment). No file exists at
`tests/Integration/Infrastructure/Console/RulesCommandWiringTest.php` on disk
(confirmed: `ls` fails, "No such file or directory"). This `@see` is **already broken
today, independent of the P1 move** — it was not created by this stage, and rewriting
it to point at the P1 target would silently launder an unrelated pre-existing defect
into the appearance of "the move updated its references correctly."
`docs/internal/plans/test-structure/measurement/stage-04-review/native-round-3.md` line
267 already documents this class as one a prior stage renamed, corroborating that the
docblock was never fixed after that earlier move. **Flagging, not fixing**: P1's own DoD
grep (`git grep -l` for the old FQCN, per `04-packages.md`'s Definition of Done for P1)
will not catch this, because the string it searches for
(`Qualimetrix\Tests\Infrastructure\Integration\RulesCommandWiringTest`) is not what this
docblock contains.

### 3d. Sanity-check of a sweep expected to be non-empty

Per the task brief, `governance/Channel/Fixtures/declared.txt` and a group of `@see`
references were named as known members of this channel. Confirmed:
`grep -n ChannelUniverseTest governance/Channel/Fixtures/declared.txt` returns one hit
(line 58, a comment), and `@see` references were independently found for
`ApplicationTest` (x2) — both already covered in 3a. The sweep is not a zero-result
claim in need of falsification; it produced exactly the expected non-empty content.

## 4. Generator constants naming one of the 52

Matched two ways against the pinned `generator.php` (== `46c3deca` blob):

**(a) Path literals** — grep each of the 52 `current` paths against the file:

```
grep -n -F "<path>" generator.php
```

4 hits, exactly as the plan claims:

| Line | Constant              | Literal                                                                              |
| ---- | --------------------- | ------------------------------------------------------------------------------------ |
| 189  | `P3_TEST_PATHS`       | `'tests/Unit/Infrastructure/Console/CheckScopeResolverTest.php'`                     |
| 190  | `P3_TEST_PATHS`       | `'tests/Unit/Infrastructure/Console/RuntimeLoggerConfiguratorTest.php'`              |
| 277  | `P6_D_GIT_TEST_PATHS` | `'tests/Integration/Infrastructure/Git/ReportingGitScopeQueryProjectSubdirTest.php'` |
| 278  | `P6_D_GIT_TEST_PATHS` | `'tests/Unit/Infrastructure/Git/ReportingGitScopeQueryTest.php'`                     |

`P3_TEST_PATHS x2` and `P6_D_GIT_TEST_PATHS x2` — confirmed as stated in the plan.
`P6_D_REPORTING_TEST_PATHS` (1 literal) does not name any P1 path — that literal belongs
to P2, consistent with the plan's per-package Files sections.

**(b) `FQCN::method` literals** — every single-quoted string in the `P6_LIVE_ADDED_TEST_IDS`
and `P6_RENAMED_TEST_IDS` blocks was extracted by regex, split on `::`, and the FQCN half
matched against the 52-FQCN set (exact string match, not substring):

- `P6_LIVE_ADDED_TEST_IDS` (6 entries total): **1 match** —
  `Qualimetrix\Tests\Integration\Infrastructure\Git\ReportingGitScopeQueryProjectSubdirTest::itProjectsGitScopeThroughTheReportingPortWithoutAReverseImport`.
- `P6_RENAMED_TEST_IDS` (4 key/value pairs, 8 literal halves total): **3 matches** — the
  *value* half of
  `...CompilerPass\RuleCompilerPassTest::itCollectsTaggedRulesIntoRuleExecutor' =>
  'Qualimetrix\Tests\Infrastructure\Unit\RuleCompilerPassTest::itCollectsTaggedRulesIntoRuleExecution'`
  (RHS matches P1's current FQCN for `RuleCompilerPassTest`), and **both** the key and
  value of
  `'Qualimetrix\Tests\Integration\DependencyInjection\ContainerFactoryTest::itInjectsRulesIntoRuleExecutor'
  => '...ContainerFactoryTest::itInjectsRulesIntoRuleExecution'` (both halves share the
  same FQCN, `Qualimetrix\Tests\Integration\DependencyInjection\ContainerFactoryTest`,
  which is P1's current FQCN for `ContainerFactoryTest` — this row renames a method, not
  a class, but the FQCN prefix is literally P1's own).

  The other two `P6_RENAMED_TEST_IDS` pairs (`RuleExclusionStatsWiringTest`,
  `CheckCommandBaselineTest`) do **not** match any of the 52 FQCNs on either side and
  belong to neither this package nor any P1 file — one of them
  (`Integration\Infrastructure\Console\RuleExclusionStatsWiringTest`, the LHS key) is
  itself a stale spelling matching neither the old nor the current real namespace of
  that non-moving file, echoing the same defect class as 3c.

**Correction to the plan's phrasing**: `04-packages.md` says the derivation "yields...
the `FQCN::method` literals in `P6_LIVE_ADDED_TEST_IDS` / `P6_RENAMED_TEST_IDS`" without
a count. Measured here: **1 literal in `P6_LIVE_ADDED_TEST_IDS`, 3 literal halves
(across 2 of its 4 pairs) in `P6_RENAMED_TEST_IDS`.** All four are FQCN prefixes that
equal a P1 FQCN under exact string match; the method names on either side of `::` need
not exist as real methods for the row to count, since these arrays record
historical/exact test-ID bookkeeping, not live reflection targets.

**Re-derivation notice, per the task brief**: this whole section is measured against
`46c3deca`'s frozen blob of the generator. P0 is rewriting that file concurrently in the
working tree; P1 must re-run every command in this section against whatever P0 actually
commits, not trust this file's line numbers or even the continued existence of these
constant names. `04-packages.md`'s P0 section describes deleting `validateP4Topology()`
and reshaping ownership derivation; it does not describe deleting `P3_TEST_PATHS`,
`P6_D_GIT_TEST_PATHS`, `P6_LIVE_ADDED_TEST_IDS`, or `P6_RENAMED_TEST_IDS`, but this was
not independently verified against P0's real diff, since this measurement was
deliberately forbidden from reading the working-tree generator.

`validateP4Topology()` was confirmed **not** to belong to this derivation, per the
brief: `grep -n LayerAssignmentCommandTest generator.php` shows it only inside that
function (pinning `tests/Infrastructure/Console/Functional/LayerAssignmentCommandTest.php`,
which is not even P1's actual target for that file — P1's target is
`tests/Infrastructure/Console/Functional/Command/Debug/LayerAssignmentCommandTest.php`,
one level deeper). P0 deletes the function; P1 must not resurrect or patch it.

## 5. `phpunit.xml.dist` `<directory>` entries

All 7 currently-declared `<directory>` entries were checked, by disk enumeration, for
whether P1's 52 moves empty them or leave any target uncovered.

Commands and results:

```
find tests/Unit -type f | wc -l                        # 76
find tests/Unit/Infrastructure -type f | wc -l          # 28  (= every Unit-suite P1 row)
find tests/Integration -type f | wc -l                  # 6
find tests/Integration/Infrastructure -type f | wc -l   # 3
grep -c '^tests/Integration/' p1_paths.txt              # 4  (3 under Infrastructure/ + ContainerFactoryTest.php under DependencyInjection/)
find tests/Functional -type f | wc -l                   # 6
grep -c '^tests/Functional/' p1_paths.txt               # 4
```

- **`tests/Unit`** (76 files, 28 of them under `tests/Unit/Infrastructure/`, all 28
  moving): 48 files remain — not emptied.
- **`tests/Integration`** (6 files; 4 are P1 rows: `DependencyInjection/ContainerFactoryTest.php`
  plus 3 under `Infrastructure/`): 2 files remain
  (`tests/Integration/Architecture/MaxExpandedLayersFromYamlTest.php`,
  `tests/Integration/DependencyInjection/ContainerConfigurationStagesTest.php`) — not
  emptied, and neither remaining file is a P1 concern.
- **`tests/Functional`** (6 files; 4 are P1 rows, all under `Console/`): 2 files remain
  (`tests/Functional/Reporting/CoverageProjectionFormatterTest.php`,
  `tests/Functional/Reporting/JsonShapePreservationTest.php`) — these are P2's, per
  `04-packages.md`'s statement that P2 (not P1) empties and removes
  `tests/Functional`. **Not emptied by P1.**
- **`tests/Infrastructure`** (already declared, 81 files on disk today, none of them
  from outside the manifest's Infrastructure ownership): every one of the 52 targets
  begins with `tests/Infrastructure/` (visible in the section-1 table's `target`
  column), so every target lands inside this already-declared entry. Nothing new to
  add.
- The other three declared entries (`tests/Core/...`, `tests/Analysis/...`,
  `tests/Reporting/...`) share no path prefix with any of the 52 current or target
  paths and are untouched by construction.

**Confirmed: P1 adds and removes zero `<directory>` entries**, matching the plan's
claim. This is the one claim in the brief that this derivation actively tried to
falsify by counting remaining files rather than trusting the "already covered"
assertion, and it held.

## 6. `dirname(__DIR__, N)` and other depth-sensitive path arithmetic

```
grep -Hn "dirname(__DIR__" <each of the 52 current paths>
```

4 occurrences, in 3 files:

| File                                                         | Line | Call                   | Current depth resolves to                                                                                           | Target depth resolves to                                                                 | Agree?                                |
| ------------------------------------------------------------ | ---- | ---------------------- | ------------------------------------------------------------------------------------------------------------------- | ---------------------------------------------------------------------------------------- | ------------------------------------- |
| `tests/Unit/Infrastructure/Ast/PhpFileParserTest.php`        | 30   | `\dirname(__DIR__, 3)` | `tests/` (dir is `tests/Unit/Infrastructure/Ast`, 3 segments under `tests/`; `dirname(x,3)` walks back to `tests/`) | `tests/` (target dir `tests/Infrastructure/Ast/Unit` is also 3 segments under `tests/`)  | **Yes**                               |
| `tests/Unit/Infrastructure/Git/GitRepositoryLocatorTest.php` | 69   | `\dirname(__DIR__, 4)` | repo root (dir is 3 segments under `tests/`, the extra `+1` clears `tests/` itself)                                 | repo root (target dir `tests/Infrastructure/Git/Unit` is also 3 segments under `tests/`) | **Yes**                               |
| `tests/Unit/Infrastructure/Git/GitScopeResolverTest.php`     | 27   | `\dirname(__DIR__, 4)` | repo root                                                                                                           | repo root                                                                                | **Yes**                               |
| `tests/Unit/Infrastructure/Git/GitScopeResolverTest.php`     | 85   | `\dirname(__DIR__, 4)` | repo root                                                                                                           | repo root                                                                                | **Yes** (same file, second call site) |

All 4 agree because every P1 move permutes the path's segments (e.g.
`Unit/Infrastructure/Git` -> `Infrastructure/Git/Unit`) without changing the segment
*count* between `tests/` and the file — a general property of the map's
"legacy-bucket" rows (an outer bucket segment like `Unit/` is dropped and an owner
segment is inserted in its place, net zero) — but checked here by literal depth
arithmetic per occurrence rather than assumed, and it holds for all 4. This confirms the
addresses document's own "Checked and found not to apply" row for `dirname(__DIR__, N)`.

No other depth-sensitive path arithmetic (`__DIR__ . '/../..'`, `realpath(__DIR__ .
...)`, etc.) was found in the 52 files: a broader `grep -l "__DIR__"` over the 52 paths
returns exactly the same 3 files as the `dirname(` search, and inspection of each hit
shows only the four call sites already listed — no bare `__DIR__ . '/../'` string
concatenation exists in this population.

## What this derivation cannot see

- **Dynamically assembled class names.** No sweep here can catch a class name built by
  string concatenation, a variable holding a class name read from config, or a
  `#[CoversClass('Some\Literal\String')]` attribute (as opposed to `SomeClass::class`)
  written with unusual formatting the regex used in section 4 might not anticipate. The
  regex in section 4 was written specifically for `'...'::'...'` single-quoted PHP
  literals inside two named constant blocks; it would miss a double-quoted string, a
  heredoc, or string interpolation elsewhere in the tree.
- **Paths or FQCNs carried in data files this sweep did not enumerate exhaustively.**
  `git grep -F` covers every tracked text file, but a binary or encoded artifact (none
  found in this repo's tracked tree, as far as this measurement went) would be invisible
  to it. Untracked files (build output, `.phpunit.cache`, etc.) are outside `git grep`
  entirely and outside this derivation by construction — same as every other
  `git grep`-based address enumeration in this stage.
- **The PHPStan baseline**, if one exists and carries a line-scoped ignore for any of
  the 52 files — not checked here; `04-packages.md`'s address enumeration for other
  packages does not name this channel either, and this derivation does not add it as a
  new finding beyond flagging that it was not checked.
- **Whether P0's actual committed rewrite of the generator preserves the four constants
  named in section 4 under the same names.** This was explicitly out of scope (the task
  forbade reading the generator from the working tree) and is stated as a
  re-derivation requirement, not answered here.
- **Semantic correctness of the target namespace/path choices themselves** (i.e.
  whether `RuleCompilerPassTest` *should* live under `Infrastructure/DependencyInjection/`
  rather than somewhere else). That is the map's and stage 05's business; this
  derivation only checks that the map's `target` column and the PSR-4 implication of
  that path agree, not whether the map is right.
- **Comment prose that mentions a class by informal description rather than by name**
  (e.g. "the container factory's test" without ever spelling `ContainerFactoryTest`) —
  structurally invisible to any string sweep, FQCN or short-name.
