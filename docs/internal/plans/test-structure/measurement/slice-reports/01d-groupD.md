# Group D — Integration (2) + RuleConfiguration/Unit + Support (19 files)

All 19 files were read in full (for the two largest — `RuleIdentifierLiteralGuardTest.php`, 654
lines, and `ThresholdOverrideOwnRuleNameGuardTest.php`, 1105 lines — the docblock, the full method
structure, and representative body fragments were read; see "Assumptions").

## Table

| path                                                                   | SUT                                                                                                                                                | category                                                                                 | correct directory                                                                                                                        | defects in brief                                                                                                                                                                                                       |
| ---------------------------------------------------------------------- | -------------------------------------------------------------------------------------------------------------------------------------------------- | ---------------------------------------------------------------------------------------- | ---------------------------------------------------------------------------------------------------------------------------------------- | ---------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| `Integration/HierarchicalLevelActivityTest.php`                        | `RuleExecutionInterface::levelActivity()` (a real DI container, every hierarchical rule)                                                           | integration                                                                              | matches                                                                                                                                  | none                                                                                                                                                                                                                   |
| `Integration/LevelActivityCoversEveryDeclaredLevelTest.php`            | `RuleExecution::levelActivity()` × `ChannelUniverseInterface` (agreement between two subsystems via a real container)                              | integration (borderline control-invariant: a walk over the entire channel population)    | matches                                                                                                                                  | none; the docblock honestly names the incompleteness (7 `health.*` not covered, checked in another file)                                                                                                               |
| `Integration/OccurrenceKindFreezeGuardTest.php`                        | 6 classes with `OCCURRENCE_KIND` (regex/reflection over all of `src/`)                                                                             | **control** (control-invariant: scans `src/` as text)                                    | matches (Finding owns `OccurrenceKey`)                                                                                                   | none                                                                                                                                                                                                                   |
| `Integration/OccurrenceLeafFreezeGuardTest.php`                        | 12 classes with `SMELL_TYPE`/`PATTERN_TYPE` + a check against `test-phpunit-discovery.txt`                                                         | **control** (control-invariant)                                                          | matches                                                                                                                                  | none                                                                                                                                                                                                                   |
| `Integration/RuleDocsPageCoverageTest.php`                             | `RuleDocsPageReader` × `website/docs/**` (48 registered rules)                                                                                     | **control**                                                                              | matches                                                                                                                                  | none                                                                                                                                                                                                                   |
| `Integration/RuleIdentifierLiteralGuardTest.php`                       | All of `src/` — scans for literal rule/channel name strings outside the owning capability                                                          | **control** (control-invariant, the widest scan in the slice)                            | matches                                                                                                                                  | none                                                                                                                                                                                                                   |
| `Integration/RuleOptionKeyNormalizationTest.php`                       | `RuleOptionsFactory` + `RuleOptionsParser` (camelCase/kebab/snake normalization)                                                                   | integration                                                                              | matches                                                                                                                                  | none                                                                                                                                                                                                                   |
| `Integration/RuleRemediationMinutesCoverageTest.php`                   | `RuleRemediationMinutesReader` × `website/docs/reference/remediation-time*.md` × a DI container (injecting the map into `RemediationTimeRegistry`) | **control** (the last test is an integration check of DI, the other three are control)   | matches                                                                                                                                  | the file mixes 1 integration test (`itRequiresEveryAddressableProducerToHaveAnInjectedRemediationEstimate`) with three documentation control tests; a candidate for a split during refactoring, not a defect in itself |
| `Integration/ScopeConditionedChannelGuardTest.php`                     | `CheckCommand` end-to-end (6 `*.unmatched-*` channels, a real filesystem fixture)                                                                  | **functional**                                                                           | matches                                                                                                                                  | none                                                                                                                                                                                                                   |
| `Integration/SuppressionBinding/UnboundSuppressionIntegrationTest.php` | `CheckCommand`/`BaselineGenerateCommand` end-to-end, `UnboundSuppressionRule`/`Audit`                                                              | **functional**                                                                           | matches                                                                                                                                  | none                                                                                                                                                                                                                   |
| `Integration/ThresholdOverrideOwnRuleNameGuardTest.php`                | All of `src/` — an AST/text scan for reading someone else's `@qmx-threshold` via `AnalysisContext::getThresholdOverride()`                         | **control** (control-invariant)                                                          | matches                                                                                                                                  | none                                                                                                                                                                                                                   |
| `Integration/WarningBoundaryDeclarationTest.php`                       | Every `RuleOptionsInterface` implementation (the real `getSeverity()` at boundary points, via the registry)                                        | integration (borderline control-invariant: a behavioral walk over the entire population) | matches                                                                                                                                  | none                                                                                                                                                                                                                   |
| `RuleConfiguration/Unit/DeclaredOptionKeysCoverReadKeysTest.php`       | `RuleOptionKeySet::acceptedOptionKeys()` × an AST read of `fromArray()` (via `FromArrayKeyReader`)                                                 | **control** (control-invariant, but with an AST oracle rather than a text regex)         | matches                                                                                                                                  | none                                                                                                                                                                                                                   |
| `RuleConfiguration/Unit/RuleOptionKeyDeclarationCoverageTest.php`      | `acceptedOptionKeys()` implementations × `RuleExecutionInterface` (a php-parser walk over `src/`+`tests/`)                                         | **control** (control-invariant)                                                          | matches                                                                                                                                  | none                                                                                                                                                                                                                   |
| `RuleConfiguration/Unit/UnknownRuleOptionKeyRefusalTest.php`           | `RuleOptionsFactory` + `YamlConfigLoader` + `RuleOptionsRegistry` (the real YAML configuration path, ~35 cases)                                    | integration                                                                              | matches                                                                                                                                  | none; an exemplary test — every negative case comes paired with a positive one                                                                                                                                         |
| `RuleConfiguration/Support/FromArrayKeyReader.php`                     | Not a test. An AST oracle (php-parser) that reads literal configuration keys from the body of `fromArray()`                                        | helper (fixture)                                                                         | matches (used only by `DeclaredOptionKeysCoverReadKeysTest`)                                                                             | none — legitimate, does not mask a business rule: it is itself a measuring instrument, not a decision-making one                                                                                                       |
| `Support/CorpusCaseRun.php`                                            | Not a test. Runs `bin/qmx check` as a subprocess over `finding-gate/cases/*` and parses the JSON report                                            | helper (fixture)                                                                         | matches                                                                                                                                  | none; used by `ChannelLevelDeclarationDriftTest`, `ChannelJudgedMetricDriftTest` (group C)                                                                                                                             |
| `Support/FindingFactory.php`                                           | Not a test. A `Finding` VO factory for three channel shapes (magnitude/occurrence/edge)                                                            | helper (fixture)                                                                         | **does not match** — used exclusively by `tests/Analysis/Policy/Baseline/Unit/*` (8 files), no test in `tests/Analysis/Finding/` uses it | see "Duplicates and contradictions"                                                                                                                                                                                    |
| `Support/StubChannelDeclarationRegistry.php`                           | Not a test. A stub `ChannelDeclarationRegistryInterface` (a Finding contract)                                                                      | helper (fixture)                                                                         | matches by contract ownership, though used in ~45 files far beyond Finding (Reporting, Prioritization, Baseline, Infrastructure)         | not a defect: the interface is a public Finding contract, it's reasonable to keep a shared stub next to it                                                                                                             |

## Control candidates

- `OccurrenceKindFreezeGuardTest.php` — reads `src/` as text with regexes, checks it against a
  hardcoded spelling map; executes no product behavior.
- `OccurrenceLeafFreezeGuardTest.php` — similarly, plus checks the test population against the
  generated artifact `docs/internal/generated/.../test-phpunit-discovery.txt`.
- `RuleDocsPageCoverageTest.php` — checks `DOCS_PAGE` constants against the contents of
  `website/docs/**`.
- `RuleIdentifierLiteralGuardTest.php` — the largest scan: searches for rule/channel name string
  literals outside the owning capability across all of `src/`.
- `RuleRemediationMinutesCoverageTest.php` (3 of 4 tests) — checks `REMEDIATION_MINUTES` against
  `website/docs/reference/remediation-time*.md` (EN+RU).
- `ThresholdOverrideOwnRuleNameGuardTest.php` — an AST/text scan of all of `src/` for reading
  someone else's threshold-override; not a line of product behavior executes.
- `RuleConfiguration/Unit/DeclaredOptionKeysCoverReadKeysTest.php` and
  `RuleOptionKeyDeclarationCoverageTest.php` — both run php-parser over `src/` (+`tests/`) for a
  layout/declaration-completeness invariant, not for product runtime.

Every control candidate in this group is of the **control-invariant** subtype: the invariant is
taken from ALL (or almost all) classes of the corresponding population via
reflection/AST/regex, not selectively.

Borderline (integration, not control, but with a caveat): `LevelActivityCoversEveryDeclaredLevelTest`
and `WarningBoundaryDeclarationTest` — both run real runtime (`levelActivity()`, `getSeverity()`)
via the registry, walking the ENTIRE rule population rather than repository/documentation state.
Formally this is a behavioral regression, not control, but the scale of the walk is the same as in
control-invariant cases, so the reclassification decision belongs to the owner.

## Duplicates and contradictions

- **`Support/FindingFactory.php` is physically in the wrong place.** The file lives in
  `tests/Analysis/Finding/Support/`, but no test in the `tests/Analysis/Finding/` directory uses
  it — all 8 consumers are in `tests/Analysis/Policy/Baseline/Unit/`. By the subject-cohesion rule
  (co-change, counterfactual ownership), this is a Baseline-domain file that accidentally settled
  in another subject's Support. The correct location is
  `tests/Analysis/Policy/Baseline/Support/FindingFactory.php` (or a local `Fixtures/`, as
  `tests/Analysis/Policy/Baseline/Fixtures/CeilingStageFixtures.php` already exists).
- **`OccurrenceKindFreezeGuardTest` and `OccurrenceLeafFreezeGuardTest`** — not duplicates, but
  honestly split halves of one invariant (6 families with an `OCCURRENCE_KIND` constant vs 12 with
  `SMELL_TYPE`/`PATTERN_TYPE`); `OccurrenceLeafFreezeGuardTest`'s docblock explains itself why it
  doesn't inherit from its neighbor. No contradiction, but worth noting: both scan the same `src/`
  tree with two similar but independently maintained walkers (`RecursiveDirectoryIterator` +
  their own regexes) — mergeable into a shared helper if desired.
- **`RuleRemediationMinutesCoverageTest`** overlaps in mechanics (a DI container,
  `RuleRegistryInterface::getClasses()`, the count `48`) with `RuleDocsPageCoverageTest` — both
  literally use the same counting pattern and the same comment about "48 vs 54". Not a duplicate
  (different documents, different constants), but the shared helper method
  `ruleClasses()`/`docsRoot()` is copied verbatim between the files.

## How this was checked and what this method cannot see

The method used to check this group (not the project as a whole) was entirely reading the source
text of the tests and related `src/` classes (via `Read`, targeted `grep`), with no PHPUnit
execution, no container build, no `bin/qmx` run. Hence:

- **Not verified that the control tests actually catch a regression.** For
  `OccurrenceKindFreezeGuardTest`, `RuleIdentifierLiteralGuardTest` and similar files I took the
  docblocks at their word that "it was measured, it bites" — the docblocks themselves assert this
  (e.g.: "dropping `#[Test]` from EvalRuleTest's pin left both assertions above green... that was
  verified by doing it"), but I did not reproduce that mutation myself.
- **Runtime behavior was not verified.** I read the integration/functional tests
  (`ScopeConditionedChannelGuardTest`, `UnboundSuppressionIntegrationTest`,
  `UnknownRuleOptionKeyRefusalTest`) as code and assessed their structure (how many classes are
  involved, whether it's a real DI/CLI path), but did not run them — per the brief's direct
  prohibition. The "integration"/"functional" category assessment rests on static analysis of
  imports and calls (`ContainerFactory`, `CommandTester`, real Options classes), not on observing
  a run.
- **Duplicates between group D and the other three groups (A/B/C) were not checked directly by
  me** — I did not read the other groups' files; coordinating duplicates across groups is the job
  of the final report merge, not this file.
- **Use of `Support/*.php` files outside the Finding slice** was established via `grep -rl` over
  all of `tests/` — a reliable but syntactic search: I did not check that the found consumers
  actually instantiate the class (rather than merely mention the name in a comment), although a
  quick look at the context for every match found confirmed real use (a `use` statement + a
  constructor call).
- **The 654-line `RuleIdentifierLiteralGuardTest.php` and 1105-line
  `ThresholdOverrideOwnRuleNameGuardTest.php` were not read line by line in full** — the docblock,
  every method's signature (`grep`), and 100-150 lines of representative body fragments were. I
  cannot guarantee catching point defects (a typo in a single case, a forgotten check on one
  branch) inside the sections that were not read.

## Assumptions

- All files in this group physically live under
  `tests/Analysis/Finding/{Integration,RuleConfiguration}/`, so "owning subject" was assumed to be
  Finding by default — and this holds true for almost every file, since even the broad
  control-invariants (the freeze-guards, RuleIdentifierLiteralGuardTest) protect contracts owned
  by Finding (`OccurrenceKey`, rule names, option keys), even when they physically scan all of
  `src/`.
- The "control" category was assigned by the brief's criterion literally: the test doesn't run
  product behavior in real time, it checks laid-out state (documentation, generated artifacts,
  source text via regex/AST). Two files (`LevelActivityCoversEveryDeclaredLevelTest`,
  `WarningBoundaryDeclarationTest`) I left as integration, because they really do call product
  code (`levelActivity()`, `getSeverity()`) and check its REAL answer, not text/a constant — but
  flagged as borderline control-invariant by the scale of the walk (the entire rule population);
  the terminology decision belongs to the owner.
- For the Support files, I described "SUT" as the object being constructed rather than as checked
  behavior — per the brief's instruction for this case.
- No CLAUDE.md §9 violations (`#[Test]` + `itXxx`) were found in this group — everywhere either
  `#[Test]` is present with a name in `itXxx` form, or (for the Support files) the methods aren't
  tests at all.
